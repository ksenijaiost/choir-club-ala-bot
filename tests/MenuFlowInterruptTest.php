<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AdminHistoryContext;
use App\Handlers\AttendanceDeps;
use App\Handlers\MaterialsDeps;
use App\Handlers\MessageHandler;
use App\Handlers\PaymentDeps;
use App\Handlers\ScheduleDeps;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MaterialsService;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentTexts;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Services\SongDeliveryService;
use App\Services\UserRolePresenter;
use App\Tests\Support\RecordingTelegramOutbound;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Psr7\Request;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class MenuFlowInterruptTest extends TestCase
{
    private const OWNER_TG = 91_501;
    private const MEMBER_TG = 91_502;

    public function testSongSearchFlowInterruptedByAdminHistoryMenuButton(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig((string) self::OWNER_TG);
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Техспец');
        $users->create(91_503, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $flows = new UserFlowRepository($pdo);
        $deps = $this->messageHandlerDeps($config, $pdo, $texts, $users, $flows);
        $flows->set(self::OWNER_TG, 'song_search', []);

        $bot = $this->fakePrivateBot(self::OWNER_TG);
        MessageHandler::register(
            $bot,
            $texts,
            $users,
            $deps['access'],
            $deps['schedule'],
            $deps['attendance'],
            $deps['payments'],
            $deps['materials'],
            $deps['adminHistory'],
        );

        $notFound = $texts->get('songs.not_found');
        $historyPick = $texts->get('history.pick_member');

        $bot->hearText($texts->get('menu.admin_history'))
            ->willStartConversation()
            ->reply()
            ->assertRaw(function (Request $request) use ($historyPick, $notFound): bool {
                if (!str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                    return false;
                }

                $data = json_decode((string) $request->getBody(), true);
                $text = (string) ($data['text'] ?? '');

                return str_contains($text, $historyPick) && $text !== $notFound;
            }, message: 'Expected admin history picker, not song search');

        self::assertNull($flows->get(self::OWNER_TG));
    }

    public function testProfileRenameFlowInterruptedByScheduleMenuButton(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig('1');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Старое имя', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $flows = new UserFlowRepository($pdo);
        $deps = $this->messageHandlerDeps($config, $pdo, $texts, $users, $flows);
        $flows->set(self::MEMBER_TG, 'profile_rename', []);

        $bot = $this->fakePrivateBot(self::MEMBER_TG);
        MessageHandler::register(
            $bot,
            $texts,
            $users,
            $deps['access'],
            $deps['schedule'],
            $deps['attendance'],
            $deps['payments'],
            $deps['materials'],
            $deps['adminHistory'],
        );

        $scheduleHint = $texts->get('schedule.list_header');
        $emptySchedule = $texts->get('schedule.empty');

        $bot->hearText($texts->get('menu.schedule'))
            ->willStartConversation()
            ->reply()
            ->assertRaw(function (Request $request) use ($scheduleHint, $emptySchedule): bool {
                if (!str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                    return false;
                }

                $data = json_decode((string) $request->getBody(), true);
                $text = (string) ($data['text'] ?? '');

                return str_contains($text, $scheduleHint) || $text === $emptySchedule;
            }, message: 'Expected schedule view');

        $user = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($user);
        self::assertSame('Старое имя', $user['display_name']);
        self::assertNull($flows->get(self::MEMBER_TG));
    }

    private function fakePrivateBot(int $telegramId): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $telegramId;
        $user->is_bot = false;
        $user->first_name = 'Тест';
        $bot->setCommonUser($user);

        $chat = new Chat();
        $chat->id = $telegramId;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        return $bot;
    }

    /**
     * @return array{
     *     access: AccessControl,
     *     schedule: ScheduleDeps,
     *     attendance: AttendanceDeps,
     *     payments: PaymentDeps,
     *     materials: MaterialsDeps,
     *     adminHistory: AdminHistoryContext,
     * }
     */
    private function messageHandlerDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
        UserFlowRepository $flows,
    ): array {
        $templates = new ScheduleTemplateRepository($pdo);
        $rehearsals = new RehearsalRepository($pdo);
        $settings = new BotSettingsRepository($pdo);
        $generator = new ScheduleGenerator($templates, $rehearsals, $settings);
        $presenter = new SchedulePresenter($rehearsals, $texts);
        $broadcast = new ScheduleBroadcastService($users, $texts);

        $schedule = new ScheduleDeps(
            $config,
            $templates,
            $rehearsals,
            $flows,
            $settings,
            $generator,
            $presenter,
            $broadcast,
            $users,
            $texts,
        );

        $marks = new AttendanceMarkRepository($pdo);
        $outbound = new RecordingTelegramOutbound();
        $recipients = new NotificationRecipientResolver($config, $users);
        $blocks = new MemberBlockService($users, new BlockEventRepository($pdo));
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blocks);
        $service = new AttendanceService($marks, $users, $discipline, $recalculator);

        $attendance = new AttendanceDeps(
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

        $payments = new PaymentDeps(
            $config,
            $users,
            new PaymentRecordRepository($pdo),
            new PaymentTexts($texts),
            $recipients,
            $texts,
        );

        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $materialsService = new MaterialsService($materialRepo, $songRepo, $texts);
        $materials = new MaterialsDeps(
            $config,
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            $materialsService,
            new SongDeliveryService($materialRepo, $texts, $materialsService),
            $texts,
        );

        $adminHistory = new AdminHistoryContext(
            $config,
            $users,
            $marks,
            new PaymentRecordRepository($pdo),
            $texts,
            new AttendanceHistoryPresenter($texts),
            new PaymentTexts($texts),
            new UserRolePresenter($texts),
        );

        return [
            'access' => new AccessControl($config),
            'schedule' => $schedule,
            'attendance' => $attendance,
            'payments' => $payments,
            'materials' => $materials,
            'adminHistory' => $adminHistory,
        ];
    }

    private function testConfig(string $ownerTelegramId): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: $ownerTelegramId,
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
