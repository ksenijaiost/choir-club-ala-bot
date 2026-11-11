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

    public static function formatLocalTimeRange(
        \DateTimeImmutable $starts,
        \DateTimeImmutable $ends,
        \DateTimeZone $tz,
    ): string {
        return self::formatLocalTime($starts, $tz) . '–' . self::formatLocalTime($ends, $tz);
    }

    /**
     * @param array<string, mixed> $rehearsalRow
     */
    public static function formatRehearsalTimeRange(array $rehearsalRow, \DateTimeZone $tz): string
    {
        $starts = new \DateTimeImmutable((string) $rehearsalRow['starts_at'], $tz);
        $ends = new \DateTimeImmutable((string) $rehearsalRow['ends_at'], $tz);

        return self::formatLocalTimeRange($starts, $ends, $tz);
    }

    public static function formatTemplateTimeRange(string $startTime, int $durationMinutes, \DateTimeZone $tz): string
    {
        $start = new \DateTimeImmutable('2000-01-01 ' . substr($startTime, 0, 8), $tz);
        $end = $start->modify('+' . $durationMinutes . ' minutes');

        return self::formatLocalTimeRange($start, $end, $tz);
    }

    public static function minutesBetweenHm(string $startHm, string $endHm): ?int
    {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $startHm) || !preg_match('/^\d{1,2}:\d{2}$/', $endHm)) {
            return null;
        }

        [$sh, $sm] = array_map('intval', explode(':', $startHm));
        [$eh, $em] = array_map('intval', explode(':', $endHm));

        $startMinutes = $sh * 60 + $sm;
        $endMinutes = $eh * 60 + $em;

        if ($endMinutes <= $startMinutes) {
            return null;
        }

        return $endMinutes - $startMinutes;
    }

    public static function templateStartHm(string $startTime): string
    {
        return substr($startTime, 0, 5);
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
