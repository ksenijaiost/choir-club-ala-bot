<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\UserRepository;
use App\Services\ChoirWrappedService;
use App\Services\VoicePartRules;
use App\Telegram\TelegramOutbound;

final class MonthlyWrappedJob
{
    private const JOB_TYPE = 'monthly_wrapped';

    public function __construct(
        private readonly ChoirWrappedService $wrapped,
        private readonly UserRepository $users,
        private readonly JobDeliveryRepository $deliveries,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{sent: int, skipped_no_rehearsals?: bool, not_due?: bool}
     */
    public function runIfDue(Config $config): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $local = Clock::now($config)->setTimezone($tz);

        if ((int) $local->format('j') !== 1 || (int) $local->format('G') !== 10 || (int) $local->format('i') !== 5) {
            return ['sent' => 0, 'not_due' => true];
        }

        $prev = $this->wrapped->previousCalendarMonth($config);
        if (!$this->wrapped->monthHasRehearsals($config, $prev['year'], $prev['month'])) {
            return ['sent' => 0, 'skipped_no_rehearsals' => true];
        }

        $periodKey = sprintf('%04d-%02d', $prev['year'], $prev['month']);
        $sent = 0;

        foreach ($this->users->listAttendanceParticipants() as $user) {
            if (VoicePartRules::isConductor($user)) {
                continue;
            }

            $userId = (int) $user['id'];
            $jobKey = 'monthly_wrapped:' . $periodKey . ':' . $userId;
            if (!$this->deliveries->tryClaim($jobKey, self::JOB_TYPE, $userId)) {
                continue;
            }

            $summary = $this->wrapped->buildMonthlyMemberSummary($user, $config, $prev['year'], $prev['month']);
            $this->outbound->sendMessage(
                (int) $user['telegram_id'],
                $this->wrapped->formatMonthlyMemberMessage($summary),
            );
            $sent++;
        }

        return ['sent' => $sent];
    }
}