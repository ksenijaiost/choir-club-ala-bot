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
use App\Telegram\InteractiveMenuService;
use App\Repositories\UserOpenMenuRepository;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class CheckinFlowTest extends TestCase
{
    private const MEMBER_TG = 88_001;
    private const ADMIN_CHAT_ID = -100_777_001;

    public function testCheckinQuestionMentionsLateness(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        self::assertStringContainsString('опаздываешь', strtolower($texts->get('checkin.question')));
    }

    public function testNoThenLateRecordsAttended(): void
    {
        putenv('FAKE_NOW=2026-03-11T19:35:00+05:00');

        ['deps' => $deps, 'pdo' => $pdo] = $this->fixture();
        $rehearsalId = $this->seedRehearsal($pdo);
        (new CheckInSessionRepository($pdo))->ensureOpen($rehearsalId);

        $bot = Nutgram::fake();
        AttendanceCallbackHandler::handle($bot, $deps, 'att:ci:' . $rehearsalId . ':n', self::MEMBER_TG);
        AttendanceCallbackHandler::handle($bot, $deps, 'chk:late:' . $rehearsalId, self::MEMBER_TG);

        $mark = $deps->marks->find($rehearsalId, $this->memberId($deps));
        self::assertNotNull($mark);
        self::assertSame(AttendanceMark::ATTENDED, $mark['mark']);
        self::assertSame(AttendanceSource::CHECKIN, $mark['source']);

        putenv('FAKE_NOW');
    }

    public function testEditClearsCheckinMarkAndAllowsNewAnswer(): void
    {
        putenv('FAKE_NOW=2026-03-11T19:35:00+05:00');

        ['deps' => $deps, 'pdo' => $pdo] = $this->fixture();
        $rehearsalId = $this->seedRehearsal($pdo);
        (new CheckInSessionRepository($pdo))->ensureOpen($rehearsalId);
        $memberId = $this->memberId($deps);

        $bot = Nutgram::fake();
        AttendanceCallbackHandler::handle($bot, $deps, 'att:ci:' . $rehearsalId . ':y', self::MEMBER_TG);
        self::assertSame(AttendanceMark::ATTENDED, $deps->marks->find($rehearsalId, $memberId)['mark']);

        AttendanceCallbackHandler::handle($bot, $deps, 'chk:edit:' . $rehearsalId, self::MEMBER_TG);
        self::assertNull($deps->marks->find($rehearsalId, $memberId));

        AttendanceCallbackHandler::handle($bot, $deps, 'att:ci:' . $rehearsalId . ':y', self::MEMBER_TG);
        self::assertSame(AttendanceMark::ATTENDED, $deps->marks->find($rehearsalId, $memberId)['mark']);

        putenv('FAKE_NOW');
    }

    public function testEditManualMarkShowsAlert(): void
    {
        putenv('FAKE_NOW=2026-03-11T19:35:00+05:00');

        ['deps' => $deps, 'pdo' => $pdo] = $this->fixture();
        $rehearsalId = $this->seedRehearsal($pdo);
        (new CheckInSessionRepository($pdo))->ensureOpen($rehearsalId);
        $memberId = $this->memberId($deps);

        $deps->attendance->recordManual($memberId, $rehearsalId, AttendanceMark::ATTENDED);

        $bot = Nutgram::fake();
        AttendanceCallbackHandler::handle($bot, $deps, 'chk:edit:' . $rehearsalId, self::MEMBER_TG);

        self::assertSame(AttendanceMark::ATTENDED, $deps->marks->find($rehearsalId, $memberId)['mark']);

        putenv('FAKE_NOW');
    }

    public function testEditAttendedToAbsentNotifiesAdminChat(): void
    {
        putenv('FAKE_NOW=2026-03-11T19:35:00+05:00');

        $outbound = new RecordingTelegramOutbound();
        ['deps' => $deps, 'pdo' => $pdo] = $this->fixture($outbound);
        $rehearsalId = $this->seedRehearsal($pdo);
        (new CheckInSessionRepository($pdo))->ensureOpen($rehearsalId);

        $bot = Nutgram::fake();
        AttendanceCallbackHandler::handle($bot, $deps, 'att:ci:' . $rehearsalId . ':y', self::MEMBER_TG);
        AttendanceCallbackHandler::handle($bot, $deps, 'chk:edit:' . $rehearsalId, self::MEMBER_TG);
        AttendanceCallbackHandler::handle($bot, $deps, 'att:ci:' . $rehearsalId . ':n', self::MEMBER_TG);
        AttendanceCallbackHandler::handle($bot, $deps, 'chk:absent:' . $rehearsalId, self::MEMBER_TG);
        AttendanceMemberHandler::handleFlowMessage($bot, $deps, self::MEMBER_TG, 'пробка');

        $notified = false;
        foreach ($outbound->messages as $message) {
            if ($message['chat_id'] === self::ADMIN_CHAT_ID
                && str_contains($message['text'], 'изменил(а) ответ')) {
                $notified = true;
                self::assertStringContainsString('пробка', $message['text']);
            }
        }
        self::assertTrue($notified);

        putenv('FAKE_NOW');
    }

    public function testAboutMentionsLatenessAndEditAnswer(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $body = AboutBotHandler::sectionText($texts, 'member');
        self::assertStringContainsString('Опоздание', $body);
        self::assertStringContainsString('Изменить ответ', $body);
    }

    private function seedRehearsal(\PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO rehearsals (template_id, starts_at, ends_at, address, status) VALUES (NULL, ?, ?, ?, ?)'
        )->execute(['2026-03-11 19:30:00', '2026-03-11 21:30:00', 'зал', 'scheduled']);

        return (int) $pdo->lastInsertId();
    }

    private function memberId(AttendanceDeps $deps): int
    {
        $user = $deps->users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($user);

        return (int) $user['id'];
    }

    /**
     * @return array{deps: AttendanceDeps, pdo: \PDO}
     */
    private function fixture(?RecordingTelegramOutbound $outbound = null): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $outbound ??= new RecordingTelegramOutbound();
        $deps = \App\Tests\Support\AttendanceDepsFactory::make($config, $pdo, $texts, $users, $outbound);

        return ['deps' => $deps, 'pdo' => $pdo];
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: (string) self::ADMIN_CHAT_ID,
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