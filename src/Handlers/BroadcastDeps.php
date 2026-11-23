<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BroadcastRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\BroadcastDeliveryService;
use App\Services\BroadcastKeyboardBuilder;
use App\Services\BroadcastStatusPresenter;
use App\Services\UserRolePresenter;
use App\Telegram\InteractiveMenuService;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class BroadcastDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly UserRepository $users,
        public readonly BroadcastRepository $broadcasts,
        public readonly UserFlowRepository $flows,
        public readonly TextRepository $texts,
        public readonly InteractiveMenuService $interactiveMenus,
        public readonly BroadcastDeliveryService $delivery,
        public readonly BroadcastStatusPresenter $statusPresenter,
        public readonly BroadcastKeyboardBuilder $keyboards,
        public readonly UserRolePresenter $rolePresenter,
        public readonly TelegramOutbound $outbound,
    ) {
    }
}