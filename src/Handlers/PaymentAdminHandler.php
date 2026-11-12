<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
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

        $tz = new \DateTimeZone($deps->config->timezone);
        $local = Clock::now($deps->config)->setTimezone($tz);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('payment.reminder_texts_button'),
                callback_data: PaymentReminderTextAdminHandler::CB_HOME,
            ),
        );
        for ($i = 0; $i < 3; $i++) {
            $cursor = $local->modify('-' . $i . ' months');
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $label = $deps->paymentTexts->monthName($month) . ' ' . $year;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'pay:adm:' . $year . ':' . $month),
            );
        }

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts);

        $bot->sendMessage($deps->texts->get('payment.admin_pick_month'), reply_markup: $keyboard);
    }

    public static function handleCallback(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
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
            CallbackUi::clearKeyboard($bot);
            $deps->flows->clear((int) ($actor['telegram_id'] ?? 0));
            self::openHome($bot, $deps, $access, $actor);

            return true;
        }

        if (preg_match('/^pay:adm:(\d{4}):(\d{1,2}):pg:(\d+)$/', $data, $m) === 1) {
            if (!$access->hasConductorPrivileges($actor)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMonthMemberList($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3]);

            return true;
        }

        if (preg_match('/^pay:adm:(\d{4}):(\d{1,2})$/', $data, $m) !== 1) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $bot->answerCallbackQuery();
        CallbackUi::clearKeyboard($bot);
        self::sendMonthMemberList($bot, $deps, $year, $month, 0);

        return true;
    }

    private static function sendMonthMemberList(
        Nutgram $bot,
        PaymentDeps $deps,
        int $year,
        int $month,
        int $page,
    ): void {
        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $lines = [$deps->texts->get('payment.admin_list_header', $placeholders)];

        $all = $deps->users->listActivePayers();
        $total = count($all);
        $keyboard = InlineKeyboardMarkup::make();

        foreach (InlineMenuHelper::pageSlice($all, $page) as $user) {
            $uid = (int) $user['id'];
            $name = (string) ($user['display_name'] ?? '');
            $row = $deps->payments->find($uid, $year, $month);
            $status = self::statusLine($deps, $row);
            $lines[] = '• ' . $name . ' — ' . $status;

            if (!$deps->payments->isConductorConfirmed($row)) {
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make(
                        $name . ' ✓',
                        callback_data: 'pay:cf:' . $uid . ':' . $year . ':' . $month,
                    ),
                );
            }
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'pay:adm:' . $year . ':' . $month . ':pg:',
            'pay:adm:home',
        );

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function statusLine(PaymentDeps $deps, ?array $row): string
    {
        if ($deps->payments->isConductorConfirmed($row)) {
            return $deps->texts->get('payment.status_confirmed');
        }

        if ($deps->payments->hasMemberClaim($row)) {
            return $deps->texts->get('payment.status_awaiting');
        }

        return $deps->texts->get('payment.status_unpaid');
    }
}
