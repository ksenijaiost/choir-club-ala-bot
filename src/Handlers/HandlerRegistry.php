<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\RegistrationRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class HandlerRegistry
{
    public static function register(Nutgram $bot, TextRepository $texts, Config $config, \PDO $pdo): void
    {
        $users = new UserRepository($pdo);
        $registrations = new RegistrationRepository($pdo);
        $access = new AccessControl($config);

        CallbackQueryHandler::register($bot, $texts, $config, $users, $registrations, $access);
        StartHandler::register($bot, $texts, $users, $access);
        ConductorAdminHandler::register($bot, $texts, $users, $access);
        HelpersAdminHandler::register($bot, $texts, $users, $access);
        MessageHandler::register($bot, $texts, $users, $access);
    }
}
