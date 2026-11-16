<?php

declare(strict_types=1);

namespace App\Services;

use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class TranslitUploadCommand
{
    public const COMMAND = '/translit';

    public static function isInvoked(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $firstLine = explode("\n", str_replace(["\r\n", "\r"], "\n", $text), 2)[0];

        return preg_match('/^\/translit(?:@[\w_]+)?(?:\s|$)/u', trim($firstLine)) === 1;
    }

    public static function extractText(Message $message): ?string
    {
        $raw = $message->text ?? $message->caption;
        if ($raw === null || !self::isInvoked($raw)) {
            return null;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = explode("\n", $normalized, 2);
        $afterCommand = $parts[1] ?? '';
        $afterCommand = ltrim($afterCommand, "\n");

        if ($afterCommand !== '') {
            return $afterCommand;
        }

        $reply = $message->reply_to_message;
        if ($reply === null) {
            return null;
        }

        $replyText = $reply->text ?? $reply->caption;
        if ($replyText === null) {
            return null;
        }

        $body = str_replace(["\r\n", "\r"], "\n", $replyText);

        return trim($body) === '' ? null : $body;
    }
}