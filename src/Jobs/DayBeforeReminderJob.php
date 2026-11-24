<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceMessageFactory;
use App\Services\TelegramDeliveryReason;
use App\Telegram\TelegramOutbound;

final class DayBeforeReminderJob
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly AttendanceMarkRepository $marks,
        private readonly JobDeliveryRepository $deliveries,
        private readonly DayBeforeReminderDeliveryRepository $reminderDeliveries,
        private readonly AttendanceMessageFactory $messages,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{sent: int, skipped_hour: bool}
     */
    public function runIfDue(Config $config): array
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $local = $now->setTimezone($tz);

        if ((int) $local->format('G') !== 12) {
            return ['sent' => 0, 'skipped_hour' => true];
        }

        $tomorrow = $local->modify('+1 day')->format('Y-m-d');
        $sent = 0;

        foreach ($this->rehearsals->listScheduledOnLocalDay($tomorrow) as $rehearsal) {
            $rehearsalId = (int) $rehearsal['id'];
            $text = $this->messages->dayBeforeText($rehearsal, $tz);
            $keyboard = $this->messages->dayBeforeKeyboard($rehearsalId);

            foreach ($this->users->listActiveParticipants() as $user) {
                $userId = (int) $user['id'];
                $jobKey = 'day_before:' . $rehearsalId . ':' . $userId;
                if ($this->deliveries->wasDelivered($jobKey)) {
                    continue;
                }

                if ($this->shouldSkipEarlyAbsence($rehearsalId, $userId)) {
                    continue;
                }

                try {
                    $this->outbound->sendMessage((int) $user['telegram_id'], $text, $keyboard);
                    $this->deliveries->record($jobKey, 'day_before_reminder', $rehearsalId);
                    $this->reminderDeliveries->recordSent($rehearsalId, $userId);
                    $sent++;
                } catch (\Throwable $e) {
                    $this->reminderDeliveries->recordFailed(
                        $rehearsalId,
                        $userId,
                        TelegramDeliveryReason::codeFromThrowable($e),
                    );
                }
            }
        }

        return ['sent' => $sent, 'skipped_hour' => false];
    }

    private function shouldSkipEarlyAbsence(int $rehearsalId, int $userId): bool
    {
        $mark = $this->marks->find($rehearsalId, $userId);
        if ($mark === null) {
            return false;
        }

        $markCode = (string) ($mark['mark'] ?? '');
        $source = (string) ($mark['source'] ?? '');

        return in_array($markCode, [AttendanceMark::WARNED, AttendanceMark::UNEXCUSED], true)
            && $source !== AttendanceSource::DAY_BEFORE;
    }
}