<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Jobs\CronJobRunner;
use App\Repositories\UserRepository;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class AttendanceCronTest extends TestCase
{
    public function testDayBeforeReminderIsIdempotentAtFakeNoon(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(1001, 'Тест', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $tomorrowStart = '2026-03-11 19:30:00';
        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute([$tomorrowStart, '2026-03-11 21:30:00', 'комьюнити-центр', 'scheduled']);

        $outbound = new RecordingTelegramOutbound();
        $runner = new CronJobRunner();

        $first = $runner->runAttendanceJobs($config, $pdo, $texts, $outbound);
        self::assertSame(1, $first['day_before_reminder']['sent']);
        self::assertCount(1, $outbound->messages);

        $second = $runner->runAttendanceJobs($config, $pdo, $texts, $outbound);
        self::assertSame(0, $second['day_before_reminder']['sent']);
        self::assertCount(1, $outbound->messages);

        putenv('FAKE_NOW');
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
