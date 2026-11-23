<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;

final class NutgramFactory
{
    public static function configuration(Config $config): Configuration
    {
        return new Configuration(
            botName: BotCommandMention::normalizeUsername($config->botUsername),
            enableHttp2: false,
            clientOptions: TelegramGuzzleRetry::clientOptions(),
        );
    }

    public static function make(Config $config): Nutgram
    {
        if ($config->botToken === '') {
            throw new \RuntimeException('BOT_TOKEN is not set in .env');
        }

        return new Nutgram($config->botToken, self::configuration($config));
    }
}