<?php

declare(strict_types=1);

namespace App\Services;

use App\Clock;
use App\Config;
use App\Domain\AttendanceMark;
use App\Domain\VoicePart;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Text\TextRepository;

final class PartBattleService
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly AttendanceMarkRepository $marks,
        private readonly TextRepository $texts,
        private readonly PaymentTexts $paymentTexts,
    ) {
    }

    public function buildForMonth(Config $config, int $year, int $month): PartBattleMonthResult
    {
        $tz = new \DateTimeZone($config->timezone);
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        return $this->buildForLocalRange($config, $monthStart, $monthEnd, $year, $month);
    }

    public function buildForLocalRange(
        Config $config,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?int $labelYear = null,
        ?int $labelMonth = null,
    ): PartBattleMonthResult {
        $tz = new \DateTimeZone($config->timezone);
        $now = Clock::now($config)->setTimezone($tz);
        $pivotLocal = ScheduleTime::toStorage($now);

        $rehearsalRows = $this->rehearsals->listPastBetween(
            ScheduleTime::toStorage($rangeStart),
            ScheduleTime::toStorage($rangeEnd),
            $pivotLocal,
        );
        $rehearsalIds = array_map(static fn (array $row): int => (int) $row['id'], $rehearsalRows);
        $rehearsalCount = count($rehearsalIds);

        $labelYear ??= (int) $rangeEnd->format('Y');
        $labelMonth ??= (int) $rangeEnd->format('n');

        $byPart = [];
        foreach ($this->users->listAttendanceParticipants() as $user) {
            if (!VoicePartRules::hasAssignedVoicePart($user)) {
                continue;
            }
            $part = (string) $user['voice_part'];
            $byPart[$part] ??= [];
            $byPart[$part][] = (int) $user['id'];
        }

        $scored = [];
        foreach (VoicePart::CODES as $part) {
            $memberIds = $byPart[$part] ?? [];
            $memberCount = count($memberIds);
            if ($memberCount === 0 || $rehearsalCount === 0) {
                if ($memberCount > 0 && $rehearsalCount > 0) {
                    $scored[] = [
                        'part' => $part,
                        'member_count' => $memberCount,
                        'attended' => 0,
                        'total' => $rehearsalCount * $memberCount,
                        'percent' => 0,
                    ];
                }

                continue;
            }

            $total = $rehearsalCount * $memberCount;
            $attended = $this->marks->countAttendedAtRehearsalsForUsers($rehearsalIds, $memberIds);
            $percent = (int) round($attended / $total * 100);
            $scored[] = [
                'part' => $part,
                'member_count' => $memberCount,
                'attended' => $attended,
                'total' => $total,
                'percent' => $percent,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['percent'] !== $b['percent']) {
                return $b['percent'] <=> $a['percent'];
            }
            if ($a['attended'] !== $b['attended']) {
                return $b['attended'] <=> $a['attended'];
            }
            $ia = array_search($a['part'], VoicePart::CODES, true);
            $ib = array_search($b['part'], VoicePart::CODES, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib;
        });

        $rank = 0;
        $rows = [];
        foreach ($scored as $row) {
            $rank++;
            $row['rank'] = $rank;
            $rows[] = $row;
        }

        $winners = [];
        if ($rehearsalCount > 0 && $rows !== []) {
            $topPercent = $rows[0]['percent'];
            $topAttended = $rows[0]['attended'];
            foreach ($rows as $row) {
                if ($row['percent'] === $topPercent && $row['attended'] === $topAttended) {
                    $winners[] = $row['part'];
                }
            }
        }

        return new PartBattleMonthResult($labelYear, $labelMonth, $rehearsalCount, $rows, $winners);
    }

    /**
     * @return array{year: int, month: int}
     */
    public function previousCalendarMonth(Config $config): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $local = Clock::now($config)->setTimezone($tz)->modify('first day of this month')->modify('-1 day');

        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
        ];
    }

    /**
     * @return array{year: int, month: int}
     */
    public function currentCalendarMonth(Config $config): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $local = Clock::now($config)->setTimezone($tz);

        return [
            'year' => (int) $local->format('Y'),
            'month' => (int) $local->format('n'),
        ];
    }

    public function formatAdminMessage(PartBattleMonthResult $result): string
    {
        $ph = $this->paymentTexts->monthYearPlaceholders($result->year, $result->month);
        if (!$result->hasRehearsals()) {
            return $this->texts->get('part_battle.no_rehearsals', $ph);
        }

        $lines = [$this->texts->get('part_battle.header', $ph)];
        foreach ($result->rows as $row) {
            $lines[] = $this->formatTableLine($row);
        }

        return implode("\n", $lines);
    }

    public function formatCronMessage(PartBattleMonthResult $result): string
    {
        $ph = $this->paymentTexts->monthYearPlaceholders($result->year, $result->month);
        $lines = [$this->texts->get('part_battle.cron_header', $ph)];

        if ($result->winners === []) {
            return implode("\n", $lines);
        }

        $winnerLabels = array_map(
            fn (string $part): string => $this->texts->get('parts.' . $part),
            $result->winners,
        );
        $lines[] = count($winnerLabels) === 1
            ? $this->texts->get('part_battle.cron_winner', [
                'part' => $winnerLabels[0],
                'percent' => (string) ($result->rows[0]['percent'] ?? 0),
            ])
            : $this->texts->get('part_battle.cron_winners_tie', [
                'parts' => implode(', ', $winnerLabels),
                'percent' => (string) ($result->rows[0]['percent'] ?? 0),
            ]);

        foreach ($result->rows as $row) {
            $lines[] = $this->formatTableLine($row);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function formatProfileLine(array $user, Config $config): string
    {
        if (!VoicePartRules::hasAssignedVoicePart($user)) {
            return '';
        }

        $prev = $this->previousCalendarMonth($config);
        $result = $this->buildForMonth($config, $prev['year'], $prev['month']);
        $part = (string) $user['voice_part'];
        if (!in_array($part, $result->winners, true)) {
            return '';
        }

        $row = null;
        foreach ($result->rows as $candidate) {
            if ($candidate['part'] === $part) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return '';
        }

        $ph = $this->paymentTexts->monthYearPlaceholders($prev['year'], $prev['month']);

        return $this->texts->get('profile.part_battle_win', array_merge($ph, [
            'part' => $this->texts->get('parts.' . $part),
            'percent' => (string) $row['percent'],
        ]));
    }

    /**
     * @param array{part: string, attended: int, total: int, percent: int, rank: int} $row
     */
    private function formatTableLine(array $row): string
    {
        $medal = match ($row['rank']) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => (string) $row['rank'] . '.',
        };

        return $this->texts->get('part_battle.row', [
            'medal' => $medal,
            'part' => $this->texts->get('parts.' . $row['part']),
            'percent' => (string) $row['percent'],
            'attended' => (string) $row['attended'],
            'total' => (string) $row['total'],
        ]);
    }
}