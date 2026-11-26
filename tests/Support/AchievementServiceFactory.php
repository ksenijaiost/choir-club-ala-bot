<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config;
use App\Repositories\AttendanceMarkRepository;
use App\Repositories\BotSettingsRepository;
use App\Repositories\DayBeforeReminderDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\UserAchievementRepository;
use App\Services\AchievementService;
use App\Text\TextRepository;

final class AchievementServiceFactory
{
    public static function make(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        ?RecordingTelegramOutbound $outbound = null,
    ): AchievementService {
        $outbound ??= new RecordingTelegramOutbound();

        return new AchievementService(
            new UserAchievementRepository($pdo),
            $texts,
            $outbound,
            new AttendanceMarkRepository($pdo),
            new DayBeforeReminderDeliveryRepository($pdo),
            new ProgramRepository($pdo),
            new PaymentRecordRepository($pdo),
            new BotSettingsRepository($pdo),
        );
    }
}