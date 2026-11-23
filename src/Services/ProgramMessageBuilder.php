<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProgramRepository;
use App\Text\TextRepository;

final class ProgramMessageBuilder
{
    public function __construct(
        private readonly TextRepository $texts,
        private readonly ProgramRepository $programs,
    ) {
    }

      /**
     * @param array<string, mixed> $program
     */
    public function notifyBody(array $program, bool $isUpdate): string
    {
        $title = (string) ($program['title'] ?? '');
        $dateSuffix = $this->dateSuffix($program);
        $headerKey = $isUpdate ? 'programs.notify_header_updated' : 'programs.notify_header_new';
        $lines = [$this->texts->get($headerKey, [
            'title' => $title,
            'date_suffix' => $dateSuffix,
        ])];

        $songs = $this->programs->getSongs((int) $program['id']);
        $n = 1;
        foreach ($songs as $row) {
            $lines[] = $n . '. ' . (string) ($row['title'] ?? '');
            $n++;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $program
     */
    public function formatDateLine(array $program): string
    {
        $eventDate = $program['event_date'] ?? null;
        if ($eventDate === null || $eventDate === '') {
            return $this->texts->get('programs.date_none');
        }

        $parts = explode('-', (string) $eventDate);
        if (count($parts) !== 3) {
            return (string) $eventDate;
        }

        return $this->texts->get('programs.date_display', [
            'day' => ltrim($parts[2], '0'),
            'month' => ltrim($parts[1], '0'),
            'year' => $parts[0],
        ]);
    }

    /**
     * @param array<string, mixed> $program
     */
    private function dateSuffix(array $program): string
    {
        $eventDate = $program['event_date'] ?? null;
        if ($eventDate === null || $eventDate === '') {
            return '';
        }

        return $this->texts->get('programs.date_title_suffix', [
            'date' => $this->formatDateLine($program),
        ]);
    }

    /**
     * @param array<string, mixed> $program
     */
    public function listButtonDate(array $program): string
    {
        $eventDate = $program['event_date'] ?? null;
        if ($eventDate === null || $eventDate === '') {
            return $this->texts->get('programs.list_date_none');
        }

        return $this->formatDateLine($program);
    }
}