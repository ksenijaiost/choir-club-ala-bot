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
use App\Handlers\AttendanceCallbackHandler;
use App\Handlers\AttendanceDeps;
use App\Handlers\AttendanceMemberHandler;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BlockEventRepository;
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
use SergiX44\Nutgram\Nutgram;

final class AttendanceAbsenceReasonTest extends TestCase
{
    private const MEMBER_TG = 96_001;

    private TextRepository $texts;

    private int $rehearsalId;

    private AttendanceDeps $deps;

    private int $memberId;

    private \PDO $pdo;

    private UserRepository $users;

    protected function setUp(): void
    {
        putenv('FAKE_NOW=2026-03-10T12:05:00+05:00');

        $this->texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $this->pdo = Connection::make($config);
        $pdo = $this->pdo;
        (new Migrator($pdo, 'sqlite'))->migrate();

        $this->users = new UserRepository($pdo);
        $this->users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $this->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $this->memberId = (int) $member['id'];

        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);

        $this->rehearsalId = (int) (new RehearsalRepository($pdo))
            ->listUpcomingScheduled('2026-03-10 12:05:00', 1)[0]['id'];

        $this->deps = $this->attendanceDeps($config, $pdo, $this->texts, $this->users);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_NOW');
    }

    public function testAdvanceWontComeWithoutReasonLeavesNoMark(): void
    {
        $bot = Nutgram::fake();

        AttendanceCallbackHandler::handle($bot, $this->deps, 'att:wx:' . $this->rehearsalId, self::MEMBER_TG);

        self::assertNull($this->deps->marks->find($this->rehearsalId, $this->memberId));
        self::assertNotNull($this->deps->flows->get(self::MEMBER_TG));

        AttendanceMemberHandler::handleFlowMessage($bot, $this->deps, self::MEMBER_TG, '   ');
        self::assertNull($this->deps->marks->find($this->rehearsalId, $this->memberId));
    }

    public function testAdvanceWontComeWithReasonRecordsWarned(): void
    {
        $bot = Nutgram::fake();

        AttendanceCallbackHandler::handle($bot, $this->deps, 'att:wx:' . $this->rehearsalId, self::MEMBER_TG);
        AttendanceMemberHandler::handleFlowMessage($bot, $this->deps, self::MEMBER_TG, 'болею');

        $mark = $this->deps->marks->find($this->rehearsalId, $this->memberId);
        self::assertNotNull($mark);
        self::assertSame(AttendanceMark::WARNED, $mark['mark']);
        self::assertSame('болею', $mark['reason_text']);
        self::assertSame(AttendanceSource::ABSENCE_BUTTON, $mark['source']);
    }

    public function testPollSkipWithoutReasonRecordsUnexcused(): void
    {
        putenv('FAKE_NOW=2026-03-11T19:35:00+05:00');

        $config = $this->testConfig();
        $deps = $this->attendanceDeps($config, $this->pdo, $this->texts, $this->users);
        $bot = Nutgram::fake();

        AttendanceCallbackHandler::handle(
            $bot,
            $deps,
            'att:ci:' . $this->rehearsalId . ':n',
            self::MEMBER_TG,
        );

        AttendanceCallbackHandler::handle($bot, $deps, 'att:sk', self::MEMBER_TG);

        $mark = $deps->marks->find($this->rehearsalId, $this->memberId);
        self::assertNotNull($mark);
        self::assertSame(AttendanceMark::UNEXCUSED, $mark['mark']);
        self::assertSame(AttendanceSource::CHECKIN, $mark['source']);
    }

    public function testPollReasonPromptContainsItalicDisclaimerAndNoReasonButton(): void
    {
        $messages = new AttendanceMessageFactory($this->texts);
        $prompt = $messages->pollReasonPromptText();

        self::assertStringContainsString($this->texts->get('reminder.will_not_attend'), $prompt);
        self::assertStringContainsString('Без причины пропуск считается неуважительным', strip_tags($prompt));
        $keyboardJson = json_encode($messages->reasonOptionalKeyboard(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString($this->texts->get('buttons.skip_reason'), $keyboardJson);
        self::assertStringContainsString('att:sk', $keyboardJson);
    }

    public function testAboutMemberTextMentionsPaymentReminderDays(): void
    {
        $body = \App\Handlers\AboutBotHandler::sectionText($this->texts, 'member');
        self::assertStringContainsString('23-го', $body);
        self::assertStringContainsString('1-го', $body);
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

    private function attendanceDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): AttendanceDeps {
        $rehearsals = new RehearsalRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $marks = new AttendanceMarkRepository($pdo);
        $outbound = new RecordingTelegramOutbound();
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
