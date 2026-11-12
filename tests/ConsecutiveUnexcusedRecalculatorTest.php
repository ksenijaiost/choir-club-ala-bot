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
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\UserRepository;
use App\Services\ConsecutiveUnexcusedRecalculator;
use PHPUnit\Framework\TestCase;

final class ConsecutiveUnexcusedRecalculatorTest extends TestCase
{
    public function testResetsStreakOnWarnedAndAttended(): void
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uid = $users->create(5001, 'Аня', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-01-01 19:00:00', '2026-01-01 21:00:00', 'x', 'scheduled']);
        $r1 = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-01-08 19:00:00', '2026-01-08 21:00:00', 'x', 'scheduled']);
        $r2 = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-01-15 19:00:00', '2026-01-15 21:00:00', 'x', 'scheduled']);
        $r3 = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-01-22 19:00:00', '2026-01-22 21:00:00', 'x', 'scheduled']);
        $r4 = (int) $pdo->lastInsertId();

        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($r1, $uid, AttendanceMark::UNEXCUSED, AttendanceSource::SYSTEM, null);
        $marks->upsert($r2, $uid, AttendanceMark::UNEXCUSED, AttendanceSource::SYSTEM, null);
        $marks->upsert($r3, $uid, AttendanceMark::WARNED, AttendanceSource::DAY_BEFORE, null);
        $marks->upsert($r4, $uid, AttendanceMark::UNEXCUSED, AttendanceSource::SYSTEM, null);

        $calc = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $streak = $calc->recalculateForUser($uid);

        self::assertSame(1, $streak);
        $user = $users->findById($uid);
        self::assertSame(1, (int) ($user['consecutive_unexcused'] ?? 0));
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
            fakeNow: null,
        );
    }
}
