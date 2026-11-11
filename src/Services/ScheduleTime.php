<?php

declare(strict_types=1);

namespace App\Services;

final class ScheduleTime
{
    public static function combineLocalDateAndTime(
        \DateTimeImmutable $date,
        string $timeHms,
        \DateTimeZone $tz,
    ): \DateTimeImmutable {
        $time = substr($timeHms, 0, 8);
        $normalized = $date->format('Y-m-d') . ' ' . $time;

        return new \DateTimeImmutable($normalized, $tz);
    }

    public static function formatLocalDate(\DateTimeImmutable $dt, \DateTimeZone $tz): string
    {
        $local = $dt->setTimezone($tz);

        return $local->format('d.m.Y');
    }

    public static function formatLocalTime(\DateTimeImmutable $dt, \DateTimeZone $tz): string
    {
        $local = $dt->setTimezone($tz);

        return $local->format('H:i');
    }

    public static function toStorage(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s');
    }

    public static function parseUserDateTime(string $input, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $input = trim($input);
        $formats = ['d.m.Y H:i', 'Y-m-d H:i', 'd.m.Y H:i:s', 'Y-m-d H:i:s'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $input, $tz);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }
}
