<?php

declare(strict_types=1);

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Types\User\User;

final class TelegramHtml
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function userLink(int $telegramId, string $label): string
    {
        return '<a href="tg://user?id=' . $telegramId . '">' . self::escape($label) . '</a>';
    }

    public static function usernameLink(string $username): string
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return '';
        }

        return '<a href="https://t.me/' . self::escape($username) . '">@' . self::escape($username) . '</a>';
    }

    /**
     * @return array{profile_line: string, telegram_id_line: string}
     */
    public static function profileBlock(string $profileLabel, string $idLabel, int $telegramId, string $linkLabel, ?string $username): array
    {
        $profile = self::escape($profileLabel) . self::userLink($telegramId, $linkLabel);
        $trimmedUsername = $username !== null ? trim($username) : '';
        if ($trimmedUsername !== '') {
            $profile .= ' (' . self::usernameLink($trimmedUsername) . ')';
        }

        return [
            'profile_line' => $profile,
            'telegram_id_line' => self::escape($idLabel) . (string) $telegramId,
        ];
    }

    public static function telegramDisplayName(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $parts = array_filter([trim($user->first_name ?? ''), trim($user->last_name ?? '')]);

        return $parts !== [] ? implode(' ', $parts) : 'User';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function memberNameLink(array $user): string
    {
        $telegramId = (int) ($user['telegram_id'] ?? 0);
        $label = (string) ($user['display_name'] ?? '');

        return $telegramId > 0 ? self::userLink($telegramId, $label) : self::escape($label);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function memberUsernameSuffix(array $user): string
    {
        $username = trim((string) ($user['telegram_username'] ?? ''));
        if ($username === '') {
            return '';
        }

        return ' ' . self::usernameLink($username);
    }
}