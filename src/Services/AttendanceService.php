<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\UserRepository;

final class AttendanceService
{
    public function __construct(
        private readonly AttendanceMarkRepository $marks,
        private readonly UserRepository $users,
        private readonly AttendanceDiscipline $discipline,
        private readonly ConsecutiveUnexcusedRecalculator $recalculator,
    ) {
    }

  /**
   * @param array<string, mixed> $user
   */
    public function recordWarned(array $user, int $rehearsalId, string $source, ?string $reason = null): void
    {
        $userId = (int) $user['id'];
        $this->marks->upsert($rehearsalId, $userId, AttendanceMark::WARNED, $source, $reason);
        $this->users->resetConsecutiveUnexcused($userId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function recordAttended(array $user, int $rehearsalId): void
    {
        $userId = (int) $user['id'];
        $this->marks->upsert($rehearsalId, $userId, AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
        $this->users->resetConsecutiveUnexcused($userId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function recordUnexcused(array $user, int $rehearsalId, string $source): void
    {
        $existing = $this->marks->find($rehearsalId, (int) $user['id']);
        if ($existing !== null) {
            return;
        }

        $this->marks->upsert($rehearsalId, (int) $user['id'], AttendanceMark::UNEXCUSED, $source, null);
        $this->discipline->onUnexcusedRecorded($user);
    }

    public function appendReason(int $rehearsalId, int $userId, string $reason): void
    {
        if ($this->marks->hasMark($rehearsalId, $userId)) {
            $this->marks->appendReason($rehearsalId, $userId, $reason);

            return;
        }

        $this->marks->upsert($rehearsalId, $userId, AttendanceMark::WARNED, AttendanceSource::DAY_BEFORE, $reason);
    }

    public function recordManual(int $userId, int $rehearsalId, string $mark): int
    {
        $this->marks->upsert($rehearsalId, $userId, $mark, AttendanceSource::MANUAL, null);

        return $this->recalculator->recalculateForUser($userId);
    }
}
