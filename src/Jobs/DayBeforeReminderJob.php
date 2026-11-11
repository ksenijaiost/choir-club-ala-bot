<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceMessageFactory;
use App\Telegram\TelegramOutbound;

final class DayBeforeReminderJob
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly JobDeliveryRepository $deliveries,
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
                if (!$this->deliveries->tryClaim($jobKey, 'day_before_reminder', $rehearsalId)) {
                    continue;
                }

                $this->outbound->sendMessage((int) $user['telegram_id'], $text, $keyboard);
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped_hour' => false];
    }
}
