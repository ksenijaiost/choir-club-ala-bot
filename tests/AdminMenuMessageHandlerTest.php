<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AdminChatDeps;
use App\Handlers\AdminHistoryContext;
use App\Repositories\BotSettingsRepository;
use App\Services\AdminChatResolver;
use App\Handlers\AttendanceDeps;
use App\Handlers\MaterialsDeps;
use App\Handlers\MessageHandler;
use App\Handlers\PaymentDeps;
use App\Handlers\ScheduleDeps;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BlockEventRepository;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Psr7\Request;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AdminMenuMessageHandlerTest extends TestCase
{
    private const OWNER_TG = 91_001;

    /**
     * @return list<array{string, string}>
     */
    public static function adminMenuButtons(): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        return [
            'admin_schedule' => [$texts->get('menu.admin_schedule'), $texts->get('schedule.admin_menu')],
            'admin_materials' => [$texts->get('menu.admin_materials'), $texts->get('materials.admin_chat_hint')],
            'admin_attendance' => [$texts->get('menu.admin_attendance'), $texts->get('attendance.admin_home')],
            'admin_payment' => [$texts->get('menu.admin_payment'), $texts->get('payment.admin_pick_month')],
            'admin_history' => [$texts->get('menu.admin_history'), $texts->get('history.pick_member')],
            'admin_helpers' => [$texts->get('menu.admin_helpers'), $texts->get('admin.helpers_list')],
            'admin_members' => [$texts->get('menu.admin_members'), $texts->get('admin.members_pick_filter')],
            'assign_conductor' => [$texts->get('menu.assign_conductor'), $texts->get('admin.conductor_manage_header')],
        ];
    }

    #[DataProvider('adminMenuButtons')]
    public function testAdminMenuButtonDoesNotReplySoon(string $buttonLabel, string $expectedSubstring): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Техспец');
        $users->create(91_002, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $schedule = $this->scheduleDeps($config, $pdo, $texts, $users);
        $attendance = $this->attendanceDeps($config, $pdo, $texts, $users);
        $payments = $this->paymentDeps($config, $pdo, $users, $texts);
        $materials = $this->materialsDeps($config, $pdo, $texts, $users);
        $adminHistory = $this->adminHistoryContext($config, $pdo, $users, $texts);
        $settings = new BotSettingsRepository($pdo);
        $resolver = new AdminChatResolver($settings, $config);
        $access = new AccessControl($config, $resolver);
        $adminChat = new AdminChatDeps($config, $settings, $resolver, $schedule->flows, $texts);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->makeUser());
        $bot->setCommonChat($this->makeChat(self::OWNER_TG, ChatType::PRIVATE));

        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance, $payments, $materials, $adminHistory, $adminChat);

        $soon = $texts->get('common.soon');

        $bot->hearText($buttonLabel)
            ->willStartConversation()
            ->reply()
            ->assertRaw(function (Request $request) use ($expectedSubstring, $soon): bool {
                if (!str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                    return false;
                }

                $data = json_decode((string) $request->getBody(), true);
                $text = (string) ($data['text'] ?? '');

                return str_contains($text, $expectedSubstring) && $text !== $soon;
            }, message: 'Expected menu handler reply containing: ' . $expectedSubstring);
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->id = self::OWNER_TG;
        $user->is_bot = false;
        $user->first_name = 'Техспец';

        return $user;
    }

    private function makeChat(int $id, ChatType $type): Chat
    {
        $chat = new Chat();
        $chat->id = $id;
        $chat->type = $type;

        return $chat;
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: (string) self::OWNER_TG,
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

    private function scheduleDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): ScheduleDeps {
        $templates = new ScheduleTemplateRepository($pdo);
        $rehearsals = new RehearsalRepository($pdo);
        $settings = new BotSettingsRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $generator = new ScheduleGenerator($templates, $rehearsals, $settings);
        $presenter = new SchedulePresenter($rehearsals, $texts);
        $broadcast = new ScheduleBroadcastService($users, $texts);

        return new ScheduleDeps(
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
    }

    private function paymentDeps(
        Config $config,
        \PDO $pdo,
        UserRepository $users,
        TextRepository $texts,
    ): PaymentDeps {
        $recipients = new NotificationRecipientResolver($config, $users);

        return new PaymentDeps(
            $config,
            $users,
            new PaymentRecordRepository($pdo),
            new PaymentTexts($texts),
            $recipients,
            $texts,
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

    private function materialsDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): MaterialsDeps {
        $flows = new UserFlowRepository($pdo);
        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $service = new MaterialsService($materialRepo, $songRepo, $texts);

        return new MaterialsDeps(
            $config,
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            $service,
            new SongDeliveryService($materialRepo, $texts, $service),
            $texts,
        );
    }

    private function adminHistoryContext(
        Config $config,
        \PDO $pdo,
        UserRepository $users,
        TextRepository $texts,
    ): AdminHistoryContext {
        $marks = new AttendanceMarkRepository($pdo);
        $payments = new PaymentRecordRepository($pdo);

        return new AdminHistoryContext(
            $config,
            $users,
            $marks,
            $payments,
            $texts,
            new AttendanceHistoryPresenter($texts),
            new PaymentTexts($texts),
            new UserRolePresenter($texts),
        );
    }
}
