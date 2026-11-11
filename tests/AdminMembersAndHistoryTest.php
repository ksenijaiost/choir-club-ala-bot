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
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceHistoryPresenter;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class AdminMembersAndHistoryTest extends TestCase
{
    public function testOwnerIncludedInSingingMembersListForAllFilter(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(1, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->upsertOwner(99, 'Владелица');
        $owner = $users->findByTelegramId(99);
        self::assertNotNull($owner);
        $users->setVoicePart((int) $owner['id'], 'soprano_1');

        $list = $users->listSingingMembersForAdmin(null);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $list);

        self::assertContains((int) $owner['id'], $ids);
    }

    public function testAdminHistoryFormatsMemberMarks(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $userId = $users->create(2, 'Поющий', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $rehearsals = new RehearsalRepository($pdo);
        $r1 = $rehearsals->create(null, '2026-03-01 19:00:00', '2026-03-01 21:00:00', 'зал');
        $r2 = $rehearsals->create(null, '2026-03-08 19:00:00', '2026-03-08 21:00:00', 'зал');

        $marks = new AttendanceMarkRepository($pdo);
        $marks->upsert($r1, $userId, AttendanceMark::ATTENDED, AttendanceSource::MANUAL, null);
        $marks->upsert($r2, $userId, AttendanceMark::WARNED, AttendanceSource::DAY_BEFORE, 'болезнь');

        $presenter = new AttendanceHistoryPresenter($texts);
        $tz = new \DateTimeZone('Asia/Almaty');
        $rows = $marks->listForUserRecent($userId, 10);

        self::assertCount(2, $rows);
        $lines = array_map(
            static fn (array $row): string => $presenter->formatAdminMemberLine($row, $tz),
            $rows,
        );

        self::assertStringContainsString('болезнь', $lines[0]);
        self::assertStringContainsString('был', mb_strtolower($lines[1]));
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '99',
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
