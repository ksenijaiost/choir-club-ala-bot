<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config;

final class AdminChatLink
{
    public static function resolveUrl(Config $config): ?string
    {
        $chatId = trim($config->adminChatId);
        if ($chatId !== '' && str_starts_with($chatId, '-100')) {
            return 'https://t.me/c/' . substr($chatId, 4);
        }

        $link = trim($config->adminChatLink);
        if ($link !== '') {
            return $link;
        }

        return null;
    }
}
