<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\RegistrationRepository;
use App\Repositories\SongRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Services\UserRolePresenter;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentTexts;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Telegram\NutgramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class HandlerRegistry
{
    public static function register(Nutgram $bot, TextRepository $texts, Config $config, \PDO $pdo): void
    {
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
        $outbound = new NutgramOutbound($bot);
        $recipients = new NotificationRecipientResolver($config, $users);
        $recalculator = new ConsecutiveUnexcusedRecalculator($marks, $users);
        $discipline = new AttendanceDiscipline($users, $texts, $recipients, $outbound, $blockService);
        $attendanceService = new AttendanceService($marks, $users, $discipline, $recalculator);
        $conductorNotifier = new AttendanceConductorNotifier($recipients, $texts, $outbound);

        $payments = new PaymentRecordRepository($pdo);
        $paymentDeps = new PaymentDeps(
            $config,
            $users,
            $payments,
            new PaymentTexts($texts),
            $recipients,
            $texts,
        );

        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $materials = new MaterialsDeps(
            $config,
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            new MaterialsService($materialRepo, $songRepo, $texts),
            new SongDeliveryService($materialRepo, $texts, new MaterialsService($materialRepo, $songRepo, $texts)),
            $texts,
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
        );

        $adminChat = new AdminChatDeps($config, $settings, $adminChatResolver, $flows, $texts);

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
        );
        MaterialsAdminChatHandler::register($bot, $texts, $access, $materials);
        StartHandler::register($bot, $texts, $users, $access);
        ConductorAdminHandler::register($bot, $texts, $users, $access);
        HelpersAdminHandler::register($bot, $texts, $users, $access);
        MembersAdminHandler::register($bot, $texts, $users, $access, $blockService);
        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance, $paymentDeps, $materials, $adminHistory, $adminChat);
    }
}
