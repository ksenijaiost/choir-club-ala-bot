<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AttendanceCallbackHandler;
use App\Handlers\AttendanceDeps;
use App\Handlers\AttendanceMemberHandler;
use App\Jobs\CronJobRunner;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\CheckInPromptRepository;
use App\Repositories\CheckInSessionRepository;
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

final class CheckinCloseTest extends TestCase
{
    private const MEMBER_TG = 55_001;

    public function testCheckinCloseRemovesPromptKeyboard(): void
    {
        putenv('FAKE_NOW=2026-03-11T19:35:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);

        $rehearsalId = (int) (new RehearsalRepository($pdo))->listUpcomingScheduled('2026-03-10 12:00:00', 1)[0]['id'];

        $outbound = new RecordingTelegramOutbound();
        $runner = new CronJobRunner();

        $open = $runner->runAttendanceJobs($config, $pdo, $texts, $outbound);
        self::assertSame(1, $open['checkin_open']['sent']);
        self::assertCount(1, $outbound->messages);
        self::assertTrue($outbound->messages[0]['has_keyboard']);

        $prompts = new CheckInPromptRepository($pdo);
        self::assertCount(1, $prompts->listForRehearsal($rehearsalId));

        putenv('FAKE_NOW=2026-03-11T21:35:00+05:00');
        $config = $this->testConfig();
        $close = $runner->runAttendanceJobs($config, $pdo, $texts, $outbound);
        self::assertSame(1, $close['checkin_close']['closed']);
        self::assertCount(1, $outbound->checkinCloses);
        self::assertStringContainsString(
            $texts->get('checkin.closed_suffix'),
            $outbound->checkinCloses[0]['text'],
        );
        self::assertSame([], $prompts->listForRehearsal($rehearsalId));

        putenv('FAKE_NOW');
    }

    public function testCheckinCallbackAfterCloseDoesNotCreateMark(): void
    {
        putenv('FAKE_NOW=2026-03-11T21:40:00+05:00');

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $memberId = (int) $users->findByTelegramId(self::MEMBER_TG)['id'];

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);
        $rehearsalId = (int) (new RehearsalRepository($pdo))->listScheduledEnded('2026-03-11 21:40:00')[0]['id'];

        $sessions = new CheckInSessionRepository($pdo);
        $sessions->ensureOpen($rehearsalId);
        $sessions->close($rehearsalId);

        $deps = $this->attendanceDeps($config, $pdo, $texts, $users);
        $bot = \SergiX44\Nutgram\Nutgram::fake();

        AttendanceCallbackHandler::handle($bot, $deps, 'att:ci:' . $rehearsalId . ':y', self::MEMBER_TG);

        self::assertNull($deps->marks->find($rehearsalId, $memberId));

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
