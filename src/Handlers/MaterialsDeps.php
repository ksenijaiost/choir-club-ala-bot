<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Text\TextRepository;

final class MaterialsDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly UserRepository $users,
        public readonly UserFlowRepository $flows,
        public readonly SongRepository $songs,
        public readonly MaterialRepository $materials,
        public readonly MaterialsService $service,
        public readonly SongDeliveryService $delivery,
        public readonly TextRepository $texts,
    ) {
    }
}
