<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public function __construct(
        public readonly string $botToken,
        public readonly string $ownerTelegramId,
        public readonly string $conductorTelegramId,
        public readonly string $adminChatId,
        public readonly string $dbConnection,
        public readonly string $dbHost,
        public readonly string $dbPort,
        public readonly string $dbDatabase,
        public readonly string $dbUsername,
        public readonly string $dbPassword,
        public readonly string $timezone,
        public readonly string $webhookSecret,
        public readonly string $adminPagesKey,
        public readonly string $webhookUrl,
        public readonly ?string $fakeNow,
        public readonly string $adminChatLink = '',
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            botToken: self::envString('BOT_TOKEN'),
            ownerTelegramId: self::envString('OWNER_TELEGRAM_ID'),
            conductorTelegramId: self::envString('CONDUCTOR_TELEGRAM_ID'),
            adminChatId: self::envString('ADMIN_CHAT_ID'),
            dbConnection: self::envString('DB_CONNECTION', 'sqlite'),
            dbHost: self::envString('DB_HOST', 'localhost'),
            dbPort: self::envString('DB_PORT', '3306'),
            dbDatabase: self::envString('DB_DATABASE', 'storage/choir.sqlite'),
            dbUsername: self::envString('DB_USERNAME', ''),
            dbPassword: self::envString('DB_PASSWORD', ''),
            timezone: self::envString('TIMEZONE', 'Asia/Almaty'),
            webhookSecret: self::envString('WEBHOOK_SECRET'),
            adminPagesKey: self::envString('ADMIN_PAGES_KEY'),
            webhookUrl: self::envString('WEBHOOK_URL'),
            fakeNow: self::envOptional('FAKE_NOW'),
            adminChatLink: self::envString('ADMIN_CHAT_LINK'),
        );
    }

    public function isSqlite(): bool
    {
        return strtolower($this->dbConnection) === 'sqlite';
    }

    public function migrationDriver(): string
    {
        return $this->isSqlite() ? 'sqlite' : 'mysql';
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    private static function envOptional(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
