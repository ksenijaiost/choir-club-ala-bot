<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\JobDeliveryRepository;
use App\Services\AdminChatResolver;
use App\Services\PartBattleService;
use App\Telegram\TelegramOutbound;

final class PartBattleMonthlyJob
{
    private const JOB_TYPE = 'part_battle_monthly';

    public function __construct(
        private readonly PartBattleService $partBattle,
        private readonly JobDeliveryRepository $deliveries,
        private readonly AdminChatResolver $adminChat,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{sent: bool, not_due?: bool, skipped_no_rehearsals?: bool, skipped_no_chat?: bool}
     */
    public function runIfDue(Config $config): array
    {
        $tz = new \DateTimeZone($config->timezone);
        $local = Clock::now($config)->setTimezone($tz);

        if ((int) $local->format('j') !== 1 || (int) $local->format('G') !== 10) {
            return ['sent' => false, 'not_due' => true];
        }

        $prev = $this->partBattle->previousCalendarMonth($config);
        $jobKey = sprintf('part_battle:%04d-%02d', $prev['year'], $prev['month']);

        if (!$this->deliveries->tryClaim($jobKey, self::JOB_TYPE)) {
            return ['sent' => false];
        }

        $result = $this->partBattle->buildForMonth($config, $prev['year'], $prev['month']);
        if (!$result->hasRehearsals()) {
            return ['sent' => false, 'skipped_no_rehearsals' => true];
        }

        $adminChatId = $this->adminChat->resolvedIdAsInt();
        if ($adminChatId === null) {
            return ['sent' => false, 'skipped_no_chat' => true];
        }

        $this->outbound->sendMessage($adminChatId, $this->partBattle->formatCronMessage($result));

        return ['sent' => true];
    }
}