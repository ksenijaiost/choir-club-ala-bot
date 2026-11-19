<?php

declare(strict_types=1);

namespace App\Services;

final class RecordingSourceUrl
{
    public static function normalize(string $url): string
    {
        return trim($url);
    }

    public static function isValid(string $url): bool
    {
        $url = self::normalize($url);
        if ($url === '') {
            return false;
        }

        if (!str_starts_with(strtolower($url), 'https://t.me/')) {
            return false;
        }

        return preg_match('#^https://t\.me/(c/\d+/\d+|[A-Za-z0-9_]+/\d+)(?:\?.*)?$#iu', $url) === 1;
    }
}