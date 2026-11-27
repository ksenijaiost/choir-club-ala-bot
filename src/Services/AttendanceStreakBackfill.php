<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\StreakMilestones;
use App\Domain\UserRole;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserStreakAwardRepository;

/**
 * Пересчёт серий по истории без уведомлений (после миграции 024).
 */
final class AttendanceStreakBackfill
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AttendanceMarkRepository $marks,
        private readonly UserStreakAwardRepository $awards,
    ) {
    }

    public function runSilent(): void
    {
        foreach ($this->users->listAttendanceParticipants() as $user) {
            $userId = (int) $user['id'];
            if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
                continue;
            }

            $fresh = $this->users->findById($userId) ?? $user;
            $computed = AttendanceStreakCalculator::compute($this->marks->listChronologicalForUser($userId));
            $best = max((int) ($fresh['attendance_streak_best'] ?? 0), $computed['best']);
            $this->users->setAttendanceStreak($userId, $computed['current'], $best);

            foreach (StreakMilestones::all() as $milestone) {
                if ($milestone <= $best) {
                    $this->awards->tryAward($userId, $milestone);
                }
            }
        }
    }
}