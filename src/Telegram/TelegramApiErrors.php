<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class TelegramApiErrors
{
    public static function isMessageNotModified(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'message is not modified');
    }

    public static function isRecipientUnreachable(\Throwable $e): bool
    {
        if (self::isMessageNotModified($e)) {
            return true;
        }

        $message = $e->getMessage();
        if (str_contains($message, 'bot was blocked')
            || str_contains($message, 'user is deactivated')
            || str_contains($message, 'chat not found')
            || str_contains($message, 'PEER_ID_INVALID')) {
            return true;
        }

        return $e instanceof TelegramException && $e->getCode() === 403;
    }

    public static function shouldSkipApiErrorLog(\Throwable $e): bool
    {
        return self::isMessageNotModified($e)
            || self::isRecipientUnreachable($e)
            || self::isVoiceMessagesForbidden($e);
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