<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Clock;
use App\Config;
use App\Repositories\BotSettingsRepository;
use App\Repositories\JobDeliveryRepository;
use App\Repositories\UserRepository;
use App\Services\PaymentPeriod;
use App\Services\PaymentTexts;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentReminderFirstJob
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly BotSettingsRepository $settings,
        private readonly JobDeliveryRepository $deliveries,
        private readonly TextRepository $texts,
        private readonly PaymentTexts $paymentTexts,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @return array{sent: int, skipped: bool}
     */
    public function runIfDue(Config $config): array
    {
        $now = Clock::now($config);
        $tz = new \DateTimeZone($config->timezone);
        $local = $now->setTimezone($tz);

        $day = $this->settings->getInt('payment_reminder_day_1', 23);
        $hour = $this->settings->getInt('payment_reminder_hour', 12);

        if ((int) $local->format('j') !== $day || (int) $local->format('G') !== $hour) {
            return ['sent' => 0, 'skipped' => true];
        }

        $period = PaymentPeriod::targetForFirstReminder($local);
        $periodKey = PaymentPeriod::periodKey($period['year'], $period['month']);
        $placeholders = $this->paymentTexts->monthYearPlaceholders($period['year'], $period['month']);
        $message = $this->texts->get('payment.reminder_first', $placeholders);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('buttons.i_paid'),
                callback_data: 'pay:ok:' . $period['year'] . ':' . $period['month'],
            ),
        );

        $sent = 0;
        foreach ($this->users->listActivePayers() as $user) {
            $userId = (int) $user['id'];
            $jobKey = 'payment_r1:' . $periodKey . ':' . $userId;
            if (!$this->deliveries->tryClaim($jobKey, 'payment_reminder_1', $userId)) {
                continue;
            }

            $this->outbound->sendMessage((int) $user['telegram_id'], $message, $keyboard);
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => false];
    }
}
