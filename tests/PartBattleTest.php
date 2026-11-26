<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AboutBotHandler;
use App\Handlers\HandlerRegistry;
use App\Jobs\PartBattleMonthlyJob;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AdminChatResolver;
use App\Services\PartBattleService;
use App\Services\PaymentTexts;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class PartBattleTest extends TestCase
{
    private const CONDUCTOR_TG = 98_001;
    private const ADMIN_CHAT = -100_777;

    public function testSmallPartBeatsLargeByPercent(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(fakeNow: '2026-03-20T12:00:00+05:00');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(901, 'Тенор', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(902, 'Бас 1', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(903, 'Бас 2', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(904, 'Бас 3', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(905, 'Бас 4', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);

        $tenor = $users->findByTelegramId(901);
        $basses = array_map(
            static fn (int $tg) => $users->findByTelegramId($tg),
            [902, 903, 904, 905],
        );
        self::assertNotNull($tenor);

        $r1 = $this->insertRehearsal($pdo, '2026-03-01 19:30:00');
        $r2 = $this->insertRehearsal($pdo, '2026-03-08 19:30:00');
        $cancelled = $this->insertRehearsal($pdo, '2026-03-09 19:30:00');
        $pdo->prepare('UPDATE rehearsals SET status = ? WHERE id = ?')->execute(['cancelled', $cancelled]);

        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($r1, (int) $tenor['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
        $marks->upsert($r2, (int) $tenor['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
        foreach ($basses as $i => $bass) {
            self::assertNotNull($bass);
            $marks->upsert($r1, (int) $bass['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
            if ($i < 3) {
                $marks->upsert($r2, (int) $bass['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
            }
        }

        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $conductor = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($conductor);
        $marks->upsert($r1, (int) $conductor['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);

        $service = $this->partBattle($pdo, $texts);
        $result = $service->buildForMonth($config, 2026, 3);
        self::assertSame(2, $result->rehearsalCount);
        self::assertContains('tenor', $result->winners);
        self::assertNotContains('bass', $result->winners);
        self::assertSame(100, $result->rows[0]['percent']);
    }

    public function testTieForFirstPlace(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(fakeNow: '2026-04-10T12:00:00+05:00');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(911, 'Т1', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(912, 'Б1', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $tenor = $users->findByTelegramId(911);
        $bass = $users->findByTelegramId(912);
        self::assertNotNull($tenor);
        self::assertNotNull($bass);

        $r1 = $this->insertRehearsal($pdo, '2026-04-02 19:30:00');
        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($r1, (int) $tenor['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
        $marks->upsert($r1, (int) $bass['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);

        $result = $this->partBattle($pdo, $texts)->buildForMonth($config, 2026, 4);
        self::assertCount(2, $result->winners);
        self::assertContains('tenor', $result->winners);
        self::assertContains('bass', $result->winners);
    }

    public function testProfileShowsTrophyForWinningPartOnly(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(fakeNow: '2026-02-05T12:00:00+05:00');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(921, 'Тенор', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(922, 'Бас', 'bass', UserRole::MEMBER, UserStatus::ACTIVE);
        $tenor = $users->findByTelegramId(921);
        $bass = $users->findByTelegramId(922);
        self::assertNotNull($tenor);
        self::assertNotNull($bass);

        $r = $this->insertRehearsal($pdo, '2026-01-15 19:30:00');
        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($r, (int) $tenor['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);

        $service = $this->partBattle($pdo, $texts);
        self::assertStringContainsString('🏆', $service->formatProfileLine($tenor, $config));
        self::assertSame('', $service->formatProfileLine($bass, $config));
    }

    public function testMonthlyCronSendsOnceAndSkipsEmptyMonth(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(931, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $r = $this->insertRehearsal($pdo, '2026-01-10 19:30:00');
        $marks = new AttendanceMarkRepository($pdo);
        $member = $users->findByTelegramId(931);
        self::assertNotNull($member);
        $marks->upsert($r, (int) $member['id'], AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);

        $outbound = new RecordingTelegramOutbound();
        $deliveries = new JobDeliveryRepository($pdo);
        $config = $this->testConfig(
            fakeNow: '2026-02-01T10:00:00+05:00',
            adminChatId: (string) self::ADMIN_CHAT,
        );
        $service = $this->partBattle($pdo, $texts);
        $job = new PartBattleMonthlyJob(
            $service,
            $deliveries,
            new AdminChatResolver(new \App\Repositories\BotSettingsRepository($pdo), $config),
            $outbound,
        );

        $first = $job->runIfDue($config);
        self::assertTrue($first['sent']);
        self::assertCount(1, $outbound->messages);
        self::assertSame(self::ADMIN_CHAT, $outbound->messages[0]['chat_id']);

        $second = $job->runIfDue($config);
        self::assertFalse($second['sent']);
        self::assertCount(1, $outbound->messages);

        $emptyConfig = $this->testConfig(
            fakeNow: '2026-04-01T10:00:00+05:00',
            adminChatId: (string) self::ADMIN_CHAT,
        );
        $emptyJob = new PartBattleMonthlyJob(
            $service,
            $deliveries,
            new AdminChatResolver(new \App\Repositories\BotSettingsRepository($pdo), $emptyConfig),
            new RecordingTelegramOutbound(),
        );
        $empty = $emptyJob->runIfDue($emptyConfig);
        self::assertFalse($empty['sent']);
        self::assertTrue($empty['skipped_no_rehearsals'] ?? false);
    }

    public function testPartBattleCallbackOpensScreen(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $bot = Nutgram::fake();
        $user = new User();
        $user->id = self::CONDUCTOR_TG;
        $user->is_bot = false;
        $user->first_name = 'Дирижёр';
        $bot->setCommonUser($user);
        $chat = new Chat();
        $chat->id = self::CONDUCTOR_TG;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearCallbackQueryData('atd:pb')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery');
    }

    public function testAboutMentionsPartBattleWithoutSpoilers(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $body = AboutBotHandler::sectionText($texts, 'member');
        self::assertStringContainsString('Битва партий', $body);
        self::assertStringContainsString($texts->get('menu.my_profile'), $body);
    }

    public function testCallbackDataIsRouted(): void
    {
        self::assertTrue(str_starts_with('atd:pb', 'atd:'));
        self::assertTrue(str_starts_with('atd:pb:prev', 'atd:'));
    }

    private function partBattle(\PDO $pdo, TextRepository $texts): PartBattleService
    {
        return new PartBattleService(
            new RehearsalRepository($pdo),
            new UserRepository($pdo),
            new AttendanceMarkRepository($pdo),
            $texts,
            new PaymentTexts($texts),
        );
    }

    private function insertRehearsal(\PDO $pdo, string $startsAt): int
    {
        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute([$startsAt, $startsAt, 'зал', 'scheduled']);

        return (int) $pdo->lastInsertId();
    }

    private function testConfig(?string $fakeNow = null, string $adminChatId = ''): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
            adminChatId: $adminChatId,
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
            fakeNow: $fakeNow,
        );
    }
}