<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\RehearsalRsvpAnswer;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\RehearsalRsvpRepository;

final class RehearsalRsvpStatusService
{
    public function __construct(
        private readonly RehearsalRsvpRepository $rsvp,
        private readonly DayBeforeReminderDeliveryRepository $deliveries,
        private readonly AttendanceMarkRepository $marks,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $participants active participants
     * @return array{
     *   reminder_started: bool,
     *   yes: list<array<string, mixed>>,
     *   no: list<array<string, mixed>>,
     *   pending: list<array<string, mixed>>,
     *   undelivered: list<array<string, mixed>>,
     *   early_absent: list<array<string, mixed>>,
     * }
     */
    public function buildLists(int $rehearsalId, array $participants): array
    {
        $rsvpByUser = [];
        foreach ($this->rsvp->listForRehearsal($rehearsalId) as $row) {
            $rsvpByUser[(int) $row['user_id']] = (string) $row['answer'];
        }

        $deliveryByUser = [];
        foreach ($this->deliveries->listForRehearsal($rehearsalId) as $row) {
            $deliveryByUser[(int) $row['user_id']] = $row;
        }

        $marksByUser = [];
        foreach ($this->marks->listForRehearsal($rehearsalId) as $row) {
            $marksByUser[(int) $row['user_id']] = $row;
        }

        $reminderStarted = $this->deliveries->hasAnyForRehearsal($rehearsalId);

        $yes = [];
        $no = [];
        $pending = [];
        $undelivered = [];
        $earlyAbsent = [];

        foreach ($participants as $user) {
            $userId = (int) $user['id'];
            $mark = $marksByUser[$userId] ?? null;
            $answer = $rsvpByUser[$userId] ?? null;
            $delivery = $deliveryByUser[$userId] ?? null;

            if ($mark !== null) {
                $markCode = (string) ($mark['mark'] ?? '');
                $source = (string) ($mark['source'] ?? '');
                if (in_array($markCode, [AttendanceMark::WARNED, AttendanceMark::UNEXCUSED], true)
                    && $source !== AttendanceSource::DAY_BEFORE
                ) {
                    $earlyAbsent[] = $this->enrichUser($user, $mark, $answer);

                    continue;
                }
            }

            if (!$reminderStarted) {
                continue;
            }

            if ($delivery !== null && ($delivery['status'] ?? '') === DayBeforeReminderDeliveryRepository::STATUS_FAILED) {
                $undelivered[] = $this->enrichUser($user, $mark, $answer, $delivery);

                continue;
            }

            if ($answer === RehearsalRsvpAnswer::YES) {
                $yes[] = $this->enrichUser($user, $mark, $answer);

                continue;
            }

            if ($answer === RehearsalRsvpAnswer::NO) {
                $no[] = $this->enrichUser($user, $mark, $answer);

                continue;
            }

            if ($delivery !== null && ($delivery['status'] ?? '') === DayBeforeReminderDeliveryRepository::STATUS_SENT) {
                $pending[] = $this->enrichUser($user, $mark, $answer);
            }
        }

        return [
            'reminder_started' => $reminderStarted,
            'yes' => $yes,
            'no' => $no,
            'pending' => $pending,
            'undelivered' => $undelivered,
            'early_absent' => $earlyAbsent,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $mark
     * @param array<string, mixed>|null $delivery
     * @return array<string, mixed>
     */
    private function enrichUser(array $user, ?array $mark, ?string $answer, ?array $delivery = null): array
    {
        return [
            'user' => $user,
            'mark' => $mark,
            'rsvp_answer' => $answer,
            'delivery' => $delivery,
        ];
    }
}