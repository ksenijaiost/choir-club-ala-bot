<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\BotSettingsRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserRepository;
use App\Services\PaymentPeriod;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;

final class PaymentReminderSecondJob
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PaymentRecordRepository $payments,
        private readonly BotSettingsRepository $settings,
        private readonly JobDeliveryRepository $deliveries,
        private readonly TextRepository $texts,
        private readonly PaymentTexts $paymentTexts,
        private readonly PaymentReminderTextService $reminderTexts,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{sent: int, skipped: int, skipped_confirmed: int}
     */
    public function runIfDue(Config $config): array
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $local = $now->setTimezone($tz);

        $day = $this->settings->getInt('payment_reminder_day_2', 1);
        $hour = $this->settings->getInt('payment_reminder_hour', 12);

        if ((int) $local->format('j') !== $day || (int) $local->format('G') !== $hour) {
            return ['sent' => 0, 'skipped' => 0, 'skipped_confirmed' => 0, 'not_due' => true];
        }

        $period = PaymentPeriod::targetForSecondReminder($local);
        $periodKey = PaymentPeriod::periodKey($period['year'], $period['month']);
        $placeholders = $this->paymentTexts->monthYearPlaceholders($period['year'], $period['month']);

        $sent = 0;
        $skippedConfirmed = 0;

        foreach ($this->users->listActivePayers() as $user) {
            $userId = (int) $user['id'];
            $jobKey = 'payment_r2:' . $periodKey . ':' . $userId;
            if ($this->deliveries->wasDelivered($jobKey)) {
                continue;
            }

            $row = $this->payments->find($userId, $period['year'], $period['month']);

            if ($this->payments->isConductorConfirmed($row)) {
                $this->deliveries->tryClaim($jobKey, 'payment_reminder_2', $userId);
                $skippedConfirmed++;

                continue;
            }

            if (!$this->deliveries->tryClaim($jobKey, 'payment_reminder_2', $userId)) {
                continue;
            }

            if ($this->payments->hasMemberClaim($row)) {
                $this->outbound->sendMessage(
                    (int) $user['telegram_id'],
                    $this->texts->get('payment.mismatch', $placeholders),
                );
            } else {
                $this->outbound->sendMessage(
                    (int) $user['telegram_id'],
                    $this->reminderTexts->formatReminder(2, $period['year'], $period['month']),
                );
            }

            $sent++;
        }

        return [
            'sent' => $sent,
            'skipped' => 0,
            'skipped_confirmed' => $skippedConfirmed,
            'not_due' => false,
        ];
    }
}
