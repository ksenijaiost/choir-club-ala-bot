<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;
use App\Text\TextRepository;

final class AttendanceHistoryPresenter
{
    public function __construct(private readonly TextRepository $texts)
    {
    }

    public function markLabel(?string $mark): string
    {
        if ($mark === null || $mark === '') {
            return $this->texts->get('attendance.no_mark');
        }

        return match ($mark) {
            AttendanceMark::ATTENDED => $this->texts->get('attendance.mark_attended'),
            AttendanceMark::WARNED => $this->texts->get('attendance.mark_warned'),
            AttendanceMark::UNEXCUSED => $this->texts->get('attendance.mark_unexcused'),
            AttendanceMark::EXCUSED_MANUAL => $this->texts->get('attendance.mark_excused_manual'),
            default => $mark,
        };
    }

    /**
     * @param array<string, mixed> $rehearsalRow with starts_at, ends_at
     */
    public function formatRehearsalLine(array $rehearsalRow, \DateTimeZone $tz, ?string $mark): string
    {
        $starts = new \DateTimeImmutable((string) $rehearsalRow['starts_at'], $tz);

        return $this->texts->get('attendance.history_line', [
            'date' => ScheduleTime::formatLocalDate($starts, $tz),
            'time' => ScheduleTime::formatRehearsalTimeRange($rehearsalRow, $tz),
            'mark' => $this->markLabel($mark),
        ]);
    }

    /**
     * @param array<string, mixed> $markRow with mark, reason_text, starts_at, ends_at
     */
    public function formatAdminMemberLine(array $markRow, \DateTimeZone $tz): string
    {
        $mark = (string) ($markRow['mark'] ?? '');
        $reason = trim((string) ($markRow['reason_text'] ?? ''));
        $base = $this->formatRehearsalLine($markRow, $tz, $mark);

        if ($mark === AttendanceMark::WARNED && $reason !== '') {
            return $this->texts->get('history.warned_with_reason', [
                'line' => $base,
                'reason' => $reason,
            ]);
        }

        if ($mark === AttendanceMark::UNEXCUSED && $reason === '') {
            return $this->texts->get('history.unexcused_no_reason', ['line' => $base]);
        }

        return $base;
    }
}
