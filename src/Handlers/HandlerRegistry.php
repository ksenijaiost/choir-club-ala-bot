<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BroadcastRepository;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\RehearsalRsvpNudgeRepository;
use App\Repositories\RehearsalRsvpRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\ErrorReportRepository;
use App\Repositories\RegistrationRepository;
use App\Repositories\SongRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UsageStatsRepository;
use App\Repositories\UserActionRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\StatsPresenter;
use App\Services\UsageSectionResolver;
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
use App\Services\RehearsalRsvpNudgeService;
use App\Services\RehearsalRsvpStatusPresenter;
use App\Services\RehearsalRsvpStatusService;
use App\Services\BroadcastDeliveryService;
use App\Services\BroadcastKeyboardBuilder;
use App\Services\BroadcastStatusPresenter;
use App\Services\MaterialNotificationService;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentReminderTextService;
use App\Services\ProgramDeliveryService;
use App\Services\ProgramMessageBuilder;
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
        $usageStats = new UsageStatsRepository($pdo);
        $usageSections = new UsageSectionResolver($texts);
        UserActionMiddleware::register($bot, new UserActionRecorder(
            $userActions,
            $texts,
            $flows,
            $usageStats,
            $usageSections,
            $config,
        ));
        $statsPresenter = new StatsPresenter($usageStats, $texts, $config);
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
        $streakAwards = new \App\Repositories\UserStreakAwardRepository($pdo);
        $streak = new \App\Services\AttendanceStreakService($marks, $users, $streakAwards, $texts, $recipients, $outbound);
        $programRepo = new ProgramRepository($pdo);
        $payments = new PaymentRecordRepository($pdo);
        $achievements = new \App\Services\AchievementService(
            new \App\Repositories\UserAchievementRepository($pdo),
            $texts,
            $outbound,
            $marks,
            new DayBeforeReminderDeliveryRepository($pdo),
            $programRepo,
            $payments,
            $settings,
        );
        $attendanceService = new AttendanceService($marks, $users, $discipline, $recalculator, $streak, $achievements);
        $conductorNotifier = new AttendanceConductorNotifier($recipients, $texts, $outbound);
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
            $achievements,
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
            $achievements,
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

        $programMessages = new ProgramMessageBuilder($texts, $programRepo);
        $programNotifyDeliveries = new \App\Repositories\ProgramNotifyDeliveryRepository($pdo);
        $programDelivery = new ProgramDeliveryService(
            $config,
            $users,
            $programRepo,
            $programMessages,
            $texts,
            $outbound,
            $programNotifyDeliveries,
        );
        $programDeps = new ProgramDeps(
            $config,
            $users,
            $flows,
            $programRepo,
            $songRepo,
            $programMessages,
            $programDelivery,
            $texts,
            $interactiveMenus,
            $achievements,
        );

        $rsvpRepo = new RehearsalRsvpRepository($pdo);
        $dayBeforeDeliveries = new DayBeforeReminderDeliveryRepository($pdo);
        $rsvpNudgeRepo = new RehearsalRsvpNudgeRepository($pdo);
        $messageFactory = new AttendanceMessageFactory($texts);
        $rsvpNudge = new RehearsalRsvpNudgeService(
            $rsvpNudgeRepo,
            $rsvpRepo,
            $dayBeforeDeliveries,
            $rehearsals,
            $users,
            $messageFactory,
            $outbound,
        );

        $partBattle = new \App\Services\PartBattleService(
            $rehearsals,
            $users,
            $marks,
            $texts,
            $paymentTexts,
        );
        $programRepoForWrapped = $programRepo;
        $wrapped = new \App\Services\ChoirWrappedService(
            $rehearsals,
            $users,
            $marks,
            $streakAwards,
            new \App\Repositories\UserAchievementRepository($pdo),
            $programRepoForWrapped,
            $partBattle,
            $texts,
            $paymentTexts,
        );

        $attendance = new AttendanceDeps(
            $config,
            $users,
            $rehearsals,
            $marks,
            $flows,
            $attendanceService,
            $messageFactory,
            $conductorNotifier,
            $recalculator,
            new AttendanceHistoryPresenter($texts),
            $texts,
            new CheckInSessionRepository($pdo),
            $interactiveMenus,
            $rsvpRepo,
            $dayBeforeDeliveries,
            new RehearsalRsvpStatusService($rsvpRepo, $dayBeforeDeliveries, $marks),
            new RehearsalRsvpStatusPresenter($texts),
            $rsvpNudge,
            $streak,
            $achievements,
            $partBattle,
            $wrapped,
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
            $programDeps,
            $interactiveMenus,
            $errorReports,
            $statsPresenter,
        );
        MaterialsAdminChatHandler::register($bot, $texts, $access, $materials, $programDeps, $broadcast);
        AdminChatCommandHandler::register($bot, $texts, $access, $users, $songRepo, $materialRepo, $materials, $programRepo, $programDelivery);
        ErrorReportHandler::register($bot, $texts, $users, $access, $flows, $errorReports);
        StartHandler::register($bot, $texts, $users, $access, $interactiveMenus);
        ConductorAdminHandler::register($bot, $texts, $users, $access, $interactiveMenus);
        HelpersAdminHandler::register($bot, $texts, $users, $access, $interactiveMenus);
        MembersAdminHandler::register($bot, $texts, $users, $access, $blockService, $interactiveMenus);
        BroadcastCommandHandler::register($bot, $broadcast, $access, $users);
        RehearsalRsvpCommandHandler::register($bot, $attendance, $access, $users);
        StatsCommandHandler::register($bot, $texts, $users, $access, $statsPresenter);
        PrivateMenuCommandHandler::register(
            $bot,
            $texts,
            $users,
            $access,
            $schedule,
            $attendance,
            $paymentDeps,
            $materials,
            $adminHistory,
            $adminChat,
            $broadcast,
            $programDeps,
        );
        ProgramCommandHandler::register($bot, $programDeps, $access, $users);
        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance, $paymentDeps, $materials, $adminHistory, $adminChat, $broadcast, $programDeps, $errorReports);
    }
}
