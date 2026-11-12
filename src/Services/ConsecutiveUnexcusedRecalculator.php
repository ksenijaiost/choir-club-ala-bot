<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\UserRepository;

final class ConsecutiveUnexcusedRecalculator
{
    public function __construct(
        private readonly AttendanceMarkRepository $marks,
        private readonly UserRepository $users,
    ) {
    }

    public function recalculateForUser(int $userId): int
    {
        $rows = $this->marks->listChronologicalForUser($userId);
        $streak = 0;

        foreach ($rows as $row) {
            $mark = (string) ($row['mark'] ?? '');
            if ($this->resetsStreak($mark)) {
                $streak = 0;
                continue;
            }

            if ($mark === AttendanceMark::UNEXCUSED) {
                $streak++;
            }
        }

        $this->users->setConsecutiveUnexcused($userId, $streak);

        return $streak;
    }

    private function resetsStreak(string $mark): bool
    {
        return $mark === AttendanceMark::ATTENDED
            || $mark === AttendanceMark::WARNED
            || $mark === AttendanceMark::EXCUSED_MANUAL;
    }
}
