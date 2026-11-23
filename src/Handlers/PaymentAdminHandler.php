<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
use App\Services\PaymentPeriod;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentAdminHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function openHome(Nutgram $bot, PaymentDeps $deps, AccessControl $access, array $actor): void
    {
        if (!$access->hasConductorPrivileges($actor)) {
            $bot->sendMessage($deps->texts->get('admin.only_conductor_or_owner'));

            return;
        }

        $keyboard = self::buildHomeKeyboard($deps);
        $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $deps->texts->get('payment.admin_pick_month'),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    /**
     * @param array<string, mixed> $actor
     */
    public static function renderHomeEdit(Nutgram $bot, PaymentDeps $deps, array $actor): void
    {
        $actorTg = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        CallbackUi::editCurrentMessage(
            $bot,
            $deps->texts->get('payment.admin_pick_month'),
            self::buildHomeKeyboard($deps),
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    public static function buildHomeKeyboard(PaymentDeps $deps): InlineKeyboardMarkup
    {
        $tz = new \DateTimeZone($deps->config->timezone);
        $local = Clock::now($deps->config)->setTimezone($tz);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_member_payments_button'),
                    callback_data: 'pa:p:0',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.reminder_texts_button'),
                    callback_data: PaymentReminderTextAdminHandler::CB_HOME,
                ),
            );

        foreach (PaymentPeriod::adminMonthChoices($local) as $choice) {
            $label = $deps->paymentTexts->monthName($choice['month']) . ' ' . $choice['year'];
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $label,
                    callback_data: 'pay:adm:' . $choice['year'] . ':' . $choice['month'],
                ),
            );
        }

        return InlineMenuHelper::appendFooter($keyboard, $deps->texts);
    }

    public static function handleCallback(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (str_starts_with($data, 'pa:')) {
            return PaymentAdminMemberHandler::handleCallback($bot, $deps, $access, $actor, $data);
        }

        if (PaymentAdminMonthHandler::handleCallback($bot, $deps, $access, $actor, $data)) {
            return true;
        }

        if (str_starts_with($data, 'pay:rt:')) {
            $actorTelegramId = (int) ($actor['telegram_id'] ?? 0);

            return PaymentReminderTextAdminHandler::handleCallback(
                $bot,
                $deps,
                $access,
                $actor,
                $data,
                $actorTelegramId,
            );
        }

        if ($data === 'pay:adm:home') {
            if (!$access->hasConductorPrivileges($actor)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            $deps->flows->clear((int) ($actor['telegram_id'] ?? 0));
            self::renderHomeEdit($bot, $deps, $actor);

            return true;
        }

        if (preg_match('/^pay:adm:(\d{4}):(\d{1,2})$/', $data, $m) !== 1) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        $bot->answerCallbackQuery();
        PaymentAdminMonthHandler::showMonthScreen($bot, $deps, $actor, (int) $m[1], (int) $m[2]);

        return true;
    }
}