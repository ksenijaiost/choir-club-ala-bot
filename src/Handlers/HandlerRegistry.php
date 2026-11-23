<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BroadcastRepository;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\ErrorReportRepository;
use App\Repositories\RegistrationRepository;
use App\Repositories\SongRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserActionRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\UserActionRecorder;
use App\Telegram\UserActionMiddleware;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Services\ErrorReportService;
use App\Services\UserRolePresenter;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\BroadcastDeliveryService;
use App\Services\BroadcastKeyboardBuilder;
use App\Services\BroadcastStatusPresenter;
use App\Services\MaterialNotificationService;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Telegram\InteractiveMenuService;
use App\Telegram\NutgramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class HandlerRegistry
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        \PDO $pdo,
        ?ErrorReportService $errorReports = null,
    ): void {
        $users = new UserRepository($pdo);
        $registrations = new RegistrationRepository($pdo);
        $blockEvents = new BlockEventRepository($pdo);
        $blockService = new MemberBlockService($users, $blockEvents);
        $settings = new BotSettingsRepository($pdo);
        $adminChatResolver = new AdminChatResolver($settings, $config);
        $access = new AccessControl($config, $adminChatResolver);

        $templates = new ScheduleTemplateRepository($pdo);
        $rehearsals = new RehearsalRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $interactiveMenus = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $userActions = new UserActionRepository($pdo);
        $errorReports ??= new ErrorReportService(
            new ErrorReportRepository($pdo),
            $userActions,
            $users,
            $texts,
            $config,
            $adminChatResolver,
        );
        UserActionMiddleware::register($bot, new UserActionRecorder($userActions, $texts, $flows));
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
            $interactiveMenus,
        );

        $marks = new AttendanceMarkRepository($pdo);
        $outbound = new NutgramOutbound($bot);
        $recipients = new NotificationRecipientResolver($config, $users);
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blockService);
        $attendanceService = new AttendanceService($marks, $users, $discipline, $recalculator);
        $conductorNotifier = new AttendanceConductorNotifier($recipients, $texts, $outbound);

        $payments = new PaymentRecordRepository($pdo);
        $paymentTexts = new PaymentTexts($texts);
        $paymentDeps = new PaymentDeps(
            $config,
            $users,
            $payments,
            $paymentTexts,
            $recipients,
            $texts,
            $settings,
            $flows,
            new PaymentReminderTextService($settings, $texts, $paymentTexts),
            $interactiveMenus,
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
            new SongDeliveryService($materialRepo, $songRepo, $texts, $materialsService),
            new MaterialNotificationService($users, $texts, $materialsService, $outbound),
            $texts,
            $interactiveMenus,
        );

        $rolePresenter = new UserRolePresenter($texts);
        $adminHistory = new AdminHistoryContext(
            $config,
            $users,
            $marks,
            $payments,
            $texts,
            new AttendanceHistoryPresenter($texts),
            new PaymentTexts($texts),
            $rolePresenter,
            $interactiveMenus,
        );

        $adminChat = new AdminChatDeps($config, $settings, $adminChatResolver, $flows, $texts, $interactiveMenus);

        $broadcastRepo = new BroadcastRepository($pdo);
        $broadcastKeyboards = new BroadcastKeyboardBuilder($texts);
        $broadcastDelivery = new BroadcastDeliveryService(
            $users,
            $broadcastRepo,
            $texts,
            $rolePresenter,
            $broadcastKeyboards,
            $outbound,
        );
        $broadcastStatus = new BroadcastStatusPresenter($texts, $broadcastRepo, $users);
        $broadcast = new BroadcastDeps(
            $config,
            $users,
            $broadcastRepo,
            $flows,
            $texts,
            $interactiveMenus,
            $broadcastDelivery,
            $broadcastStatus,
            $broadcastKeyboards,
            $rolePresenter,
            $outbound,
        );

        $attendance = new AttendanceDeps(
            $config,
            $users,
            $rehearsals,
            $marks,
            $flows,
            $attendanceService,
            new AttendanceMessageFactory($texts),
            $conductorNotifier,
            $recalculator,
            new AttendanceHistoryPresenter($texts),
            $texts,
            new CheckInSessionRepository($pdo),
            $interactiveMenus,
        );

        CallbackQueryHandler::register(
            $bot,
            $texts,
            $config,
            $users,
            $registrations,
            $access,
            $blockService,
            $schedule,
            $attendance,
            $paymentDeps,
            $materials,
            $adminHistory,
            $adminChat,
            $broadcast,
            $interactiveMenus,
            $errorReports,
        );
        MaterialsAdminChatHandler::register($bot, $texts, $access, $materials);
        AdminChatCommandHandler::register($bot, $texts, $access, $users, $songRepo, $materialRepo, $materials);
        ErrorReportHandler::register($bot, $texts, $users, $access, $flows, $errorReports);
        StartHandler::register($bot, $texts, $users, $access, $interactiveMenus);
        ConductorAdminHandler::register($bot, $texts, $users, $access, $interactiveMenus);
        HelpersAdminHandler::register($bot, $texts, $users, $access, $interactiveMenus);
        MembersAdminHandler::register($bot, $texts, $users, $access, $blockService, $interactiveMenus);
        BroadcastCommandHandler::register($bot, $broadcast, $access, $users);
        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance, $paymentDeps, $materials, $adminHistory, $adminChat, $broadcast, $errorReports);
    }
}
