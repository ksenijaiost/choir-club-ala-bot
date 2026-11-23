<?php

declare(strict_types=1);

namespace App\Services;

final class PaymentPeriod
{
    /**
     * Период взноса для напоминания 15-го — текущий календарный месяц.
     *
     * @return array{year: int, month: int}
     */
    public static function targetForFirstReminder(\DateTimeImmutable $local): array
    {
        return self::currentMonth($local);
    }

    /**
     * Период взноса для напоминания 20-го — текущий календарный месяц.
     *
     * @return array{year: int, month: int}
     */
    public static function targetForSecondReminder(\DateTimeImmutable $local): array
    {
        return self::currentMonth($local);
    }

    /**
     * @return array{year: int, month: int}
     */
    public static function currentMonth(\DateTimeImmutable $local): array
    {
        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
        ];
    }

    /**
     * @return array{year: int, month: int}
     */
    public static function previousMonth(\DateTimeImmutable $local): array
    {
        $prev = $local->modify('first day of last month');

        return [
            'year' => (int) $prev->format('Y'),
            'month' => (int) $prev->format('n'),
        ];
    }

    public static function periodKey(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }
}
