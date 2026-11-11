<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RehearsalRepository;
use App\Text\TextRepository;

final class SchedulePresenter
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly TextRepository $texts,
    ) {
    }

    public function buildUpcomingMessage(\DateTimeImmutable $now, \DateTimeZone $tz, int $limit = 15): string
    {
        $from = ScheduleTime::toStorage($now->setTimezone($tz));
        $rows = $this->rehearsals->listFrom($from, $limit);

        if ($rows === []) {
            return $this->texts->get('schedule.empty');
        }

        $lines = [$this->texts->get('schedule.list_header')];

        foreach ($rows as $row) {
            $starts = new \DateTimeImmutable((string) $row['starts_at'], $tz);
            $timeRange = ScheduleTime::formatRehearsalTimeRange($row, $tz);
            $isCancelled = ($row['status'] ?? '') === 'cancelled';

            if ($isCancelled) {
                $lines[] = $this->texts->get('schedule.item_cancelled', [
                    'date' => ScheduleTime::formatLocalDate($starts, $tz),
                    'time' => $timeRange,
                    'address' => (string) $row['address'],
                ]);
            } else {
                $lines[] = $this->texts->get('schedule.item', [
                    'date' => ScheduleTime::formatLocalDate($starts, $tz),
                    'time' => $timeRange,
                    'address' => (string) $row['address'],
                ]);
            }
        }

        return implode("\n", $lines);
    }
}
