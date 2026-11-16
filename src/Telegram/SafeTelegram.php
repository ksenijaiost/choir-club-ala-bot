<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Nutgram;

final class SafeTelegram
{
    /**
     * @param array<string, mixed> $options
     */
    public static function answerCallbackQuery(Nutgram $bot, array $options = []): void
    {
        try {
            $bot->answerCallbackQuery(...$options);
        } catch (\Throwable $e) {
            error_log('[telegram] answerCallbackQuery warning: ' . $e->getMessage());
        }
    }
}