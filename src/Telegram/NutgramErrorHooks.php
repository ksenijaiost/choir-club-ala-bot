<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config;
use App\Support\ErrorLog;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class NutgramErrorHooks
{
    public static function register(Nutgram $bot, Config $config, TextRepository $texts): void
    {
        $bot->onException(function (Nutgram $bot, \Throwable $e) use ($config, $texts): void {
            ErrorLog::log($config, $e, ErrorLog::contextFromNutgram($bot));
            self::notifyUser($bot, $texts);
        });

        $bot->onApiError(function (Nutgram $bot, TelegramException $e) use ($config): void {
            ErrorLog::log($config, $e, ErrorLog::contextFromNutgram($bot));
        });
    }

    private static function notifyUser(Nutgram $bot, TextRepository $texts): void
    {
        try {
            $chatId = $bot->chatId();
            if ($chatId === null) {
                return;
            }

            $bot->sendMessage($texts->get('errors.generic'), chat_id: $chatId);
        } catch (\Throwable) {
            // ignore secondary failures
        }
    }
}