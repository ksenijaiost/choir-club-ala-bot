<?php

declare(strict_types=1);

namespace App;

use App\Database\Connection;
use App\Handlers\HandlerRegistry;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class Bootstrap
{
    public static function basePath(): string
    {
        return dirname(__DIR__);
    }

    public static function loadEnv(): void
    {
        $base = self::basePath();
        if (is_file($base . '/.env')) {
            \Dotenv\Dotenv::createImmutable($base)->safeLoad();
        }
    }

    public static function config(): Config
    {
        return Config::fromEnv();
    }

    public static function pdo(): \PDO
    {
        return Connection::make(self::config());
    }

    public static function texts(): TextRepository
    {
        return TextRepository::fromFile(self::basePath() . '/texts/ru.json');
    }

    public static function bot(): Nutgram
    {
        $config = self::config();
        if ($config->botToken === '') {
            throw new \RuntimeException('BOT_TOKEN is not set in .env');
        }

        date_default_timezone_set($config->timezone);

        return new Nutgram($config->botToken);
    }

    public static function registerHandlers(Nutgram $bot): void
    {
        HandlerRegistry::register($bot, self::texts());
    }
}
