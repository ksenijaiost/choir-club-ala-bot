<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BotSettingsRepository;
use App\Text\TextRepository;

final class PaymentReminderTextService
{
    public const SETTING_1 = 'payment_reminder_1_text';

    public const SETTING_2 = 'payment_reminder_2_text';

    public const MAX_LENGTH = 1000;

    public function __construct(
        private readonly BotSettingsRepository $settings,
        private readonly TextRepository $texts,
        private readonly PaymentTexts $paymentTexts,
    ) {
    }

    public function templateForReminder(int $which): string
    {
        $custom = trim((string) ($this->settings->get($which === 1 ? self::SETTING_1 : self::SETTING_2) ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return $which === 1
            ? $this->texts->get('payment.reminder_first')
            : $this->texts->get('payment.reminder_second');
    }

    public function formatReminder(int $which, int $year, int $month): string
    {
        return $this->applyPlaceholders($this->templateForReminder($which), $year, $month);
    }

    public function applyPlaceholders(string $template, int $year, int $month): string
    {
        $placeholders = $this->paymentTexts->monthYearPlaceholders($year, $month);

        return str_replace(
            ['{month}', '{year}'],
            [$placeholders['month'], $placeholders['year']],
            $template,
        );
    }

    public function saveTemplate(int $which, string $text): void
    {
        $key = $which === 1 ? self::SETTING_1 : self::SETTING_2;
        $this->settings->set($key, trim($text));
    }

    public function resetToDefaults(): void
    {
        $this->settings->delete(self::SETTING_1);
        $this->settings->delete(self::SETTING_2);
    }

    public function isCustom(int $which): bool
    {
        $raw = $this->settings->get($which === 1 ? self::SETTING_1 : self::SETTING_2);

        return $raw !== null && trim($raw) !== '';
    }
}
