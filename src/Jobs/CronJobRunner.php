<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\BotSettingsRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Services\ScheduleGenerator;

final class CronJobRunner
{
    public function run(Config $config, \PDO $pdo): array
    {
        $now = Clock::now($config);

        $generator = new ScheduleGenerator(
            new ScheduleTemplateRepository($pdo),
            new RehearsalRepository($pdo),
            new BotSettingsRepository($pdo),
        );

        $scheduleJob = new ScheduleGenerateJob($generator, new JobDeliveryRepository($pdo));
        $schedule = $scheduleJob->runIfDue($config);

        return [
            'status' => 'ok',
            'now' => $now->format(DATE_ATOM),
            'timezone' => $config->timezone,
            'fake_now' => $config->fakeNow !== null,
            'schedule_generate' => $schedule,
        ];
    }
}
