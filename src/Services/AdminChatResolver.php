<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Repositories\BotSettingsRepository;

final class AdminChatResolver
{
    public const SETTING_KEY = 'admin_chat_id';

    public function __construct(
        private readonly BotSettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function resolvedId(): ?string
    {
        $fromDb = trim((string) ($this->settings->get(self::SETTING_KEY) ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        $fromEnv = trim($this->config->adminChatId);
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return null;
    }

    public function resolvedIdAsInt(): ?int
    {
        $id = $this->resolvedId();
        if ($id === null || !preg_match('/^-?\d+$/', $id)) {
            return null;
        }

        return (int) $id;
    }

    public function isAdminChat(int|string $chatId): bool
    {
        $resolved = $this->resolvedId();
        if ($resolved === null) {
            return false;
        }

        return (string) $chatId === $resolved;
    }
}
