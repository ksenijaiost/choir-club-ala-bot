<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BotSettingsRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;

final class ScheduleGenerator
{
    public function __construct(
        private readonly ScheduleTemplateRepository $templates,
        private readonly RehearsalRepository $rehearsals,
        private readonly BotSettingsRepository $settings,
    ) {
    }

    public function generate(\DateTimeImmutable $now, \DateTimeZone $tz): int
    {
        $weeks = $this->settings->getInt('schedule_generation_weeks', 4);
        $created = 0;

        $localNow = $now->setTimezone($tz);
        $localStart = $localNow->setTime(0, 0, 0);
        $localEnd = $localStart->modify('+' . $weeks . ' weeks');

        foreach ($this->templates->listActive() as $template) {
            $weekday = (int) $template['weekday'];
            $duration = (int) $template['duration_minutes'];
            $address = (string) $template['address'];
            $templateId = (int) $template['id'];
            $startTime = (string) $template['start_time'];

            for ($cursor = $localStart; $cursor < $localEnd; $cursor = $cursor->modify('+1 day')) {
                if ((int) $cursor->format('N') !== $weekday) {
                    continue;
                }

                $starts = ScheduleTime::combineLocalDateAndTime($cursor, $startTime, $tz);
                if ($starts < $localNow) {
                    continue;
                }

                $startsStored = ScheduleTime::toStorage($starts);
                if ($this->rehearsals->existsForTemplateSlot($templateId, $startsStored)) {
                    continue;
                }

                $ends = $starts->modify('+' . $duration . ' minutes');
                $this->rehearsals->create(
                    $templateId,
                    $startsStored,
                    ScheduleTime::toStorage($ends),
                    $address,
                );
                $created++;
            }
        }

        return $created;
    }
}
