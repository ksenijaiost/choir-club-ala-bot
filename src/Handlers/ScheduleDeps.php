<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BotSettingsRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Text\TextRepository;

final class ScheduleDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly ScheduleTemplateRepository $templates,
        public readonly RehearsalRepository $rehearsals,
        public readonly UserFlowRepository $flows,
        public readonly BotSettingsRepository $settings,
        public readonly ScheduleGenerator $generator,
        public readonly SchedulePresenter $presenter,
        public readonly ScheduleBroadcastService $broadcast,
        public readonly UserRepository $users,
        public readonly TextRepository $texts,
    ) {
    }
}
