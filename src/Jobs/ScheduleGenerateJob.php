<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\JobDeliveryRepository;
use App\Services\ScheduleGenerator;

final class ScheduleGenerateJob
{
    public function __construct(
        private readonly ScheduleGenerator $generator,
        private readonly JobDeliveryRepository $deliveries,
    ) {
    }

    /**
     * @return array{ran: bool, created: int}
     */
    public function runIfDue(Config $config): array
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $local = $now->setTimezone($tz);

        $jobKey = 'schedule_generate:' . $local->format('Y-m-d');

        if ($this->deliveries->wasDelivered($jobKey)) {
            return ['ran' => false, 'created' => 0];
        }

        if ((int) $local->format('G') < 3) {
            return ['ran' => false, 'created' => 0];
        }

        $created = $this->generator->generate($now, $tz);
        $this->deliveries->record($jobKey, 'schedule_generate');

        return ['ran' => true, 'created' => $created];
    }

    public function runNow(Config $config): int
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);

        return $this->generator->generate($now, $tz);
    }
}
