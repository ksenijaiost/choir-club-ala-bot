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
use App\Repositories\UserStreakAwardRepository;
use App\Services\AttendanceStreakBackfill;
use App\Services\AttendanceStreakCalculator;
use App\Tests\Support\AttendanceDepsFactory;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class AttendanceStreakTest extends TestCase
{
    public function testAttendedIncrementsStreakAndMilestone(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(701, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(701);
        self::assertNotNull($member);

        $outbound = new RecordingTelegramOutbound();
        $deps = AttendanceDepsFactory::make($config, $pdo, $texts, $users, $outbound);

        foreach ([1, 2, 3] as $i) {
            $rehearsalId = $this->insertRehearsal($pdo, "2026-04-0{$i} 19:30:00");
            $deps->attendance->recordAttended($member, $rehearsalId);
            $member = $users->findByTelegramId(701);
            self::assertNotNull($member);
        }

        self::assertSame(3, (int) ($member['attendance_streak'] ?? 0));

        $rehearsalId = $this->insertRehearsal($pdo, '2026-04-04 19:30:00');
        $result = $deps->attendance->recordAttended($member, $rehearsalId);
        self::assertStringContainsString('🔥 Серия: 4', (string) $result->appendLine);

        $member = $users->findByTelegramId(701);
        self::assertSame(4, (int) ($member['attendance_streak'] ?? 0));

        $awards = new UserStreakAwardRepository($pdo);
        self::assertTrue($awards->hasAward((int) $member['id'], 4));
        $memberCongrats = array_filter(
            $outbound->messages,
            static fn (array $m): bool => $m['chat_id'] === 701 && str_contains($m['text'], 'Распевка пройдена'),
        );
        self::assertCount(1, $memberCongrats);
    }

    public function testWarnedFreezesStreak(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(702, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(702);
        self::assertNotNull($member);

        $deps = AttendanceDepsFactory::make($this->testConfig(), $pdo, $texts, $users);
        $r1 = $this->insertRehearsal($pdo, '2026-05-01 19:30:00');
        $r2 = $this->insertRehearsal($pdo, '2026-05-03 19:30:00');
        $deps->attendance->recordAttended($member, $r1);
        $member = $users->findByTelegramId(702);
        self::assertNotNull($member);

        $warn = $deps->attendance->recordWarned($member, $r2, AttendanceSource::ABSENCE_BUTTON, 'дела');
        self::assertStringContainsString('❄️', (string) $warn->appendLine);

        $member = $users->findByTelegramId(702);
        self::assertSame(1, (int) ($member['attendance_streak'] ?? 0));
    }

    public function testUnexcusedResetsStreak(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(703, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(703);
        self::assertNotNull($member);

        $deps = AttendanceDepsFactory::make($this->testConfig(), $pdo, $texts, $users);
        $r1 = $this->insertRehearsal($pdo, '2026-06-01 19:30:00');
        $deps->attendance->recordAttended($member, $r1);
        $member = $users->findByTelegramId(703);
        self::assertNotNull($member);
        $users->setAttendanceStreak((int) $member['id'], 2, 2);
        $member = $users->findById((int) $member['id']) ?? $member;

        $r2 = $this->insertRehearsal($pdo, '2026-06-03 19:30:00');
        $result = $deps->attendance->recordUnexcused($member, $r2, AttendanceSource::CHECKIN);
        self::assertStringContainsString('💔', (string) $result->appendLine);

        $member = $users->findByTelegramId(703);
        self::assertSame(0, (int) ($member['attendance_streak'] ?? 0));
        self::assertSame(2, (int) ($member['attendance_streak_best'] ?? 0));
    }

    public function testNoFrozenOrBrokenLinesWhenStreakWasZero(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(704, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(704);
        self::assertNotNull($member);

        $deps = AttendanceDepsFactory::make($this->testConfig(), $pdo, $texts, $users);
        $r = $this->insertRehearsal($pdo, '2026-07-01 19:30:00');

        $warn = $deps->attendance->recordWarned($member, $r, AttendanceSource::ABSENCE_BUTTON, 'дела');
        self::assertNull($warn->appendLine);

        $r2 = $this->insertRehearsal($pdo, '2026-07-03 19:30:00');
        $unexcused = $deps->attendance->recordUnexcused($member, $r2, AttendanceSource::CHECKIN);
        self::assertNull($unexcused->appendLine);
    }

    public function testManualMarkRecalculatesStreak(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(705, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(705);
        self::assertNotNull($member);
        $uid = (int) $member['id'];

        $deps = AttendanceDepsFactory::make($this->testConfig(), $pdo, $texts, $users);
        $r1 = $this->insertRehearsal($pdo, '2026-08-01 19:30:00');
        $r2 = $this->insertRehearsal($pdo, '2026-08-03 19:30:00');
        $deps->attendance->recordAttended($member, $r1);
        $member = $users->findByTelegramId(705);
        self::assertNotNull($member);
        $deps->attendance->recordAttended($member, $r2);
        $member = $users->findByTelegramId(705);
        self::assertSame(2, (int) ($member['attendance_streak'] ?? 0));

        $deps->attendance->recordManual($uid, $r2, AttendanceMark::UNEXCUSED);

        $member = $users->findById($uid);
        self::assertSame(0, (int) ($member['attendance_streak'] ?? 0));
        self::assertSame(2, (int) ($member['attendance_streak_best'] ?? 0));
    }

    public function testMilestoneAwardedOnlyOnce(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $outbound = new RecordingTelegramOutbound();
        $users->create(706, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(706);
        self::assertNotNull($member);

        $deps = AttendanceDepsFactory::make($this->testConfig(), $pdo, $texts, $users, $outbound);
        for ($i = 1; $i <= 4; $i++) {
            $deps->attendance->recordAttended($member, $this->insertRehearsal($pdo, "2026-09-0{$i} 19:30:00"));
            $member = $users->findByTelegramId(706);
            self::assertNotNull($member);
        }

        $countCongrats = count(array_filter(
            $outbound->messages,
            static fn (array $m): bool => $m['chat_id'] === 706 && str_contains($m['text'], 'Распевка пройдена'),
        ));
        self::assertSame(1, $countCongrats);

        $deps->attendance->recordManual((int) $member['id'], $this->insertRehearsal($pdo, '2026-09-10 19:30:00'), AttendanceMark::ATTENDED);
        $countAfter = count(array_filter(
            $outbound->messages,
            static fn (array $m): bool => $m['chat_id'] === 706 && str_contains($m['text'], 'Распевка пройдена'),
        ));
        self::assertSame(1, $countAfter);
    }

    public function testConductorDoesNotGetStreak(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->upsertConductor(707, 'Дирижёр');
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $deps = AttendanceDepsFactory::make($this->testConfig(), $pdo, $texts, $users);
        $conductor = $users->findByTelegramId(707);
        self::assertNotNull($conductor);
        $r = $this->insertRehearsal($pdo, '2026-10-01 19:30:00');

        $result = $deps->attendance->recordAttended($conductor, $r);
        self::assertNull($result->appendLine);
        $conductor = $users->findByTelegramId(707);
        self::assertSame(0, (int) ($conductor['attendance_streak'] ?? 0));
    }

    public function testBackfillSilentNoMessages(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $marks = new AttendanceMarkRepository($pdo);
        $users->create(708, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(708);
        self::assertNotNull($member);
        $uid = (int) $member['id'];

        $r1 = $this->insertRehearsal($pdo, '2026-11-01 19:30:00');
        $r2 = $this->insertRehearsal($pdo, '2026-11-03 19:30:00');
        $marks->upsert($r1, $uid, AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
        $marks->upsert($r2, $uid, AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);

        $outbound = new RecordingTelegramOutbound();
        self::assertSame([], $outbound->messages);

        (new AttendanceStreakBackfill($users, $marks, new UserStreakAwardRepository($pdo)))->runSilent();

        $member = $users->findById($uid);
        self::assertSame(2, (int) ($member['attendance_streak'] ?? 0));
        self::assertSame([], $outbound->messages);
    }

    private function insertRehearsal(\PDO $pdo, string $startsAt): int
    {
        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute([$startsAt, $startsAt, 'зал', 'scheduled']);

        return (int) $pdo->lastInsertId();
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '999',
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