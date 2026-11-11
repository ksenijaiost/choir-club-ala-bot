<?php

declare(strict_types=1);

namespace App\Services;

use App\Text\TextRepository;
use App\Telegram\TelegramOutbound;

final class AttendanceConductorNotifier
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly TextRepository $texts,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $rehearsal
     */
    public function forwardDayBeforeReason(array $user, array $rehearsal, string $reason, \DateTimeZone $tz): void
    {
        $conductorTg = $this->recipients->resolveTelegramId();
        if ($conductorTg === null) {
            return;
        }

        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        $this->outbound->sendMessage(
            $conductorTg,
            $this->texts->get('absence.forward_reason', [
                'name' => (string) ($user['display_name'] ?? ''),
                'rehearsal_date' => ScheduleTime::formatLocalDate($starts, $tz) . ' '
                    . ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz),
                'reason' => $reason,
            ]),
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $rehearsal
     */
    public function notifyPlannedAbsence(array $user, array $rehearsal, string $reason, \DateTimeZone $tz): void
    {
        $conductorTg = $this->recipients->resolveTelegramId();
        if ($conductorTg === null) {
            return;
        }

        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        $partCode = (string) ($user['voice_part'] ?? '');
        $part = $partCode !== '' ? $this->texts->get('parts.' . $partCode) : '—';

        $this->outbound->sendMessage(
            $conductorTg,
            $this->texts->get('absence.notify_conductor', [
                'name' => (string) ($user['display_name'] ?? ''),
                'part' => $part,
                'rehearsal_date' => ScheduleTime::formatLocalDate($starts, $tz) . ' '
                    . ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz),
                'reason' => $reason !== '' ? $reason : '—',
            ]),
        );
    }
}
