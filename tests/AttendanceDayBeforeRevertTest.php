<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\AttendanceMark;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AttendanceCallbackHandler;
use App\Handlers\AttendanceDeps;
use App\Handlers\AttendanceMemberHandler;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class AttendanceDayBeforeRevertTest extends TestCase
{
  private const MEMBER_TG = 70_001;

    public function testNoThenYesClearsWarnedMarkAndAbsenceFlow(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $memberId = (int) $member['id'];

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);

        $rehearsals = new RehearsalRepository($pdo);
        $upcoming = $rehearsals->listUpcomingScheduled('2026-03-10 12:05:00', 5);
        self::assertCount(1, $upcoming);
        $rehearsalId = (int) $upcoming[0]['id'];

        $deps = $this->attendanceDeps($config, $pdo, $texts, $users);
        $bot = Nutgram::fake();

        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':n', self::MEMBER_TG);

        $mark = $deps->marks->find($rehearsalId, $memberId);
        self::assertNotNull($mark);
        self::assertSame(AttendanceMark::WARNED, $mark['mark']);
        self::assertNotNull($deps->flows->get(self::MEMBER_TG));

        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':y', self::MEMBER_TG);

        self::assertNull($deps->marks->find($rehearsalId, $memberId));
        self::assertNull($deps->flows->get(self::MEMBER_TG));

        putenv('FAKE_NOW');
    }

    public function testNoThenYesNotifiesConductorWhenReasonWasSkipped(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig(conductorTelegramId: '9001');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $memberId = (int) $member['id'];

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);

        $rehearsals = new RehearsalRepository($pdo);
        $rehearsalId = (int) $rehearsals->listUpcomingScheduled('2026-03-10 12:05:00', 1)[0]['id'];

        $outbound = new RecordingTelegramOutbound();
        $deps = $this->attendanceDeps($config, $pdo, $texts, $users, $outbound);
        $bot = Nutgram::fake();

        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':n', self::MEMBER_TG);
        AttendanceMemberHandler::finishReasonSkip(
            $bot,
            $deps,
            self::MEMBER_TG,
            \App\Domain\AttendanceSource::DAY_BEFORE,
            $rehearsalId,
        );

        self::assertSame('—', $deps->marks->find($rehearsalId, $memberId)['reason_text'] ?? null);
        $outbound->messages = [];

        AttendanceCallbackHandler::handle($bot, $deps, 'att:db:' . $rehearsalId . ':y', self::MEMBER_TG);

        self::assertNull($deps->marks->find($rehearsalId, $memberId));
        self::assertCount(1, $outbound->messages);
        self::assertStringContainsString('передумал', strtolower($outbound->messages[0]['text']));

        putenv('FAKE_NOW');
    }

    private function testConfig(string $conductorTelegramId = ''): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: $conductorTelegramId,
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

    private function attendanceDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
        ?RecordingTelegramOutbound $outbound = null,
    ): AttendanceDeps {
        $rehearsals = new RehearsalRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $marks = new AttendanceMarkRepository($pdo);
        $outbound ??= new RecordingTelegramOutbound();
        $recipients = new NotificationRecipientResolver($config, $users);
        $blocks = new MemberBlockService($users, new BlockEventRepository($pdo));
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);
        $service = new AttendanceService($marks, $users, $discipline, $recalculator);

        return new AttendanceDeps(
            $config,
            $users,
            $rehearsals,
            $marks,
            $flows,
            $service,
            new AttendanceMessageFactory($texts),
            new AttendanceConductorNotifier($recipients, $texts, $outbound),
            $recalculator,
            new AttendanceHistoryPresenter($texts),
            $texts,
            new CheckInSessionRepository($pdo),
        );
    }
}
