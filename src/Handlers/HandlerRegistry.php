<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BlockEventRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\RegistrationRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\MemberBlockService;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
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

        CallbackQueryHandler::register($bot, $texts, $config, $users, $registrations, $access, $blockService, $schedule);
        StartHandler::register($bot, $texts, $users, $access);
        ConductorAdminHandler::register($bot, $texts, $users, $access);
        HelpersAdminHandler::register($bot, $texts, $users, $access);
        MembersAdminHandler::register($bot, $texts, $users, $access, $blockService);
        MessageHandler::register($bot, $texts, $users, $access, $schedule);
    }
}
