<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\UserRole;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\UserRepository;

final class AttendanceService
{
    public function __construct(
        private readonly AttendanceMarkRepository $marks,
        private readonly UserRepository $users,
        private readonly AttendanceDiscipline $discipline,
        private readonly ConsecutiveUnexcusedRecalculator $recalculator,
        private readonly AttendanceStreakService $streak,
        private readonly ?AchievementService $achievements = null,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function recordWarned(array $user, int $rehearsalId, string $source, ?string $reason = null): AttendanceMarkResult
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return AttendanceMarkResult::empty();
        }

        $userId = (int) $user['id'];
        $existing = $this->marks->find($rehearsalId, $userId);
        $priorMark = $existing === null ? null : (string) ($existing['mark'] ?? '');

        $this->marks->upsert($rehearsalId, $userId, AttendanceMark::WARNED, $source, $reason);
        $this->users->resetConsecutiveUnexcused($userId);

        if ($priorMark !== null && $priorMark !== AttendanceMark::WARNED) {
            $this->streak->recalculateSilent($userId);
            $user = $this->users->findById($userId) ?? $user;
        }

        if ($this->achievements !== null) {
            $this->achievements->onWontComeWarned($user, $rehearsalId, $source);
        }

        return $this->streak->frozenLineResult($user);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function recordAttended(array $user, int $rehearsalId): AttendanceMarkResult
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return AttendanceMarkResult::empty();
        }

        $userId = (int) $user['id'];
        $this->marks->upsert($rehearsalId, $userId, AttendanceMark::ATTENDED, AttendanceSource::CHECKIN, null);
        $this->users->resetConsecutiveUnexcused($userId);

        $result = $this->streak->onAttended($user);
        if ($this->achievements !== null) {
            $this->achievements->onAttendedCheckIn($user, $rehearsalId);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function recordUnexcused(array $user, int $rehearsalId, string $source): AttendanceMarkResult
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return AttendanceMarkResult::empty();
        }

        $existing = $this->marks->find($rehearsalId, (int) $user['id']);
        if ($existing !== null) {
            return AttendanceMarkResult::empty();
        }

        $streakResult = $this->streak->onUnexcused($user);

        $this->marks->upsert($rehearsalId, (int) $user['id'], AttendanceMark::UNEXCUSED, $source, null);
        $disciplineSent = $this->discipline->onUnexcusedRecorded($user, $streakResult->appendLine);

        return new AttendanceMarkResult(
            $disciplineSent ? null : $streakResult->appendLine,
        );
    }

    public function appendReason(int $rehearsalId, int $userId, string $reason): void
    {
        if ($this->marks->hasMark($rehearsalId, $userId)) {
            $this->marks->appendReason($rehearsalId, $userId, $reason);

            return;
        }

        $user = $this->users->findById($userId);
        if ($user !== null) {
            $this->recordWarned($user, $rehearsalId, AttendanceSource::DAY_BEFORE, $reason);
        } else {
            $this->marks->upsert($rehearsalId, $userId, AttendanceMark::WARNED, AttendanceSource::DAY_BEFORE, $reason);
        }
    }

    public function recordManual(int $userId, int $rehearsalId, string $mark): int
    {
        $this->marks->upsert($rehearsalId, $userId, $mark, AttendanceSource::MANUAL, null);
        $unexcused = $this->recalculator->recalculateForUser($userId);
        $this->streak->recalculateSilent($userId);

        return $unexcused;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function clearPlannedAbsence(array $user, int $rehearsalId): void
    {
        $userId = (int) $user['id'];
        $this->marks->delete($rehearsalId, $userId);
        $this->users->resetConsecutiveUnexcused($userId);
        $this->streak->recalculateSilent($userId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function clearCheckinMark(array $user, int $rehearsalId): bool
    {
        $userId = (int) $user['id'];
        $existing = $this->marks->find($rehearsalId, $userId);
        if ($existing === null) {
            return true;
        }

        $source = (string) ($existing['source'] ?? '');
        if ($source === AttendanceSource::MANUAL) {
            return false;
        }

        if ($source !== AttendanceSource::CHECKIN) {
            return false;
        }

        $this->marks->delete($rehearsalId, $userId);
        $this->recalculator->recalculateForUser($userId);
        $this->streak->recalculateSilent($userId);

        return true;
    }
}