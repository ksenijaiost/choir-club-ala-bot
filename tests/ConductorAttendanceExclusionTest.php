<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\PaymentMemberHandler;
use App\Jobs\CheckinOpenJob;
use App\Jobs\CronJobRunner;
use App\Jobs\DayBeforeReminderJob;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInPromptRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceMessageFactory;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class ConductorAttendanceExclusionTest extends TestCase
{
    public function testConductorIsNotActivePayer(): void
    {
        self::assertFalse(PaymentMemberHandler::isActivePayer([
            'role' => UserRole::CONDUCTOR,
            'status' => UserStatus::ACTIVE,
        ]));
    }

    public function testConductorNotInListActivePayers(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->upsertConductor(500, 'Дирижёр');

        $ids = array_map(
            static fn (array $row): int => (int) $row['telegram_id'],
            $users->listActivePayers(),
        );
        self::assertNotContains(500, $ids);
    }

    public function testDayBeforeReminderDoesNotSendToConductor(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(1001, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(2002, 'Дирижёр');

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);

        $outbound = new RecordingTelegramOutbound();
        $runner = new CronJobRunner();
        $result = $runner->runAttendanceJobs($config, $pdo, $texts, $outbound);

        self::assertSame(1, $result['day_before_reminder']['sent']);
        $chatIds = array_column($outbound->messages, 'chat_id');
        self::assertContains(1001, $chatIds);
        self::assertNotContains(2002, $chatIds);

        putenv('FAKE_NOW');
    }

    public function testCheckinOpenDoesNotSendToConductor(): void
    {
        putenv('FAKE_NOW=2026-03-10T19:35:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(1001, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertConductor(2002, 'Дирижёр');

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-10 19:30:00', '2026-03-10 21:30:00', 'зал', 'scheduled']);

        $marks = new AttendanceMarkRepository($pdo);
        $outbound = new RecordingTelegramOutbound();
        $messages = new AttendanceMessageFactory($texts);
        $job = new CheckinOpenJob(
            new RehearsalRepository($pdo),
            $users,
            $marks,
            new CheckInSessionRepository($pdo),
            new CheckInPromptRepository($pdo),
            new JobDeliveryRepository($pdo),
            $messages,
            $texts,
            $outbound,
        );

        $stats = $job->runIfDue($config);
        self::assertSame(1, $stats['sent']);
        $chatIds = array_column($outbound->messages, 'chat_id');
        self::assertNotContains(2002, $chatIds);

        putenv('FAKE_NOW');
    }

    public function testUpsertConductorResetsConsecutiveUnexcused(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $id = $users->create(3003, 'Будущий дир', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->setConsecutiveUnexcused($id, 3);

        $users->upsertConductor(3003, 'Дирижёр');
        $conductor = $users->findByTelegramId(3003);
        self::assertNotNull($conductor);
        self::assertSame(0, (int) ($conductor['consecutive_unexcused'] ?? 0));
    }

    public function testMigrationResetsConductorStreak(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->upsertConductor(4004, 'Дирижёр');
        $user = $users->findByTelegramId(4004);
        self::assertNotNull($user);
        $users->setConsecutiveUnexcused((int) $user['id'], 5);

        $pdo->exec(
            "UPDATE users SET consecutive_unexcused = 0, updated_at = CURRENT_TIMESTAMP WHERE role = 'conductor'"
        );

        $conductor = $users->findByTelegramId(4004);
        self::assertSame(0, (int) ($conductor['consecutive_unexcused'] ?? 0));
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
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