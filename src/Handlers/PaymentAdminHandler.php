<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
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

        $keyboard = InlineKeyboardMarkup::make();
        for ($i = 0; $i < 3; $i++) {
            $cursor = $local->modify('-' . $i . ' months');
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $label = $deps->paymentTexts->monthName($month) . ' ' . $year;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'pay:adm:' . $year . ':' . $month),
            );
        }

        $bot->sendMessage($deps->texts->get('payment.admin_pick_month'), reply_markup: $keyboard);
    }

    public static function handleCallback(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
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

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $lines = [$deps->texts->get('payment.admin_list_header', $placeholders)];

        $keyboard = InlineKeyboardMarkup::make();

        foreach ($deps->users->listActivePayers() as $user) {
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

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);

        return true;
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
