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

    /**
     * @return array{year: int, month: int}
     */
    public static function nextMonth(int $year, int $month): array
    {
        $dt = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('first day of next month');

        return [
            'year' => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
        ];
    }

    /**
     * @param array{year: int, month: int} $a
     * @param array{year: int, month: int} $b
     */
    public static function compare(array $a, array $b): int
    {
        if ($a['year'] !== $b['year']) {
            return $a['year'] <=> $b['year'];
        }

        return $a['month'] <=> $b['month'];
    }

    /**
     * @param array{year: int, month: int} $start
     * @param array{year: int, month: int} $end
     *
     * @return list<array{year: int, month: int}>
     */
    public static function monthsInclusive(array $start, array $end): array
    {
        if (self::compare($start, $end) > 0) {
            return [];
        }

        $months = [];
        $cursor = $start;
        while (self::compare($cursor, $end) <= 0) {
            $months[] = $cursor;
            $cursor = self::nextMonth($cursor['year'], $cursor['month']);
        }

        return $months;
    }

    /**
     * Окно из 12 месяцев, заканчивающееся месяцем «текущий минус offset».
     *
     * @return list<array{year: int, month: int}>
     */
    public static function monthWindowEndingAt(\DateTimeImmutable $local, int $offsetMonths): array
    {
        $end = $local->modify('first day of this month')->modify('-' . $offsetMonths . ' months');
        $months = [];
        $cursor = $end->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $months[] = [
                'year' => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        return $months;
    }
}
