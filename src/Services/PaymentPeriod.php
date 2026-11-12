<?php

declare(strict_types=1);

namespace App\Services;

final class PaymentPeriod
{
    /**
     * Период взноса для напоминания 23-го — следующий календарный месяц.
     *
     * @return array{year: int, month: int}
     */
    public static function targetForFirstReminder(\DateTimeImmutable $local): array
    {
        $next = $local->modify('first day of next month');

        return [
            'year' => (int) $next->format('Y'),
            'month' => (int) $next->format('n'),
        ];
    }

    /**
     * Период взноса для напоминания 1-го — текущий календарный месяц.
     *
     * @return array{year: int, month: int}
     */
    public static function targetForSecondReminder(\DateTimeImmutable $local): array
    {
        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
        ];
    }

    public static function periodKey(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }
}
