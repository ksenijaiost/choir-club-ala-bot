<?php

declare(strict_types=1);

namespace App\Services;

final class RecordingSourceUrl
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^http://#i', $url) === 1) {
            $url = 'https://' . substr($url, 7);
        }

        if (preg_match('#^https://telegram\.me/#i', $url) === 1) {
            $url = 'https://t.me/' . substr($url, strlen('https://telegram.me/'));
        }

        return $url;
    }

    public static function isValid(string $url): bool
    {
        $url = self::normalize($url);
        if ($url === '') {
            return false;
        }

        if (preg_match('#^https://t\.me/c/(\d+)/(\d+)(?:/(\d+))?(?:\?.*)?$#iu', $url, $matches) === 1) {
            return $matches[2] !== '';
        }

        if (preg_match('#^https://t\.me/([A-Za-z0-9_]+)/(\d+)(?:/(\d+))?(?:\?.*)?$#iu', $url, $matches) === 1) {
            if (strtolower($matches[1]) === 'c') {
                return false;
            }

            return $matches[2] !== '';
        }

        return false;
    }
}