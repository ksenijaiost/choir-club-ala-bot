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

    /**
     * Возвращает «HH:MM» или null, если строка не похожа на время.
     */
    public static function parseHmInput(string $text): ?string
    {
        $text = trim($text);
        if (!preg_match('/^\d{1,2}:\d{2}$/', $text)) {
            return null;
        }

        [$h, $m] = explode(':', $text);

        return sprintf('%02d:%02d', (int) $h, (int) $m);
    }

    /**
     * Минуты между двумя моментами в один календарный день; null, если конец не позже начала.
     */
    public static function minutesBetweenHm(string $startHm, string $endHm): ?int
    {
        $startParsed = self::parseHmInput($startHm);
        $endParsed = self::parseHmInput($endHm);
        if ($startParsed === null || $endParsed === null) {
            return null;
        }

        [$sh, $sm] = array_map('intval', explode(':', $startParsed));
        [$eh, $em] = array_map('intval', explode(':', $endParsed));

        $startMinutes = $sh * 60 + $sm;
        $endMinutes = $eh * 60 + $em;

        if ($endMinutes <= $startMinutes) {
            return null;
        }

        return $endMinutes - $startMinutes;
    }

    public static function endsAfterStarts(
        \DateTimeImmutable $starts,
        string $endHm,
        \DateTimeZone $tz,
    ): ?\DateTimeImmutable {
        $endParsed = self::parseHmInput($endHm);
        if ($endParsed === null) {
            return null;
        }

        $ends = self::combineLocalDateAndTime($starts, $endParsed . ':00', $tz);
        if ($ends <= $starts) {
            return null;
        }

        return $ends;
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
