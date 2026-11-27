<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\StreakMilestones;
use App\Domain\UserRole;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserStreakAwardRepository;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class AttendanceStreakService
{
    public function __construct(
        private readonly AttendanceMarkRepository $marks,
        private readonly UserRepository $users,
        private readonly UserStreakAwardRepository $awards,
        private readonly TextRepository $texts,
        private readonly NotificationRecipientResolver $recipients,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    public function recalculateSilent(int $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || !UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return;
        }

        $computed = AttendanceStreakCalculator::compute($this->marks->listChronologicalForUser($userId));
        $storedBest = (int) ($user['attendance_streak_best'] ?? 0);
        $storedCurrent = (int) ($user['attendance_streak'] ?? 0);
        $best = max($storedBest, $storedCurrent, $computed['best']);
        $this->users->setAttendanceStreak($userId, $computed['current'], $best);
        $this->ensureSilentAwardsUpTo($userId, $best);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onAttended(array $user, bool $notifyMilestones = true): AttendanceMarkResult
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return AttendanceMarkResult::empty();
        }

        $userId = (int) $user['id'];
        $current = (int) ($user['attendance_streak'] ?? 0);
        $best = (int) ($user['attendance_streak_best'] ?? 0);
        $newCurrent = $current + 1;
        $newBest = max($best, $newCurrent);
        $this->users->setAttendanceStreak($userId, $newCurrent, $newBest);

        if ($notifyMilestones) {
            $this->notifyNewMilestones($user, $newCurrent);
        } else {
            $this->ensureSilentAwardsUpTo($userId, $newBest);
        }

        return new AttendanceMarkResult($this->texts->get('streak.line', ['n' => (string) $newCurrent]));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function frozenLineResult(array $user): AttendanceMarkResult
    {
        $current = (int) ($user['attendance_streak'] ?? 0);
        if ($current <= 0) {
            return AttendanceMarkResult::empty();
        }

        return new AttendanceMarkResult($this->texts->get('streak.frozen', ['n' => (string) $current]));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function onUnexcused(array $user): AttendanceMarkResult
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return AttendanceMarkResult::empty();
        }

        $current = (int) ($user['attendance_streak'] ?? 0);
        $best = (int) ($user['attendance_streak_best'] ?? 0);
        $this->users->setAttendanceStreak((int) $user['id'], 0, $best);

        if ($current <= 0) {
            return AttendanceMarkResult::empty();
        }

        return new AttendanceMarkResult($this->texts->get('streak.broken', [
            'n' => (string) $current,
            'best' => (string) $best,
        ]));
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return list<string>
     */
    public function formatProfileBadges(array $user): array
    {
        $lines = [];
        foreach ($this->awards->listMilestonesForUser((int) $user['id']) as $milestone) {
            $lines[] = $this->milestoneLine($milestone);
        }

        return $lines;
    }

    public function milestoneLine(int $milestone): string
    {
        return $this->texts->get('streak.profile_badge_line', [
            'badge' => $this->texts->get(StreakMilestones::badgeKey($milestone)),
            'title' => $this->texts->get(StreakMilestones::textKey($milestone)),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function formatProfileSection(array $user): string
    {
        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            return '';
        }

        $current = (int) ($user['attendance_streak'] ?? 0);
        $best = (int) ($user['attendance_streak_best'] ?? 0);
        $lines = [
            $this->texts->get('streak.profile_line', [
                'current' => (string) $current,
                'best' => (string) $best,
            ]),
        ];

        $badges = $this->formatProfileBadges($user);
        if ($badges === []) {
            $lines[] = $this->texts->get('streak.profile_badges_none');
        } else {
            $lines[] = $this->texts->get('streak.profile_badges_header');
            foreach ($badges as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function notifyNewMilestones(array $user, int $currentStreak): void
    {
        $userId = (int) $user['id'];
        $telegramId = (int) $user['telegram_id'];

        foreach (StreakMilestones::all() as $milestone) {
            if ($milestone !== $currentStreak) {
                continue;
            }
            if (!$this->awards->tryAward($userId, $milestone)) {
                continue;
            }

            $badge = $this->texts->get(StreakMilestones::badgeKey($milestone));
            $title = $this->texts->get(StreakMilestones::textKey($milestone));
            $this->outbound->sendMessage($telegramId, $this->texts->get('streak.milestone_congrats', [
                'badge' => $badge,
                'title' => $title,
                'n' => (string) $milestone,
            ]));

            $adminChat = $this->recipients->resolveOperationalChatId();
            if ($adminChat !== null) {
                $part = VoicePartRules::partLabelForDisplay($this->texts, $user) ?? '—';
                $this->outbound->sendMessage($adminChat, $this->texts->get('streak.admin_milestone', [
                    'name' => (string) ($user['display_name'] ?? ''),
                    'part' => $part,
                    'n' => (string) $milestone,
                    'badge' => $badge,
                    'title' => $title,
                ]));
            }
        }
    }

    private function ensureSilentAwardsUpTo(int $userId, int $bestStreak): void
    {
        foreach (StreakMilestones::all() as $milestone) {
            if ($milestone <= $bestStreak) {
                $this->awards->tryAward($userId, $milestone);
            }
        }
    }
}