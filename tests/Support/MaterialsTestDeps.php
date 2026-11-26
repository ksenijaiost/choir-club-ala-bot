<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config;
use App\Handlers\MaterialsDeps;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialNotificationService;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Telegram\InteractiveMenuService;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class MaterialsTestDeps
{
    public static function build(
        Config $config,
        \PDO $pdo,
        UserRepository $users,
        UserFlowRepository $flows,
        SongRepository $songs,
        MaterialRepository $materials,
        TextRepository $texts,
        InteractiveMenuService $interactiveMenus,
        ?TelegramOutbound $outbound = null,
    ): MaterialsDeps {
        $outbound ??= new RecordingTelegramOutbound();
        $service = new MaterialsService($materials, $songs, $texts);

        return new MaterialsDeps(
            $config,
            $users,
            $flows,
            $songs,
            $materials,
            $service,
            new SongDeliveryService($materials, $songs, $texts, $service),
            new MaterialNotificationService($users, $texts, $service, $outbound),
            $texts,
            $interactiveMenus,
            AchievementServiceFactory::make($config, $pdo, $texts),
        );
    }
}