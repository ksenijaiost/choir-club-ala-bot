<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Services\PaymentReminderTextService;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentReminderTextAdminHandler
{
    private const FLOW = 'payment_reminder_edit';

    public const CB_HOME = 'pay:rt:home';

    private const CB_BACK = 'pay:rt:back';

    private const CB_RESET = 'pay:rt:reset';

    private const CB_CANCEL = 'pay:rt:cancel';

    public static function handleCallback(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'pay:rt:')) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        $bot->answerCallbackQuery();

        if ($data === self::CB_HOME) {
            CallbackUi::clearKeyboard($bot);
            self::sendTextsHome($bot, $deps);

            return true;
        }

        if ($data === self::CB_BACK) {
            $deps->flows->clear($actorTelegramId);
            self::sendTextsHome($bot, $deps);

            return true;
        }

        if ($data === self::CB_RESET) {
            $deps->reminderTexts->resetToDefaults();
            $deps->flows->clear($actorTelegramId);
            self::sendTextsHome($bot, $deps);

            return true;
        }

        if ($data === self::CB_CANCEL) {
            $deps->flows->clear($actorTelegramId);
            CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('payment.reminder_edit_cancelled'));
            self::sendTextsHome($bot, $deps);

            return true;
        }

        if (preg_match('/^pay:rt:edit:(1|2)$/', $data, $m) === 1) {
            $which = (int) $m[1];
            $deps->flows->set($actorTelegramId, self::FLOW, ['which' => $which, 'step' => 'typing']);
            $label = $which === 1
                ? $deps->texts->get('payment.reminder_edit_label_1')
                : $deps->texts->get('payment.reminder_edit_label_2');
            $keyboard = InlineMenuHelper::appendFooter(
                InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make($deps->texts->get('payment.reminder_edit_cancel'), callback_data: self::CB_CANCEL),
                ),
                $deps->texts,
                self::CB_BACK,
            );
            $bot->sendMessage(
                $deps->texts->get('payment.reminder_edit_prompt', ['which' => $label]),
                reply_markup: $keyboard,
            );

            return true;
        }

        if (preg_match('/^pay:rt:save:(1|2)$/', $data, $m) === 1) {
            $which = (int) $m[1];
            $state = $deps->flows->get($actorTelegramId);
            if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
                return true;
            }

            $draft = trim((string) ($state['context']['draft'] ?? ''));
            if ($draft === '' || mb_strlen($draft) > PaymentReminderTextService::MAX_LENGTH) {
                return true;
            }

            $deps->reminderTexts->saveTemplate($which, $draft);
            $deps->flows->clear($actorTelegramId);
            $bot->sendMessage($deps->texts->get('payment.reminder_edit_saved'));
            self::sendTextsHome($bot, $deps);

            return true;
        }

        return true;
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        PaymentDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return false;
        }

        if (mb_strtolower(trim($text)) === mb_strtolower($deps->texts->get('payment.reminder_edit_cancel'))) {
            $deps->flows->clear($telegramId);
            $bot->sendMessage($deps->texts->get('payment.reminder_edit_cancelled'));

            return true;
        }

        $draft = trim($text);
        if ($draft === '') {
            $bot->sendMessage($deps->texts->get('payment.reminder_edit_empty'));

            return true;
        }

        if (mb_strlen($draft) > PaymentReminderTextService::MAX_LENGTH) {
            $bot->sendMessage($deps->texts->get('payment.reminder_edit_too_long'));

            return true;
        }

        $which = (int) ($state['context']['which'] ?? 1);
        $deps->flows->set($telegramId, self::FLOW, [
            'which' => $which,
            'step' => 'preview',
            'draft' => $draft,
        ]);

        $previewYear = 2026;
        $previewMonth = $which === 1 ? 12 : 11;
        $preview = $deps->reminderTexts->applyPlaceholders($draft, $previewYear, $previewMonth);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($deps->texts->get('payment.reminder_edit_save'), callback_data: 'pay:rt:save:' . $which),
            InlineKeyboardButton::make($deps->texts->get('payment.reminder_edit_cancel'), callback_data: self::CB_CANCEL),
        );

        $bot->sendMessage(
            $deps->texts->get('payment.reminder_edit_preview', ['text' => $preview]),
            reply_markup: $keyboard,
        );

        return true;
    }

    private static function sendTextsHome(Nutgram $bot, PaymentDeps $deps): void
    {
        $sampleYear = 2026;
        $text1 = $deps->reminderTexts->formatReminder(1, $sampleYear, 12);
        $text2 = $deps->reminderTexts->formatReminder(2, $sampleYear, 11);

        $body = $deps->texts->get('payment.reminder_texts_home', [
            'text_1' => $text1,
            'text_2' => $text2,
        ]);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.reminder_edit_action_1'),
                    callback_data: 'pay:rt:edit:1',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.reminder_edit_action_2'),
                    callback_data: 'pay:rt:edit:2',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.reminder_reset_defaults'),
                    callback_data: self::CB_RESET,
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'pay:adm:home');

        $bot->sendMessage($body, reply_markup: $keyboard);
    }
}
