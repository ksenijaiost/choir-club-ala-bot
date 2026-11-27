<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
use App\Services\PaymentPeriod;
use App\Services\PaymentStatusFormatter;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentAdminMemberHandler
{
    private const PICKER_PAGE_SIZE = 10;

    public static function handleCallback(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'pa:')) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('admin.only_conductor_or_owner'),
                'show_alert' => true,
            ]);

            return true;
        }

        $actorTg = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        $conductorUserId = (int) ($actor['id'] ?? 0);

        if (preg_match('/^pa:p:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderPicker($bot, $deps, (int) $m[1], $actorTg);

            return true;
        }

        if (preg_match('/^pa:u:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMemberCard($bot, $deps, (int) $m[1], 0, $actorTg, 'card');

            return true;
        }

        if (preg_match('/^pa:w:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMemberCard($bot, $deps, (int) $m[1], (int) $m[2], $actorTg, 'card');

            return true;
        }

        if (preg_match('/^pa:bw:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMemberCard($bot, $deps, (int) $m[1], (int) $m[2], $actorTg, 'bulk');

            return true;
        }

        if (preg_match('/^pa:m:(\d+):(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $userId = (int) $m[1];
            $year = (int) $m[2];
            $month = (int) $m[3];
            $offset = (int) $m[4];
            $row = $deps->payments->find($userId, $year, $month);
            if ($deps->payments->isConductorConfirmed($row)) {
                self::renderUnconfirmPrompt($bot, $deps, $userId, $year, $month, $offset, $actorTg);
            } else {
                $deps->payments->confirmByConductor($userId, $year, $month, $conductorUserId);
                $confirmedMember = $deps->users->findById($userId);
                if ($confirmedMember !== null) {
                    $deps->achievements->onPaymentConfirmed($confirmedMember, $year, $month, $deps->config);
                }
                self::renderMemberCard($bot, $deps, $userId, $offset, $actorTg, 'card');
            }

            return true;
        }

        if (preg_match('/^pa:tu:(\d+):(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->payments->unconfirmByConductor((int) $m[1], (int) $m[2], (int) $m[3]);
            self::renderMemberCard($bot, $deps, (int) $m[1], (int) $m[4], $actorTg, 'card');

            return true;
        }

        if (preg_match('/^pa:tc:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMemberCard($bot, $deps, (int) $m[1], (int) $m[2], $actorTg, 'card');

            return true;
        }

        if (preg_match('/^pa:bk:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMemberCard($bot, $deps, (int) $m[1], (int) $m[2], $actorTg, 'bulk');

            return true;
        }

        if (preg_match('/^pa:bt:(\d+):(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderBulkConfirmPrompt(
                $bot,
                $deps,
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                (int) $m[4],
                $actorTg,
            );

            return true;
        }

        if (preg_match('/^pa:by:(\d+):(\d{4}):(\d{1,2}):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::executeBulkConfirm(
                $bot,
                $deps,
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                $conductorUserId,
                (int) $m[4],
                $actorTg,
            );

            return true;
        }

        if (preg_match('/^pa:bn:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::renderMemberCard($bot, $deps, (int) $m[1], (int) $m[2], $actorTg, 'bulk');

            return true;
        }

        return false;
    }

    private static function renderPicker(Nutgram $bot, PaymentDeps $deps, int $page, int $actorTg): void
    {
        $all = $deps->users->listActivePayers();
        $total = count($all);
        $offset = $page * self::PICKER_PAGE_SIZE;
        $slice = array_slice($all, $offset, self::PICKER_PAGE_SIZE);

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($slice as $user) {
            $uid = (int) $user['id'];
            $last = $deps->payments->findLastConfirmed($uid);
            $displayName = (string) ($user['display_name'] ?? '');
            if ($last === null) {
                $label = $deps->texts->get('payment.admin_member_picker_button_name_only', [
                    'name' => $displayName,
                ]);
            } else {
                $lastPh = $deps->paymentTexts->monthYearPlaceholders(
                    (int) $last['period_year'],
                    (int) $last['period_month'],
                );
                $label = $deps->texts->get('payment.admin_member_picker_button', [
                    'name' => $displayName,
                    'month' => $lastPh['month'],
                    'year' => $lastPh['year'],
                ]);
            }

            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'pa:u:' . $uid),
            );
        }

        $nav = self::paginationButtons($page, $total);
        if ($nav !== []) {
            $keyboard = $keyboard->addRow(...$nav);
        }

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'pay:adm:home');

        CallbackUi::editCurrentMessage(
            $bot,
            $deps->texts->get('payment.admin_member_picker_header'),
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    /**
     * @return list<InlineKeyboardButton>
     */
    private static function paginationButtons(int $page, int $totalItems): array
    {
        if ($totalItems <= self::PICKER_PAGE_SIZE) {
            return [];
        }

        $buttons = [];
        if ($page > 0) {
            $buttons[] = InlineKeyboardButton::make('‹', callback_data: 'pa:p:' . ($page - 1));
        }

        if (($page + 1) * self::PICKER_PAGE_SIZE < $totalItems) {
            $buttons[] = InlineKeyboardButton::make('›', callback_data: 'pa:p:' . ($page + 1));
        }

        return $buttons;
    }

    private static function renderMemberCard(
        Nutgram $bot,
        PaymentDeps $deps,
        int $memberUserId,
        int $windowOffset,
        int $actorTg,
        string $mode,
    ): void {
        $member = $deps->users->findById($memberUserId);
        if ($member === null) {
            return;
        }

        $prefix = $mode === 'bulk' ? 'pa:bt:' : 'pa:m:';
        $text = self::memberCardText($deps, $member, $windowOffset);
        if ($mode === 'bulk') {
            $text .= "\n\n" . $deps->texts->get('payment.admin_bulk_pick_month');
        }

        $keyboard = $mode === 'bulk'
            ? self::buildBulkMonthGridKeyboard($deps, $memberUserId, $windowOffset, $prefix)
            : self::buildMonthGridKeyboard($deps, $memberUserId, $windowOffset, $prefix);

        CallbackUi::editCurrentMessage(
            $bot,
            $text,
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    /**
     * @param array<string, mixed> $member
     */
    private static function memberCardText(PaymentDeps $deps, array $member, int $windowOffset): string
    {
        $name = (string) ($member['display_name'] ?? '');
        $last = $deps->payments->findLastConfirmed((int) $member['id']);
        if ($last === null) {
            return $deps->texts->get('payment.admin_member_card_none', ['name' => $name]);
        }

        $ph = $deps->paymentTexts->monthYearPlaceholders((int) $last['period_year'], (int) $last['period_month']);

        return $deps->texts->get('payment.admin_member_card_header', [
            'name' => $name,
            'month' => $ph['month'],
            'year' => $ph['year'],
        ]);
    }

    private static function localNow(PaymentDeps $deps): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($deps->config->timezone);

        return Clock::now($deps->config)->setTimezone($tz);
    }

    private static function buildMonthGridKeyboard(
        PaymentDeps $deps,
        int $memberUserId,
        int $windowOffset,
        string $monthCallbackPrefix,
    ): InlineKeyboardMarkup {
        $months = PaymentPeriod::monthWindowEndingAt(self::localNow($deps), $windowOffset);
        $keyboard = self::monthRowsKeyboard($deps, $memberUserId, $months, $monthCallbackPrefix, $windowOffset);

        $nav = [
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_months_earlier'),
                callback_data: 'pa:w:' . $memberUserId . ':' . ($windowOffset + 12),
            ),
        ];
        if ($windowOffset > 0) {
            $nav[] = InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_months_later'),
                callback_data: 'pa:w:' . $memberUserId . ':' . ($windowOffset - 12),
            );
        }
        $keyboard = $keyboard->addRow(...$nav);

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_paid_through_button'),
                callback_data: 'pa:bk:' . $memberUserId . ':' . $windowOffset,
            ),
        );

        return InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'pa:p:0',
        );
    }

    private static function buildBulkMonthGridKeyboard(
        PaymentDeps $deps,
        int $memberUserId,
        int $windowOffset,
        string $monthCallbackPrefix,
    ): InlineKeyboardMarkup {
        $months = PaymentPeriod::monthWindowEndingAt(self::localNow($deps), $windowOffset);
        $keyboard = self::monthRowsKeyboard($deps, $memberUserId, $months, $monthCallbackPrefix, $windowOffset);

        $nav = [
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_months_earlier'),
                callback_data: 'pa:bw:' . $memberUserId . ':' . ($windowOffset + 12),
            ),
        ];
        if ($windowOffset > 0) {
            $nav[] = InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_months_later'),
                callback_data: 'pa:bw:' . $memberUserId . ':' . ($windowOffset - 12),
            );
        }
        $keyboard = $keyboard->addRow(...$nav);

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('ui.back'),
                callback_data: 'pa:tc:' . $memberUserId . ':' . $windowOffset,
            ),
        );

        return InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'pa:p:0',
        );
    }

    /**
     * @param list<array{year: int, month: int}> $months
     */
    private static function monthRowsKeyboard(
        PaymentDeps $deps,
        int $memberUserId,
        array $months,
        string $callbackPrefix,
        int $windowOffset,
    ): InlineKeyboardMarkup {
        $keyboard = InlineKeyboardMarkup::make();
        $row = [];
        foreach ($months as $i => $ym) {
            $rowData = $deps->payments->find($memberUserId, $ym['year'], $ym['month']);
            $emoji = PaymentStatusFormatter::emojiForRecord($deps->payments, $rowData);
            $label = $deps->paymentTexts->monthShort($ym['month']) . ' ' . $emoji;
            $row[] = InlineKeyboardButton::make(
                $label,
                callback_data: $callbackPrefix . $memberUserId . ':' . $ym['year'] . ':' . $ym['month'] . ':' . $windowOffset,
            );
            if (count($row) === 3 || $i === count($months) - 1) {
                $keyboard = $keyboard->addRow(...$row);
                $row = [];
            }
        }

        return $keyboard;
    }

    private static function renderUnconfirmPrompt(
        Nutgram $bot,
        PaymentDeps $deps,
        int $memberUserId,
        int $year,
        int $month,
        int $windowOffset,
        int $actorTg,
    ): void {
        $member = $deps->users->findById($memberUserId);
        if ($member === null) {
            return;
        }

        $ph = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $text = self::memberCardText($deps, $member, $windowOffset) . "\n\n"
            . $deps->texts->get('payment.admin_unconfirm_prompt', $ph);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_unconfirm_yes'),
                callback_data: 'pa:tu:' . $memberUserId . ':' . $year . ':' . $month . ':' . $windowOffset,
            ),
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_unconfirm_cancel'),
                callback_data: 'pa:tc:' . $memberUserId . ':' . $windowOffset,
            ),
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $text,
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    private static function renderBulkConfirmPrompt(
        Nutgram $bot,
        PaymentDeps $deps,
        int $memberUserId,
        int $year,
        int $month,
        int $windowOffset,
        int $actorTg,
    ): void {
        $member = $deps->users->findById($memberUserId);
        if ($member === null) {
            return;
        }

        $ph = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $text = self::memberCardText($deps, $member, $windowOffset) . "\n\n"
            . $deps->texts->get('payment.admin_bulk_prompt', $ph);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_bulk_yes'),
                callback_data: 'pa:by:' . $memberUserId . ':' . $year . ':' . $month . ':' . $windowOffset,
            ),
            InlineKeyboardButton::make(
                $deps->texts->get('payment.admin_bulk_cancel'),
                callback_data: 'pa:bn:' . $memberUserId . ':' . $windowOffset,
            ),
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $text,
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
        );
    }

    private static function executeBulkConfirm(
        Nutgram $bot,
        PaymentDeps $deps,
        int $memberUserId,
        int $endYear,
        int $endMonth,
        int $conductorUserId,
        int $windowOffset,
        int $actorTg,
    ): void {
        $end = ['year' => $endYear, 'month' => $endMonth];
        $last = $deps->payments->findLastConfirmed($memberUserId);
        $start = $last === null
            ? $end
            : PaymentPeriod::nextMonth((int) $last['period_year'], (int) $last['period_month']);

        foreach (PaymentPeriod::monthsInclusive($start, $end) as $ym) {
            $row = $deps->payments->find($memberUserId, $ym['year'], $ym['month']);
            if (!$deps->payments->isConductorConfirmed($row)) {
                $deps->payments->confirmByConductor($memberUserId, $ym['year'], $ym['month'], $conductorUserId);
                $confirmedMember = $deps->users->findById($memberUserId);
                if ($confirmedMember !== null) {
                    $deps->achievements->onPaymentConfirmed($confirmedMember, $ym['year'], $ym['month'], $deps->config);
                }
            }
        }

        self::renderMemberCard($bot, $deps, $memberUserId, $windowOffset, $actorTg, 'card');
    }
}