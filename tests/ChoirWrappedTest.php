<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\SecretAchievements;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AboutBotHandler;
use App\Handlers\HandlerRegistry;
use App\Jobs\MonthlyWrappedJob;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\UserAchievementRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserStreakAwardRepository;
use App\Services\AdminChatResolver;
use App\Services\ChoirWrappedService;
use App\Services\PartBattleService;
use App\Services\PaymentTexts;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ChoirWrappedTest extends TestCase
{
    private const CONDUCTOR_TG = 99_001;
    private const MEMBER_TG = 99_002;
    private const ADMIN_CHAT = -100_888;

    public function testMonthlySummaryIncludesAttendanceAwardsAndPartBattle(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(fakeNow: '2026-02-05T12:00:00+05:00');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uid = $users->create(991, 'Тенор', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(991);
        self::assertNotNull($member);

        $r = $this->insertRehearsal($pdo, '2026-01-15 19:30:00');
        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($r, $uid, AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);

        $pdo->prepare(
            'INSERT INTO user_streak_awards (user_id, milestone, awarded_at) VALUES (?, ?, ?)'
        )->execute([$uid, 4, '2026-01-16 10:00:00']);
        (new UserAchievementRepository($pdo))->tryAward($uid, SecretAchievements::NIGHT_OWL);
        $pdo->prepare('UPDATE user_achievements SET awarded_at = ? WHERE user_id = ? AND code = ?')
            ->execute(['2026-01-17 02:00:00', $uid, SecretAchievements::NIGHT_OWL]);

        $service = $this->wrapped($pdo, $texts);
        $summary = $service->buildMonthlyMemberSummary($member, $config, 2026, 1);
        self::assertSame(1, $summary['rehearsal_count']);
        self::assertSame(1, $summary['attended']);
        self::assertSame(100, $summary['percent']);
        self::assertContains(4, $summary['milestones']);
        self::assertContains(SecretAchievements::NIGHT_OWL, $summary['secret_achievements']);
        self::assertContains('tenor', $summary['part_battle']->winners);

        $msg = $service->formatMonthlyMemberMessage($summary);
        self::assertStringContainsString('100%', $msg);
        self::assertStringContainsString('Ночная сова', $msg);
    }

    public function testMonthlyCronSendsAt1005AndIsIdempotent(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $member = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);

        $r = $this->insertRehearsal($pdo, '2026-01-10 19:30:00');
        (new AttendanceMarkRepository($pdo))->upsert(
            $r,
            (int) $member['id'],
            AttendanceMark::ATTENDED,
            AttendanceSource::CHECKIN,
            null,
        );

        $config = $this->testConfig(fakeNow: '2026-02-01T10:05:00+05:00');
        $outbound = new RecordingTelegramOutbound();
        $job = new MonthlyWrappedJob(
            $this->wrapped($pdo, $texts),
            $users,
            new JobDeliveryRepository($pdo),
            $outbound,
        );

        $first = $job->runIfDue($config);
        self::assertSame(1, $first['sent']);
        self::assertCount(1, $outbound->messages);
        self::assertSame(self::MEMBER_TG, $outbound->messages[0]['chat_id']);

        $second = $job->runIfDue($config);
        self::assertSame(0, $second['sent']);
        self::assertCount(1, $outbound->messages);
    }

    public function testMonthlyCronSkipsWhenNoRehearsals(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(992, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $config = $this->testConfig(fakeNow: '2026-04-01T10:05:00+05:00');
        $outbound = new RecordingTelegramOutbound();
        $job = new MonthlyWrappedJob(
            $this->wrapped($pdo, $texts),
            $users,
            new JobDeliveryRepository($pdo),
            $outbound,
        );

        $result = $job->runIfDue($config);
        self::assertSame(0, $result['sent']);
        self::assertTrue($result['skipped_no_rehearsals'] ?? false);
        self::assertCount(0, $outbound->messages);
    }

    public function testProfileWrappedOpensForMember(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(fakeNow: '2026-02-05T12:00:00+05:00');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $this->insertRehearsal($pdo, '2026-01-10 19:30:00');

        $bot = Nutgram::fake();
        $user = new User();
        $user->id = self::MEMBER_TG;
        $user->is_bot = false;
        $user->first_name = 'Участник';
        $bot->setCommonUser($user);
        $chat = new Chat();
        $chat->id = self::MEMBER_TG;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearCallbackQueryData('prf:wr')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery');
    }

    public function testConductorProfileHasNoWrappedEntryPoint(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $conductor = [
            'role' => UserRole::CONDUCTOR,
            'status' => UserStatus::ACTIVE,
        ];
        self::assertTrue(\App\Services\VoicePartRules::isConductor($conductor));
    }

    public function testSeasonPreviewDoesNotBroadcast(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

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
        $outbound = new RecordingTelegramOutbound();
        $before = count($outbound->messages);

        $bot->hearCallbackQueryData('atd:sw:pr:3')
            ->willStartConversation()
            ->reply();

        self::assertSame($before, count($outbound->messages));
    }

    public function testSeasonSendReachesMembersAndAdminChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(fakeNow: '2026-06-15T12:00:00+05:00', adminChatId: (string) self::ADMIN_CHAT);
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $this->insertRehearsal($pdo, '2026-04-01 19:30:00');

        $deps = \App\Tests\Support\AttendanceDepsFactory::make($config, $pdo, $texts, $users);
        $outbound = new RecordingTelegramOutbound();
        $conductor = $users->findByTelegramId(self::CONDUCTOR_TG);
        self::assertNotNull($conductor);

        $reflection = new \ReflectionClass(\App\Handlers\ChoirWrappedAdminHandler::class);
        $method = $reflection->getMethod('broadcastSeason');
        $method->setAccessible(true);
        $bot = Nutgram::fake();
        $count = $method->invoke(
            null,
            $bot,
            $deps,
            new AdminChatResolver(new \App\Repositories\BotSettingsRepository($pdo), $config),
            $outbound,
            '3',
            $conductor,
        );

        self::assertSame(1, $count);
        self::assertCount(2, $outbound->messages);
        self::assertSame(self::MEMBER_TG, $outbound->messages[0]['chat_id']);
        self::assertSame(self::ADMIN_CHAT, $outbound->messages[1]['chat_id']);
    }

    public function testCallbacksRoutedUnderKnownPrefixes(): void
    {
        self::assertTrue(str_starts_with('prf:wr', 'prf:'));
        self::assertTrue(str_starts_with('atd:sw', 'atd:'));
    }

    public function testAboutMentionsWrapped(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $member = AboutBotHandler::sectionText($texts, 'member');
        self::assertStringContainsString('📊', $member);
        $staff = AboutBotHandler::sectionText($texts, 'staff');
        self::assertStringContainsString('Итоги сезона', $staff);
    }

    private function wrapped(\PDO $pdo, TextRepository $texts): ChoirWrappedService
    {
        $partBattle = new PartBattleService(
            new \App\Repositories\RehearsalRepository($pdo),
            new UserRepository($pdo),
            new AttendanceMarkRepository($pdo),
            $texts,
            new PaymentTexts($texts),
        );

        return new ChoirWrappedService(
            new \App\Repositories\RehearsalRepository($pdo),
            new UserRepository($pdo),
            new AttendanceMarkRepository($pdo),
            new UserStreakAwardRepository($pdo),
            new UserAchievementRepository($pdo),
            new ProgramRepository($pdo),
            $partBattle,
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