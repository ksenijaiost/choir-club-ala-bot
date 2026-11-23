<?php

declare(strict_types=1);

namespace App\Services;

final class ProgramDateParser
{
    /**
     * @return array{year: int, month: int, day: int}|null
     */
    public static function parseUserInput(string $input, int $defaultYear): ?array
    {
        $text = trim($input);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m) === 1) {
            return self::valid((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $text, $m) === 1) {
            return self::valid($defaultYear, (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    public static function toIsoDate(int $year, int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @return array{year: int, month: int, day: int}|null
     */
    private static function valid(int $year, int $month, int $day): ?array
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }
}