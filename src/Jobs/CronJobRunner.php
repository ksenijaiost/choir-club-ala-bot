<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;

/**
 * Entry point for scheduled jobs (see docs/scenarios.md).
 * Business logic will be implemented in later iterations.
 */
final class CronJobRunner
{
    public function run(Config $config): array
    {
        $now = Clock::now($config);

        return [
            'status' => 'ok',
            'message' => 'Cron stub: no jobs implemented yet.',
            'now' => $now->format(DATE_ATOM),
            'timezone' => $config->timezone,
            'fake_now' => $config->fakeNow !== null,
        ];
    }
}
