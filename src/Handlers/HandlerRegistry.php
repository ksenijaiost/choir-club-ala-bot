<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BlockEventRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\RegistrationRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AttendanceConductorNotifier;
use App\Services\AttendanceDiscipline;
use App\Services\AttendanceHistoryPresenter;
use App\Services\AttendanceMessageFactory;
use App\Services\AttendanceService;
use App\Services\ConsecutiveUnexcusedRecalculator;
use App\Services\MemberBlockService;
use App\Services\NotificationRecipientResolver;
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
        $access = new AccessControl($config);

        $templates = new ScheduleTemplateRepository($pdo);
        $rehearsals = new RehearsalRepository($pdo);
        $settings = new BotSettingsRepository($pdo);
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
        );
        StartHandler::register($bot, $texts, $users, $access);
        ConductorAdminHandler::register($bot, $texts, $users, $access);
        HelpersAdminHandler::register($bot, $texts, $users, $access);
        MembersAdminHandler::register($bot, $texts, $users, $access, $blockService);
        MessageHandler::register($bot, $texts, $users, $access, $schedule, $attendance);
    }
}
