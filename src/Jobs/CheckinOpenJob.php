<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Domain\AttendanceMark;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceMessageFactory;
use App\Services\ScheduleTime;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class CheckinOpenJob
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly AttendanceMarkRepository $marks,
        private readonly CheckInSessionRepository $sessions,
        private readonly JobDeliveryRepository $deliveries,
        private readonly AttendanceMessageFactory $messages,
        private readonly TextRepository $texts,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{sent: int}
     */
    public function runIfDue(Config $config): array
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $nowLocal = ScheduleTime::toStorage($now->setTimezone($tz));

        $sent = 0;
        foreach ($this->rehearsals->listOngoingScheduled($nowLocal) as $rehearsal) {
            $rehearsalId = (int) $rehearsal['id'];
            $this->sessions->ensureOpen($rehearsalId);

            $text = $this->texts->get('checkin.question');
            $keyboard = $this->messages->checkinKeyboard($rehearsalId);

            foreach ($this->users->listActiveParticipants() as $user) {
                $userId = (int) $user['id'];
                $mark = $this->marks->find($rehearsalId, $userId);
                if ($mark !== null && ($mark['mark'] ?? '') === AttendanceMark::WARNED) {
                    continue;
                }

                $jobKey = 'checkin_open:' . $rehearsalId . ':' . $userId;
                if (!$this->deliveries->tryClaim($jobKey, 'checkin_open', $rehearsalId)) {
                    continue;
                }

                $this->outbound->sendMessage((int) $user['telegram_id'], $text, $keyboard);
                $sent++;
            }
        }

        return ['sent' => $sent];
    }
}
