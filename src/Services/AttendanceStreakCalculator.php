<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;

final class AttendanceStreakCalculator
{
    /**
     * @param list<array<string, mixed>> $chronologicalMarks
     *
     * @return array{current: int, best: int}
     */
    public static function compute(array $chronologicalMarks): array
    {
        $current = 0;
        $best = 0;

        foreach ($chronologicalMarks as $row) {
            $mark = (string) ($row['mark'] ?? '');
            if ($mark === AttendanceMark::ATTENDED) {
                $current++;
                if ($current > $best) {
                    $best = $current;
                }
                continue;
            }

            if ($mark === AttendanceMark::WARNED || $mark === AttendanceMark::EXCUSED_MANUAL) {
                continue;
            }

            if ($mark === AttendanceMark::UNEXCUSED) {
                $current = 0;
            }
        }

        return ['current' => $current, 'best' => $best];
    }
}