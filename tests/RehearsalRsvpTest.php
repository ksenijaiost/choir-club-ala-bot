<?php

declare(strict_types=1);

namespace App\Tests;

use App\Clock;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\RehearsalRsvpAnswer;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AttendanceCallbackHandler;
use App\Handlers\AttendanceMemberHandler;
use App\Handlers\HandlerRegistry;
use App\Jobs\DayBeforeReminderJob;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRsvpRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceMessageFactory;
use App\Tests\Support\AttendanceDepsFactory;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class RehearsalRsvpTest extends TestCase
{
    private const CONDUCTOR_TG = 88_001;

    private const MEMBER_TG = 88_002;

    private const EARLY_TG = 88_003;

    public function testRsvpRepositoryUpsertAndList(): void
    {
        $pdo = $this->pdo();
        $repo = new RehearsalRsvpRepository($pdo);
        $users = new UserRepository($pdo);
        $uid = $users->create(self::MEMBER_TG, 'Аня', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $rehearsalId = $this->insertRehearsal($pdo);

        $repo->upsert($rehearsalId, $uid, RehearsalRsvpAnswer::YES);
        self::assertSame(RehearsalRsvpAnswer::YES, $repo->find($rehearsalId, $uid)['answer']);

        $repo->upsert($rehearsalId, $uid, RehearsalRsvpAnswer::NO);
        self::assertSame(RehearsalRsvpAnswer::NO, $repo->find($rehearsalId, $uid)['answer']);
        self::assertCount(1, $repo->listForRehearsal($rehearsalId));
    }

    public function testDayBeforeJobSkipsEarlyAbsence(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $earlyId = $users->create(self::EARLY_TG, 'Рано', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Обычный', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);

        $rehearsalId = $this->insertRehearsal($pdo, '2026-03-11 19:30:00');
        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($rehearsalId, $earlyId, AttendanceMark::WARNED, AttendanceSource::ABSENCE_BUTTON, 'болею');

        $outbound = new RecordingTelegramOutbound();
        $job = new DayBeforeReminderJob(
            new \App\Repositories\RehearsalRepository($pdo),
            $users,
            $marks,
            new JobDeliveryRepository($pdo),
            new DayBeforeReminderDeliveryRepository($pdo),
            new AttendanceMessageFactory($texts),
            $outbound,
        );

        $result = $job->runIfDue($config);
        self::assertSame(1, $result['sent']);
        self::assertCount(1, $outbound->messages);

        $deliveries = new DayBeforeReminderDeliveryRepository($pdo);
        self::assertNull($deliveries->find($rehearsalId, $earlyId));

        putenv('FAKE_NOW');
    }

    public function testStatusListsPendingAndEarlyAbsent(): void
    {
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $memberId = $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $earlyId = $users->create(self::EARLY_TG, 'Заранее', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $rehearsalId = $this->insertRehearsal($pdo);

        $rsvp = new RehearsalRsvpRepository($pdo);
        $rsvp->upsert($rehearsalId, $memberId, RehearsalRsvpAnswer::YES);

        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($rehearsalId, $earlyId, AttendanceMark::WARNED, AttendanceSource::ABSENCE_BUTTON, 'поездка');

        $deliveries = new DayBeforeReminderDeliveryRepository($pdo);
        $deliveries->recordSent($rehearsalId, $memberId);
        $deliveries->recordSent($rehearsalId, $earlyId);
        $deliveries->recordFailed($rehearsalId, $users->create(88010, 'Фейл', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE), 'blocked');

        $service = new \App\Services\RehearsalRsvpStatusService($rsvp, $deliveries, $marks);
        $lists = $service->buildLists($rehearsalId, $users->listAttendanceParticipants());

        self::assertTrue($lists['reminder_started']);
        self::assertCount(1, $lists['yes']);
        self::assertCount(1, $lists['early_absent']);
        self::assertCount(1, $lists['undelivered']);
    }

    public function testNudgeRateLimit(): void
    {
        putenv('FAKE_NOW=2026-03-10T15:00:00+05:00');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $uid = $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $rehearsalId = $this->insertRehearsal($pdo);

        $deps = AttendanceDepsFactory::make($config, $pdo, $texts, $users);
        $deliveries = new DayBeforeReminderDeliveryRepository($pdo);
        $deliveries->recordSent($rehearsalId, $uid);

        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $deps->rsvpNudge->nudgePending($rehearsalId, $now, $tz);
        $second = $deps->rsvpNudge->nudgePending($rehearsalId, $now, $tz);

        self::assertSame('rate_limit', $second['reason']);
        putenv('FAKE_NOW');
    }

    public function testDayBeforeCallbackPersistsRsvpAndAllowsYesAfterNo(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $rehearsalId = $this->insertRehearsal($pdo, '2026-03-11 19:30:00');

        $deps = AttendanceDepsFactory::make($config, $pdo, $texts, $users);
        $bot = Nutgram::fake();
        $member = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $memberId = (int) $member['id'];

        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':n', self::MEMBER_TG);
        self::assertSame(RehearsalRsvpAnswer::NO, $deps->rsvp->find($rehearsalId, $memberId)['answer']);

        AttendanceCallbackHandler::handle($bot, $deps, 'att:sk', self::MEMBER_TG);
        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':y', self::MEMBER_TG);
        self::assertSame(RehearsalRsvpAnswer::YES, $deps->rsvp->find($rehearsalId, $memberId)['answer']);

        putenv('FAKE_NOW');
    }

    public function testRsvpRoutingAndPermissions(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $this->insertRehearsal($pdo);

        $conductorBot = $this->fakePrivateBot(self::CONDUCTOR_TG);
        HandlerRegistry::register($conductorBot, $texts, $this->testConfig(), $pdo);
        $conductorBot->hearCallbackQueryData('rsv:home')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('sendMessage');

        $memberBot = $this->fakePrivateBot(self::MEMBER_TG);
        HandlerRegistry::register($memberBot, $texts, $this->testConfig(), $pdo);
        $alert = $texts->get('admin.only_conductor_or_owner');
        $memberBot->hearCallbackQueryData('rsv:home')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery');
        $found = false;
        foreach ($memberBot->getRequestHistory() as $entry) {
            if (str_contains((string) $entry['request']->getBody(), $alert)) {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function testFinishReasonWithoutReasonUpsertsNo(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $rehearsalId = $this->insertRehearsal($pdo, '2026-03-11 19:30:00');
        $deps = AttendanceDepsFactory::make($config, $pdo, $texts, $users);
        $bot = Nutgram::fake();

        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':n', self::MEMBER_TG);
        AttendanceMemberHandler::finishReasonWithoutReason(
            $bot,
            $deps,
            self::MEMBER_TG,
            AttendanceSource::DAY_BEFORE,
            $rehearsalId,
        );

        $member = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        self::assertSame(RehearsalRsvpAnswer::NO, $deps->rsvp->find($rehearsalId, (int) $member['id'])['answer']);

        putenv('FAKE_NOW');
    }

    private function pdo(): \PDO
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    private function insertRehearsal(\PDO $pdo, string $starts = '2026-04-01 19:30:00'): int
    {
        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute([$starts, date('Y-m-d H:i:s', strtotime($starts) + 7200), 'зал', 'scheduled']);

        return (int) $pdo->lastInsertId();
    }

    private function fakePrivateBot(int $telegramId): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $telegramId;
        $user->is_bot = false;
        $user->first_name = 'Тест';
        $bot->setCommonUser($user);

        $chat = new Chat();
        $chat->id = $telegramId;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        return $bot;
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
            adminChatId: '',
            dbConnection: 'sqlite',
            dbHost: '',
            dbPort: '',
            dbDatabase: ':memory:',
            dbUsername: '',
            dbPassword: '',
            timezone: 'Asia/Almaty',
            webhookSecret: 'secret',
            adminPagesKey: 'admin-key',
            webhookUrl: '',
            fakeNow: getenv('FAKE_NOW') !== false ? (string) getenv('FAKE_NOW') : null,
        );
    }
}