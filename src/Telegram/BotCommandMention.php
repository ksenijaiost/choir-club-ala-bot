<?php

declare(strict_types=1);

namespace App\Telegram;

final class BotCommandMention
{
    public static function normalizeUsername(string $username): string
    {
        return ltrim(trim($username), '@');
    }

    public static function firstLine(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return trim(explode("\n", $normalized, 2)[0]);
    }

    /**
     * First line is /{command} or /{command}@{botUsername} (case-insensitive bot name).
     * A mention of another bot returns false.
     */
    public static function firstLineMatches(string $text, string $command, string $botUsername): bool
    {
        $command = ltrim($command, '/');
        $botUsername = self::normalizeUsername($botUsername);
        $firstLine = self::firstLine($text);
        if ($firstLine === '') {
            return false;
        }

        $pattern = sprintf(
            '/^\/%s(?:@([A-Za-z0-9_]+))?(?:\s.*)?$/iu',
            preg_quote($command, '/'),
        );
        if (preg_match($pattern, $firstLine, $matches) !== 1) {
            return false;
        }

        $mentioned = $matches[1] ?? null;
        if ($mentioned !== null && strcasecmp($mentioned, $botUsername) !== 0) {
            return false;
        }

        return true;
    }

    public static function isNotesOrTranslit(string $text, string $botUsername): bool
    {
        return self::firstLineMatches($text, 'notes', $botUsername)
            || self::firstLineMatches($text, 'translit', $botUsername);
    }
}