<?php

declare(strict_types=1);

namespace App\Services;

use App\Clock;
use App\Config;
use App\Domain\SecretAchievements;
use App\Domain\StreakMilestones;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserAchievementRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserStreakAwardRepository;
use App\Text\TextRepository;

final class ChoirWrappedService
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly AttendanceMarkRepository $marks,
        private readonly UserStreakAwardRepository $streakAwards,
        private readonly UserAchievementRepository $achievements,
        private readonly ProgramRepository $programs,
        private readonly PartBattleService $partBattle,
        private readonly TextRepository $texts,
        private readonly PaymentTexts $paymentTexts,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function buildMonthlyMemberSummary(array $user, Config $config, int $year, int $month): array
    {
        [$rangeStart, $rangeEnd, $rehearsalIds, $rehearsalCount] = $this->monthRehearsals($config, $year, $month);
        $userId = (int) $user['id'];
        $attended = $rehearsalCount > 0
            ? $this->marks->countAttendedAtRehearsalsForUsers($rehearsalIds, [$userId])
            : 0;
        $percent = $rehearsalCount > 0 ? (int) round($attended / $rehearsalCount * 100) : 0;

        $awardBounds = $this->awardBoundsStorage($rangeStart, $rangeEnd);

        return [
            'year' => $year,
            'month' => $month,
            'rehearsal_count' => $rehearsalCount,
            'attended' => $attended,
            'percent' => $percent,
            'streak_current' => (int) ($user['attendance_streak'] ?? 0),
            'streak_best' => (int) ($user['attendance_streak_best'] ?? 0),
            'milestones' => $this->streakAwards->listMilestonesAwardedBetween($userId, $awardBounds[0], $awardBounds[1]),
            'secret_achievements' => $this->achievements->listCodesAwardedBetween($userId, $awardBounds[0], $awardBounds[1]),
            'part_battle' => $this->partBattle->buildForMonth($config, $year, $month),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function buildPeriodMemberSummary(
        array $user,
        Config $config,
        int $fromYear,
        int $fromMonth,
        int $toYear,
        int $toMonth,
    ): array {
        [$rangeStart, $rangeEnd, $rehearsalIds, $rehearsalCount] = $this->periodRange($config, $fromYear, $fromMonth, $toYear, $toMonth);
        $userId = (int) $user['id'];
        $attended = $rehearsalCount > 0
            ? $this->marks->countAttendedAtRehearsalsForUsers($rehearsalIds, [$userId])
            : 0;
        $percent = $rehearsalCount > 0 ? (int) round($attended / $rehearsalCount * 100) : 0;

        $awardBounds = $this->awardBoundsStorage($rangeStart, $rangeEnd);
        $fromIso = $rangeStart->format('Y-m-d');
        $toIso = $rangeEnd->format('Y-m-d');

        return [
            'from_year' => $fromYear,
            'from_month' => $fromMonth,
            'to_year' => $toYear,
            'to_month' => $toMonth,
            'rehearsal_count' => $rehearsalCount,
            'attended' => $attended,
            'percent' => $percent,
            'streak_best' => (int) ($user['attendance_streak_best'] ?? 0),
            'milestones' => $this->streakAwards->listMilestonesAwardedBetween($userId, $awardBounds[0], $awardBounds[1]),
            'secret_achievements' => $this->achievements->listCodesAwardedBetween($userId, $awardBounds[0], $awardBounds[1]),
            'programs' => $this->programs->listByEventDateBetween($fromIso, $toIso),
            'part_battle' => $this->partBattle->buildForLocalRange($config, $rangeStart, $rangeEnd),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPeriodAdminSummary(
        Config $config,
        int $fromYear,
        int $fromMonth,
        int $toYear,
        int $toMonth,
    ): array {
        [$rangeStart, $rangeEnd, $rehearsalIds, $rehearsalCount] = $this->periodRange($config, $fromYear, $fromMonth, $toYear, $toMonth);

        $totalSlots = 0;
        $totalAttended = 0;
        $userScores = [];

        foreach ($this->users->listAttendanceParticipants() as $participant) {
            if (!VoicePartRules::hasAssignedVoicePart($participant)) {
                continue;
            }
            $uid = (int) $participant['id'];
            $attended = $rehearsalCount > 0
                ? $this->marks->countAttendedAtRehearsalsForUsers($rehearsalIds, [$uid])
                : 0;
            $totalSlots += $rehearsalCount;
            $totalAttended += $attended;
            $userScores[] = [
                'name' => (string) ($participant['display_name'] ?? ''),
                'percent' => $rehearsalCount > 0 ? (int) round($attended / $rehearsalCount * 100) : 0,
                'attended' => $attended,
            ];
        }

        usort($userScores, static fn (array $a, array $b): int => $b['percent'] <=> $a['percent'] ?: $b['attended'] <=> $a['attended']);

        $avgPercent = $totalSlots > 0 ? (int) round($totalAttended / $totalSlots * 100) : 0;
        $partBattle = $this->partBattle->buildForLocalRange($config, $rangeStart, $rangeEnd);

        return [
            'rehearsal_count' => $rehearsalCount,
            'avg_percent' => $avgPercent,
            'part_battle' => $partBattle,
            'top3' => array_slice($userScores, 0, 3),
            'from_year' => $fromYear,
            'from_month' => $fromMonth,
            'to_year' => $toYear,
            'to_month' => $toMonth,
        ];
    }

    /**
     * @param array<string, mixed> $summary from buildMonthlyMemberSummary
     */
    public function formatMonthlyMemberMessage(array $summary): string
    {
        $ph = $this->paymentTexts->monthYearPlaceholders((int) $summary['year'], (int) $summary['month']);
        $lines = [$this->texts->get('wrapped.monthly_header', $ph)];

        if ((int) $summary['rehearsal_count'] === 0) {
            $lines[] = $this->texts->get('wrapped.monthly_no_rehearsals', $ph);

            return implode("\n", $lines);
        }

        $lines[] = $this->texts->get('wrapped.monthly_attendance', array_merge($ph, [
            'attended' => (string) $summary['attended'],
            'total' => (string) $summary['rehearsal_count'],
            'percent' => (string) $summary['percent'],
        ]));
        $lines[] = $this->texts->get('wrapped.monthly_streak', [
            'current' => (string) $summary['streak_current'],
            'best' => (string) $summary['streak_best'],
        ]);

        $lines = array_merge($lines, $this->formatAwardsLines(
            $summary['milestones'],
            $summary['secret_achievements'],
        ));

        $battle = $summary['part_battle'];
        if ($battle->hasRehearsals() && $battle->winners !== []) {
            $winnerLabels = array_map(
                fn (string $code): string => $this->texts->get('parts.' . $code),
                $battle->winners,
            );
            $lines[] = $this->texts->get('wrapped.monthly_part_battle', [
                'parts' => implode(', ', $winnerLabels),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function formatPeriodMemberMessage(array $summary): string
    {
        $lines = [$this->texts->get('wrapped.period_member_header', $this->periodPlaceholders($summary))];

        if ((int) $summary['rehearsal_count'] === 0) {
            $lines[] = $this->texts->get('wrapped.period_no_rehearsals');

            return implode("\n", $lines);
        }

        $lines[] = $this->texts->get('wrapped.period_attendance', [
            'attended' => (string) $summary['attended'],
            'total' => (string) $summary['rehearsal_count'],
            'percent' => (string) $summary['percent'],
        ]);
        $lines[] = $this->texts->get('wrapped.period_streak_best', [
            'best' => (string) $summary['streak_best'],
        ]);

        $lines = array_merge($lines, $this->formatAwardsLines(
            $summary['milestones'],
            $summary['secret_achievements'],
        ));

        /** @var list<array<string, mixed>> $programs */
        $programs = $summary['programs'];
        if ($programs !== []) {
            $lines[] = $this->texts->get('wrapped.period_programs_header');
            foreach ($programs as $program) {
                $lines[] = $this->texts->get('wrapped.period_program_line', [
                    'title' => (string) ($program['title'] ?? ''),
                    'date' => (string) ($program['event_date'] ?? ''),
                    'songs' => (string) ((int) ($program['song_count'] ?? 0)),
                ]);
            }
        }

        $battle = $summary['part_battle'];
        if ($battle->hasRehearsals() && $battle->winners !== []) {
            $winnerLabels = array_map(
                fn (string $code): string => $this->texts->get('parts.' . $code),
                $battle->winners,
            );
            $lines[] = $this->texts->get('wrapped.period_part_battle', [
                'parts' => implode(', ', $winnerLabels),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function formatPeriodAdminMessage(array $summary): string
    {
        $ph = $this->periodPlaceholders($summary);
        $lines = [$this->texts->get('wrapped.admin_header', $ph)];
        $lines[] = $this->texts->get('wrapped.admin_rehearsals', [
            'count' => (string) $summary['rehearsal_count'],
        ]);
        $lines[] = $this->texts->get('wrapped.admin_avg', [
            'percent' => (string) $summary['avg_percent'],
        ]);

        $battle = $summary['part_battle'];
        if ($battle->hasRehearsals() && $battle->winners !== []) {
            $winnerLabels = array_map(
                fn (string $code): string => $this->texts->get('parts.' . $code),
                $battle->winners,
            );
            $lines[] = $this->texts->get('wrapped.admin_best_part', [
                'parts' => implode(', ', $winnerLabels),
            ]);
        }

        /** @var list<array{name: string, percent: int}> $top3 */
        $top3 = $summary['top3'];
        if ($top3 !== []) {
            $lines[] = $this->texts->get('wrapped.admin_top_header');
            $rank = 1;
            foreach ($top3 as $row) {
                $lines[] = $this->texts->get('wrapped.admin_top_line', [
                    'rank' => (string) $rank,
                    'name' => $row['name'],
                    'percent' => (string) $row['percent'],
                ]);
                $rank++;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{year: int, month: int}
     */
    public function previousCalendarMonth(Config $config): array
    {
        return $this->partBattle->previousCalendarMonth($config);
    }

    /**
     * @return array{year: int, month: int}
     */
    public function currentCalendarMonth(Config $config): array
    {
        return $this->partBattle->currentCalendarMonth($config);
    }

    public function monthHasRehearsals(Config $config, int $year, int $month): bool
    {
        [, , , $count] = $this->monthRehearsals($config, $year, $month);

        return $count > 0;
    }

    /**
     * @return array{year: int, month: int, year2: int, month2: int}
     */
    public function presetPeriod(Config $config, string $preset): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $now = Clock::now($config)->setTimezone($tz);
        $toYear = (int) $now->format('Y');
        $toMonth = (int) $now->format('n');

        return match ($preset) {
            '3' => $this->monthsBack($now, 2, $toYear, $toMonth),
            '6' => $this->monthsBack($now, 5, $toYear, $toMonth),
            'y' => [
                'year' => (int) $now->format('Y'),
                'month' => 1,
                'year2' => $toYear,
                'month2' => $toMonth,
            ],
            default => $this->monthsBack($now, 2, $toYear, $toMonth),
        };
    }

    /**
     * @param list<int> $milestones
     * @param list<string> $secretCodes
     * @return list<string>
     */
    private function formatAwardsLines(array $milestones, array $secretCodes): array
    {
        $lines = [];
        if ($milestones !== []) {
            $parts = [];
            foreach ($milestones as $milestone) {
                $parts[] = $this->texts->get(StreakMilestones::badgeKey($milestone))
                    . ' ' . $this->texts->get(StreakMilestones::textKey($milestone));
            }
            $lines[] = $this->texts->get('wrapped.milestones_line', [
                'list' => implode(', ', $parts),
            ]);
        }
        if ($secretCodes !== []) {
            $parts = [];
            foreach ($secretCodes as $code) {
                $parts[] = $this->texts->get(SecretAchievements::badgeKey($code))
                    . ' ' . $this->texts->get(SecretAchievements::titleKey($code));
            }
            $lines[] = $this->texts->get('wrapped.achievements_line', [
                'list' => implode(', ', $parts),
            ]);
        }

        return $lines;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: list<int>, 3: int}
     */
    private function monthRehearsals(Config $config, int $year, int $month): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $rangeStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $rangeEnd = $rangeStart->modify('last day of this month')->setTime(23, 59, 59);

        return $this->rehearsalsInRange($config, $rangeStart, $rangeEnd);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: list<int>, 3: int}
     */
    private function periodRange(
        Config $config,
        int $fromYear,
        int $fromMonth,
        int $toYear,
        int $toMonth,
    ): array {
        $tz = new \DateTimeZone($config->timezone);
        $rangeStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $fromYear, $fromMonth), $tz);
        $rangeEnd = (new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $toYear, $toMonth), $tz))
            ->modify('last day of this month')
            ->setTime(23, 59, 59);

        return $this->rehearsalsInRange($config, $rangeStart, $rangeEnd);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: list<int>, 3: int}
     */
    private function rehearsalsInRange(
        Config $config,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
    ): array {
        $now = Clock::now($config)->setTimezone($rangeStart->getTimezone());
        $pivot = ScheduleTime::toStorage($now);
        $rows = $this->rehearsals->listPastBetween(
            ScheduleTime::toStorage($rangeStart),
            ScheduleTime::toStorage($rangeEnd),
            $pivot,
        );
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        return [$rangeStart, $rangeEnd, $ids, count($ids)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function awardBoundsStorage(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return [ScheduleTime::toStorage($start), ScheduleTime::toStorage($end)];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, string>
     */
    private function periodPlaceholders(array $summary): array
    {
        return [
            'from_month' => $this->paymentTexts->monthName((int) $summary['from_month']),
            'from_year' => (string) $summary['from_year'],
            'to_month' => $this->paymentTexts->monthName((int) $summary['to_month']),
            'to_year' => (string) $summary['to_year'],
        ];
    }

    /**
     * @return array{year: int, month: int, year2: int, month2: int}
     */
    private function monthsBack(
        \DateTimeImmutable $now,
        int $back,
        int $toYear,
        int $toMonth,
    ): array {
        $start = $now->modify('first day of this month')->modify(sprintf('-%d months', $back));

        return [
            'year' => (int) $start->format('Y'),
            'month' => (int) $start->format('n'),
            'year2' => $toYear,
            'month2' => $toMonth,
        ];
    }
}