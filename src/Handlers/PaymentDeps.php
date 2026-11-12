<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Repositories\BotSettingsRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationRecipientResolver;
use App\Services\PaymentReminderTextService;
use App\Services\PaymentTexts;
use App\Text\TextRepository;

final class PaymentDeps
{
    public function __construct(
        public readonly Config $config,
        public readonly UserRepository $users,
        public readonly PaymentRecordRepository $payments,
        public readonly PaymentTexts $paymentTexts,
        public readonly NotificationRecipientResolver $recipients,
        public readonly TextRepository $texts,
        public readonly BotSettingsRepository $settings,
        public readonly UserFlowRepository $flows,
        public readonly PaymentReminderTextService $reminderTexts,
    ) {
    }
}
