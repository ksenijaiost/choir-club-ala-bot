<?php

declare(strict_types=1);

namespace App\Support;

final class DbTime
{
    public static function parseStored(string $driver, string $value, \DateTimeZone $displayTz): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        $storageTz = $driver === 'sqlite'
            ? new \DateTimeZone('UTC')
            : $displayTz;

        try {
            $parsed = new \DateTimeImmutable($value, $storageTz);
        } catch (\Throwable) {
            return null;
        }

        return $parsed->setTimezone($displayTz);
    }

    public static function formatHi(string $driver, string $value, \DateTimeZone $displayTz): string
    {
        $parsed = self::parseStored($driver, $value, $displayTz);

        return $parsed === null ? '—' : $parsed->format('H:i');
    }
}