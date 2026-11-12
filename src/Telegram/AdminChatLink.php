<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config;
use App\Services\AdminChatResolver;

final class AdminChatLink
{
    public static function resolveUrl(AdminChatResolver $resolver, Config $config): ?string
    {
        $chatId = trim((string) ($resolver->resolvedId() ?? ''));
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
