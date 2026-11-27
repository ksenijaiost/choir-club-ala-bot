<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\ProgramRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AchievementService;
use App\Services\ProgramDeliveryService;
use App\Services\ProgramMessageBuilder;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;

final class ProgramDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly UserRepository $users,
        public readonly UserFlowRepository $flows,
        public readonly ProgramRepository $programs,
        public readonly SongRepository $songs,
        public readonly ProgramMessageBuilder $programMessages,
        public readonly ProgramDeliveryService $delivery,
        public readonly TextRepository $texts,
        public readonly InteractiveMenuService $interactiveMenus,
        public readonly AchievementService $achievements,
    ) {
    }
}