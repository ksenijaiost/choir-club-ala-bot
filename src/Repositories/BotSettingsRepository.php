<?php

declare(strict_types=1);

namespace App\Repositories;

final class BotSettingsRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM bot_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }

    public function getInt(string $key, int $default): int
    {
        $raw = $this->get($key);
        if ($raw === null || !ctype_digit($raw)) {
            return $default;
        }

        return (int) $raw;
    }
}
