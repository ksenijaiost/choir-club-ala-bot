<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\RehearsalRsvpNudgeRepository;
use App\Repositories\RehearsalRsvpRepository;
use App\Repositories\UserRepository;
use App\Telegram\TelegramOutbound;

final class RehearsalRsvpNudgeService
{
    private const MIN_INTERVAL_SECONDS = 1800;

    public function __construct(
        private readonly RehearsalRsvpNudgeRepository $nudges,
        private readonly RehearsalRsvpRepository $rsvp,
        private readonly DayBeforeReminderDeliveryRepository $deliveries,
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly AttendanceMessageFactory $messages,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{ok: bool, reason: string, sent: int}
     */
    public function nudgePending(int $rehearsalId, \DateTimeImmutable $nowUtc, \DateTimeZone $tz): array
    {
        $rehearsal = $this->rehearsals->findById($rehearsalId);
        if ($rehearsal === null) {
            return ['ok' => false, 'reason' => 'missing', 'sent' => 0];
        }

        $text = $this->messages->dayBeforeText($rehearsal, $tz);
        $keyboard = $this->messages->dayBeforeKeyboard($rehearsalId);
        $last = $this->nudges->find($rehearsalId);
        if ($last !== null) {
            $lastAt = new \DateTimeImmutable((string) $last['last_nudged_at'], new \DateTimeZone('UTC'));
            if ($nowUtc->getTimestamp() - $lastAt->getTimestamp() < self::MIN_INTERVAL_SECONDS) {
                return ['ok' => false, 'reason' => 'rate_limit', 'sent' => 0];
            }
        }

        if (!$this->deliveries->hasAnyForRehearsal($rehearsalId)) {
            return ['ok' => false, 'reason' => 'not_sent', 'sent' => 0];
        }

        $rsvpUserIds = [];
        foreach ($this->rsvp->listForRehearsal($rehearsalId) as $row) {
            $rsvpUserIds[(int) $row['user_id']] = true;
        }

        $sent = 0;
        foreach ($this->deliveries->listForRehearsal($rehearsalId) as $delivery) {
            if (($delivery['status'] ?? '') !== DayBeforeReminderDeliveryRepository::STATUS_SENT) {
                continue;
            }
            $userId = (int) $delivery['user_id'];
            if (isset($rsvpUserIds[$userId])) {
                continue;
            }

            $user = $this->users->findById($userId);
            if ($user === null) {
                continue;
            }

            try {
                $this->outbound->sendMessage((int) $user['telegram_id'], $text, $keyboard);
                $sent++;
            } catch (\Throwable) {
            }
        }

        if ($sent > 0) {
            $this->nudges->record($rehearsalId);
        }

        return ['ok' => true, 'reason' => 'ok', 'sent' => $sent];
    }
}