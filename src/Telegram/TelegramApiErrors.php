<?php

declare(strict_types=1);

namespace App\Telegram;

final class TelegramApiErrors
{
    public static function isMessageNotModified(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'message is not modified');
    }

    public static function isVoiceMessagesForbidden(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'VOICE_MESSAGES_FORBIDDEN');
    }

    public static function logTelegramIssue(string $method, \Throwable $e): void
    {
        if (self::isMessageNotModified($e)) {
            return;
        }

        if (self::isVoiceMessagesForbidden($e)) {
            error_log('[telegram] ' . $method . ' skipped: voice messages forbidden');

            return;
        }

        error_log('[telegram] ' . $method . ' warning: ' . $e->getMessage());
    }
}