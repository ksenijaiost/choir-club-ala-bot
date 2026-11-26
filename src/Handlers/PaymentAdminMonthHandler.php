<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
use App\Services\PaymentAdminMonthPresenter;
use App\Services\PaymentPeriod;
use App\Services\PaymentStatusFormatter;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentAdminMonthHandler
{
    public static function handleCallback(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (str_starts_with($data, 'pay:am:')) {
            if (!$access->hasConductorPrivileges($actor)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('admin.only_conductor_or_owner'),
                    'show_alert' => true,
                ]);

                return true;
            }

            return self::routeAm($bot, $deps, $actor, $data);
        }

        return false;
    }

    public static function showMonthScreen(
        Nutgram $bot,
        PaymentDeps $deps,
        array $actor,
        int $year,
        int $month,
    ): void {
        $actorTg = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        $buckets = PaymentAdminMonthPresenter::classify($deps, $year, $month);
        $text = PaymentAdminMonthPresenter::buildMonthScreenText(
            $deps->texts,
            $deps->paymentTexts,
            $year,
            $month,
            $buckets,
        );

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.confirm_payment'),
                    callback_data: 'pay:am:cf:' . $year . ':' . $month . ':0',
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_month_edit_button'),
                    callback_data: 'pay:am:ed:' . $year . ':' . $month,
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'pay:adm:home',
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $text,
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    private static function routeAm(Nutgram $bot, PaymentDeps $deps, array $actor, string $data): bool
    {
        $conductorUserId = (int) ($actor['id'] ?? 0);
        $actorTg = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);

        if (preg_match('/^pay:am:cf:(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderConfirmList($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:cx:(\d+):(\d{4}):(\d{1,2})(?::(\d+))?$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $memberUserId = (int) $m[1];
            $year = (int) $m[2];
            $month = (int) $m[3];
            $page = isset($m[4]) ? (int) $m[4] : 0;
            self::confirmWithMemberNotify($bot, $deps, $conductorUserId, $memberUserId, $year, $month);
            $buckets = PaymentAdminMonthPresenter::classify($deps, $year, $month);
            $awaitingCount = count($buckets['awaiting']);
            $pageSize = PaymentAdminMonthPresenter::pageSize();
            if ($awaitingCount > 0 && $page * $pageSize >= $awaitingCount) {
                $page = max(0, (int) ceil($awaitingCount / $pageSize) - 1);
            }
            self::renderConfirmList($bot, $deps, $year, $month, $page, $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:ed:(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderEditChooser($bot, $deps, (int) $m[1], (int) $m[2], $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:lp:(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderEditUserList($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], true, $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:lu:(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderEditUserList($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], false, $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:u:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderUserCard($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:mk:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $memberUserId = (int) $m[1];
            $year = (int) $m[2];
            $month = (int) $m[3];
            $deps->payments->confirmByConductor($memberUserId, $year, $month, $conductorUserId);
            $confirmedMember = $deps->users->findById($memberUserId);
            if ($confirmedMember !== null) {
                $deps->achievements->onPaymentConfirmed($confirmedMember, $year, $month, $deps->config);
            }
            self::renderUserCard($bot, $deps, $memberUserId, $year, $month, $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:ur:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderUnconfirmPrompt($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:ux:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->payments->unconfirmByConductor((int) $m[1], (int) $m[2], (int) $m[3]);
            self::renderUserCard($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:mv:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMovePicker($bot, $deps, (int) $m[1], (int) $m[2], (int) $m[3], $actorTg);

            return true;
        }

        if (preg_match('/^pay:am:mx:(\d+):(\d{4}):(\d{1,2}):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $userId = (int) $m[1];
            $fromYear = (int) $m[2];
            $fromMonth = (int) $m[3];
            $toYear = (int) $m[4];
            $toMonth = (int) $m[5];
            $member = $deps->users->findById($userId);
            $moved = $deps->payments->moveToMonth($userId, $fromYear, $fromMonth, $toYear, $toMonth);
            if ($moved) {
                self::renderUserCard($bot, $deps, $userId, $toYear, $toMonth, $actorTg);

                return true;
            }
            if ($member !== null && $deps->payments->find($userId, $toYear, $toMonth) !== null) {
                $toPh = $deps->paymentTexts->monthYearPlaceholders($toYear, $toMonth);
                CallbackUi::editCurrentMessage(
                    $bot,
                    $deps->texts->get('payment.admin_month_move_conflict', [
                        'name' => (string) ($member['display_name'] ?? ''),
                        'month' => $toPh['month'],
                        'year' => $toPh['year'],
                    ]),
                    InlineKeyboardMarkup::make()->addRow(
                        InlineMenuHelper::backButton(
                            $deps->texts,
                            'pay:am:u:' . $userId . ':' . $fromYear . ':' . $fromMonth,
                        ),
                        InlineMenuHelper::closeButton($deps->texts),
                    ),
                    $deps->interactiveMenus,
                    $actorTg,
                );

                return true;
            }
            self::renderUserCard($bot, $deps, $userId, $fromYear, $fromMonth, $actorTg);

            return true;
        }

        return false;
    }

    private static function renderConfirmList(
        Nutgram $bot,
        PaymentDeps $deps,
        int $year,
        int $month,
        int $page,
        int $actorTg,
    ): void {
        $buckets = PaymentAdminMonthPresenter::classify($deps, $year, $month);
        $awaiting = $buckets['awaiting'];
        $total = count($awaiting);

        if ($total === 0) {
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineMenuHelper::backButton($deps->texts, 'pay:adm:' . $year . ':' . $month),
                InlineMenuHelper::closeButton($deps->texts),
            );
            CallbackUi::editCurrentMessage(
                $bot,
                $deps->texts->get('payment.admin_month_all_confirmed'),
                $keyboard,
                $deps->interactiveMenus,
                $actorTg,
            );

            return;
        }

        $ph = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $text = $deps->texts->get('payment.admin_month_confirm_header', $ph);
        $keyboard = InlineKeyboardMarkup::make();

        foreach (PaymentAdminMonthPresenter::pageSlice($awaiting, $page) as $row) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    (string) $row['name'],
                    callback_data: 'pay:am:cx:' . $row['user_id'] . ':' . $year . ':' . $month . ':' . $page,
                ),
            );
        }

        $keyboard = self::appendPagination($keyboard, $page, $total, 'pay:am:cf:' . $year . ':' . $month . ':');
        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'pay:adm:' . $year . ':' . $month,
        );

        CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, $actorTg);
    }

    private static function renderEditChooser(
        Nutgram $bot,
        PaymentDeps $deps,
        int $year,
        int $month,
        int $actorTg,
    ): void {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_month_edit_paid'),
                    callback_data: 'pay:am:lp:' . $year . ':' . $month . ':0',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_month_edit_unpaid'),
                    callback_data: 'pay:am:lu:' . $year . ':' . $month . ':0',
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'pay:adm:' . $year . ':' . $month,
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $deps->texts->get('payment.admin_month_edit_who'),
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    private static function renderEditUserList(
        Nutgram $bot,
        PaymentDeps $deps,
        int $year,
        int $month,
        int $page,
        bool $paidList,
        int $actorTg,
    ): void {
        $buckets = PaymentAdminMonthPresenter::classify($deps, $year, $month);
        if ($paidList) {
            $items = $buckets['paid'];
            $headerKey = 'payment.admin_month_edit_paid';
            $pagePrefix = 'pay:am:lp:' . $year . ':' . $month . ':';
            $backTo = 'pay:am:ed:' . $year . ':' . $month;
        } else {
            $items = [];
            foreach ($buckets['awaiting'] as $row) {
                $items[] = ['user_id' => $row['user_id'], 'name' => $row['name'], 'awaiting' => true];
            }
            foreach ($buckets['unpaid'] as $row) {
                $items[] = ['user_id' => $row['user_id'], 'name' => $row['name'], 'awaiting' => false];
            }
            $headerKey = 'payment.admin_month_edit_unpaid';
            $pagePrefix = 'pay:am:lu:' . $year . ':' . $month . ':';
            $backTo = 'pay:am:ed:' . $year . ':' . $month;
        }

        $total = count($items);
        $ph = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $text = $deps->texts->get($headerKey) . "\n" . $ph['month'] . ' ' . $ph['year'];

        $keyboard = InlineKeyboardMarkup::make();
        foreach (PaymentAdminMonthPresenter::pageSlice($items, $page) as $row) {
            $label = (string) $row['name'];
            if (!$paidList && !empty($row['awaiting'])) {
                $label = '⏳ ' . $label;
            }
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $label,
                    callback_data: 'pay:am:u:' . $row['user_id'] . ':' . $year . ':' . $month,
                ),
            );
        }

        $keyboard = self::appendPagination($keyboard, $page, $total, $pagePrefix);
        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, $backTo);

        CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, $actorTg);
    }

    private static function renderUserCard(
        Nutgram $bot,
        PaymentDeps $deps,
        int $userId,
        int $year,
        int $month,
        int $actorTg,
    ): void {
        $member = $deps->users->findById($userId);
        if ($member === null) {
            return;
        }

        $row = $deps->payments->find($userId, $year, $month);
        $ph = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $status = PaymentStatusFormatter::forRecord($deps->texts, $deps->payments, $row);
        $text = $deps->texts->get('payment.admin_month_user_card', [
            'name' => (string) ($member['display_name'] ?? ''),
            'month' => $ph['month'],
            'year' => $ph['year'],
            'status' => $status,
        ]);

        $keyboard = InlineKeyboardMarkup::make();
        if ($deps->payments->isConductorConfirmed($row)) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_month_unmark_paid'),
                    callback_data: 'pay:am:ur:' . $userId . ':' . $year . ':' . $month,
                ),
            );
        } else {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_month_mark_paid'),
                    callback_data: 'pay:am:mk:' . $userId . ':' . $year . ':' . $month,
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_month_move_button'),
                callback_data: 'pay:am:mv:' . $userId . ':' . $year . ':' . $month,
            ),
        );

        $backCallback = $deps->payments->isConductorConfirmed($row)
            ? 'pay:am:lp:' . $year . ':' . $month . ':0'
            : 'pay:am:lu:' . $year . ':' . $month . ':0';

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, $backCallback);

        CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, $actorTg);
    }

    private static function renderUnconfirmPrompt(
        Nutgram $bot,
        PaymentDeps $deps,
        int $userId,
        int $year,
        int $month,
        int $actorTg,
    ): void {
        $ph = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $text = $deps->texts->get('payment.admin_unconfirm_prompt', $ph);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_unconfirm_yes'),
                    callback_data: 'pay:am:ux:' . $userId . ':' . $year . ':' . $month,
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.admin_unconfirm_cancel'),
                    callback_data: 'pay:am:u:' . $userId . ':' . $year . ':' . $month,
                ),
            );

        CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, $actorTg);
    }

    private static function renderMovePicker(
        Nutgram $bot,
        PaymentDeps $deps,
        int $userId,
        int $year,
        int $month,
        int $actorTg,
    ): void {
        $tz = new \DateTimeZone($deps->config->timezone);
        $local = Clock::now($deps->config)->setTimezone($tz);

        $keyboard = InlineKeyboardMarkup::make();
        foreach (PaymentPeriod::adminMonthChoices($local) as $choice) {
            if ($choice['year'] === $year && $choice['month'] === $month) {
                continue;
            }
            $label = $deps->paymentTexts->monthName($choice['month']) . ' ' . $choice['year'];
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $label,
                    callback_data: 'pay:am:mx:' . $userId . ':' . $year . ':' . $month . ':' . $choice['year'] . ':' . $choice['month'],
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'pay:am:u:' . $userId . ':' . $year . ':' . $month,
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $deps->texts->get('payment.admin_month_move_pick'),
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    private static function confirmWithMemberNotify(
        Nutgram $bot,
        PaymentDeps $deps,
        int $conductorUserId,
        int $memberUserId,
        int $year,
        int $month,
    ): void {
        $member = $deps->users->findById($memberUserId);
        if ($member === null) {
            return;
        }

        $deps->payments->confirmByConductor($memberUserId, $year, $month, $conductorUserId);
        $freshMember = $deps->users->findById($memberUserId) ?? $member;
        $deps->achievements->onPaymentConfirmed($freshMember, $year, $month, $deps->config);

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        SafeTelegram::sendMessage(
            $bot,
            $deps->texts->get('payment.conductor_confirmed_member', $placeholders),
            chatId: (int) $member['telegram_id'],
        );
    }

    private static function appendPagination(
        InlineKeyboardMarkup $keyboard,
        int $page,
        int $total,
        string $prefix,
    ): InlineKeyboardMarkup {
        $pageSize = PaymentAdminMonthPresenter::pageSize();
        if ($total <= $pageSize) {
            return $keyboard;
        }

        $buttons = [];
        if ($page > 0) {
            $buttons[] = InlineKeyboardButton::make('‹', callback_data: $prefix . ($page - 1));
        }
        $offset = $page * $pageSize;
        if ($offset + $pageSize < $total) {
            $buttons[] = InlineKeyboardButton::make('›', callback_data: $prefix . ($page + 1));
        }
        if ($buttons !== []) {
            $keyboard = $keyboard->addRow(...$buttons);
        }

        return $keyboard;
    }
}