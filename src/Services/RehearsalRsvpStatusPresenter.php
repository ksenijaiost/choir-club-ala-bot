<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;
use App\Domain\UserRole;
use App\Domain\VoicePart;
use App\Text\TextRepository;

final class RehearsalRsvpStatusPresenter
{
    public function __construct(private readonly TextRepository $texts)
    {
    }

    /**
     * @param array<string, mixed> $rehearsal
     */
    public function headerText(array $rehearsal, \DateTimeZone $tz): string
    {
        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);

        return $this->texts->get('rsvp.header', [
            'date' => ScheduleTime::formatLocalDate($starts, $tz),
            'time' => ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz),
            'address' => (string) $rehearsal['address'],
        ]);
    }

    /**
     * @param array{
     *   reminder_started: bool,
     *   yes: list<array<string, mixed>>,
     *   no: list<array<string, mixed>>,
     *   pending: list<array<string, mixed>>,
     *   undelivered: list<array<string, mixed>>,
     *   early_absent: list<array<string, mixed>>,
     * } $lists
     */
    public function buildBody(array $lists): string
    {
        $lines = [];

        if (!$lists['reminder_started']) {
            $lines[] = $this->texts->get('rsvp.reminder_not_sent');
            if ($lists['early_absent'] !== []) {
                $lines[] = '';
                $lines[] = $this->texts->get('rsvp.section_early_absent');
                $lines = array_merge($lines, $this->formatSortedMemberLines($lists['early_absent'], true));
            }

            return implode("\n", $lines);
        }

        $sections = [
            ['rsvp.section_yes', $lists['yes'], false],
            ['rsvp.section_no', $lists['no'], true],
            ['rsvp.section_pending', $lists['pending'], false],
            ['rsvp.section_undelivered', $lists['undelivered'], false],
        ];

        $first = true;
        foreach ($sections as [$key, $items, $withNoDetail]) {
            if ($items === []) {
                continue;
            }
            if (!$first) {
                $lines[] = '';
            }
            $first = false;
            $lines[] = $this->texts->get($key);
            $lines = array_merge($lines, $this->formatSortedMemberLines($items, $withNoDetail));
        }

        if ($lists['early_absent'] !== []) {
            $lines[] = '';
            $lines[] = $this->texts->get('rsvp.section_early_absent');
            $lines = array_merge($lines, $this->formatSortedMemberLines($lists['early_absent'], true));
        }

        if ($lines === []) {
            return $this->texts->get('rsvp.empty');
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $entries with user, mark, rsvp_answer
     * @return list<string>
     */
    private function formatSortedMemberLines(array $entries, bool $withNoDetail): array
    {
        $sorted = $this->sortByVoiceParts($entries);
        $lines = [];
        foreach ($sorted as $entry) {
            $lines[] = $this->formatMemberLine($entry, $withNoDetail);
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function sortByVoiceParts(array $entries): array
    {
        $conductor = [];
        $byPart = [];
        foreach (VoicePart::CODES as $code) {
            $byPart[$code] = [];
        }
        $noPart = [];

        foreach ($entries as $entry) {
            $user = $entry['user'];
            if (($user['role'] ?? '') === UserRole::CONDUCTOR) {
                $conductor[] = $entry;

                continue;
            }

            $part = (string) ($user['voice_part'] ?? '');
            if ($part === '' || !VoicePart::isValid($part)) {
                $noPart[] = $entry;

                continue;
            }

            $byPart[$part][] = $entry;
        }

        $sorted = $conductor;
        foreach (VoicePart::CODES as $code) {
            $sorted = array_merge($sorted, $byPart[$code]);
        }

        return array_merge($sorted, $noPart);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatMemberLine(array $entry, bool $withNoDetail): string
    {
        $user = $entry['user'];
        $name = (string) ($user['display_name'] ?? '');
        $mark = $entry['mark'];

        if (!$withNoDetail) {
            return $this->texts->get('rsvp.member_line', ['name' => $name]);
        }

        $suffix = $this->noLineSuffix($mark);

        return $this->texts->get('rsvp.member_line_detail', [
            'name' => $name,
            'detail' => $suffix,
        ]);
    }

    /**
     * @param array<string, mixed>|null $mark
     */
    private function noLineSuffix(?array $mark): string
    {
        if ($mark === null) {
            return '';
        }

        $markCode = (string) ($mark['mark'] ?? '');
        $reason = trim((string) ($mark['reason_text'] ?? ''));

        if ($markCode === AttendanceMark::WARNED && $reason !== '') {
            return $this->texts->get('rsvp.no_with_reason', ['reason' => $reason]);
        }

        if ($markCode === AttendanceMark::WARNED || $markCode === AttendanceMark::UNEXCUSED) {
            return $this->texts->get('rsvp.no_early');
        }

        return '';
    }
}