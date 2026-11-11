<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Domain\AttendanceSource;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\CheckInSessionRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Services\AttendanceService;
use App\Services\ScheduleTime;

final class CheckinCloseJob
{
    public function __construct(
        private readonly RehearsalRepository $rehearsals,
        private readonly UserRepository $users,
        private readonly AttendanceMarkRepository $marks,
        private readonly CheckInSessionRepository $sessions,
        private readonly JobDeliveryRepository $deliveries,
        private readonly AttendanceService $attendance,
    ) {
    }

    /**
     * @return array{closed: int, marks: int}
     */
    public function runIfDue(Config $config): array
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $nowLocal = ScheduleTime::toStorage($now->setTimezone($tz));

        $marksCreated = 0;
        $closed = 0;

        foreach ($this->rehearsals->listScheduledEnded($nowLocal) as $rehearsal) {
            $rehearsalId = (int) $rehearsal['id'];
            if ($this->sessions->isClosed($rehearsalId)) {
                continue;
            }

            foreach ($this->users->listActiveParticipants() as $user) {
                $userId = (int) $user['id'];
                if ($this->marks->hasMark($rehearsalId, $userId)) {
                    continue;
                }

                $jobKey = 'checkin_close:' . $rehearsalId . ':' . $userId;
                if (!$this->deliveries->tryClaim($jobKey, 'checkin_close', $rehearsalId)) {
                    continue;
                }

                $this->attendance->recordUnexcused($user, $rehearsalId, AttendanceSource::SYSTEM);
                $marksCreated++;
            }

            $this->sessions->close($rehearsalId);
            $closed++;
        }

        return ['closed' => $closed, 'marks' => $marksCreated];
    }
}
