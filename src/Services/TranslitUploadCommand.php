<?php

declare(strict_types=1);

namespace App\Services;

use App\Telegram\BotCommandMention;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class TranslitUploadCommand
{
    public const COMMAND = '/notes';

    public const LEGACY_COMMAND = '/translit';

    public const DEFAULT_BOT_USERNAME = 'CHOIR_CLUB_ALA_bot';

    public static function isInvoked(?string $text, ?string $botUsername = null): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $botUsername ??= self::DEFAULT_BOT_USERNAME;

        return BotCommandMention::isNotesOrTranslit($text, $botUsername);
    }

    public static function extractText(Message $message, ?string $botUsername = null): ?string
    {
        $raw = $message->text ?? $message->caption;
        if ($raw === null || !self::isInvoked($raw, $botUsername)) {
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