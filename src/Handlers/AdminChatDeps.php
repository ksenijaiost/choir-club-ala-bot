<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BotSettingsRepository;
use App\Repositories\UserFlowRepository;
use App\Services\AdminChatResolver;
use App\Text\TextRepository;

final class AdminChatDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly BotSettingsRepository $settings,
        public readonly AdminChatResolver $resolver,
        public readonly UserFlowRepository $flows,
        public readonly TextRepository $texts,
    ) {
    }
}
