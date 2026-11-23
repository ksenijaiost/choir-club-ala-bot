<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\PaymentRecordRepository;
use App\Services\PaymentPeriod;
use App\Services\PaymentStatusFormatter;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentMemberHandler
{
    public const FLOW_DEFER_COMMENT = 'payment_defer_comment';

    /**
     * @param array<string, mixed> $user
     */
    public static function openHome(Nutgram $bot, PaymentDeps $deps, array $user): void
    {
        if (!self::isActivePayer($user)) {
            return;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $local = Clock::now($deps->config)->setTimezone($tz);
        $current = PaymentPeriod::currentMonth($local);
        $previous = PaymentPeriod::previousMonth($local);
        $userId = (int) $user['id'];

        $lines = [];

        $lastConfirmed = $deps->payments->findLastConfirmed($userId);
        if ($lastConfirmed === null) {
            $lines[] = $deps->texts->get('payment.screen_last_none');
        } else {
            $lastPh = $deps->paymentTexts->monthYearPlaceholders(
                (int) $lastConfirmed['period_year'],
                (int) $lastConfirmed['period_month'],
            );
            $lines[] = $deps->texts->get('payment.screen_last_confirmed', $lastPh);
        }

        $currentRow = $deps->payments->find($userId, $current['year'], $current['month']);
        $currentPh = $deps->paymentTexts->monthYearPlaceholders($current['year'], $current['month']);
        $lines[] = $deps->texts->get('payment.screen_current_status', [
            'month' => $currentPh['month'],
            'year' => $currentPh['year'],
            'status' => PaymentStatusFormatter::forRecord($deps->texts, $deps->payments, $currentRow),
        ]);

        $keyboard = InlineKeyboardMarkup::make();
        $hasButtons = false;

        if (!$deps->payments->isConductorConfirmed($currentRow)) {
            $hasButtons = true;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.screen_claim_current', $currentPh),
                    callback_data: 'pay:ok:' . $current['year'] . ':' . $current['month'],
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.pay_later'),
                    callback_data: 'pay:later:' . $current['year'] . ':' . $current['month'],
                ),
            );
        }

        $prevRow = $deps->payments->find($userId, $previous['year'], $previous['month']);
        if (
            !$deps->payments->isConductorConfirmed($prevRow)
            && !$deps->payments->hasMemberClaim($prevRow)
            && !($deps->payments->isMemberDeferred($prevRow))
        ) {
            $prevPh = $deps->paymentTexts->monthYearPlaceholders($previous['year'], $previous['month']);
            $hasButtons = true;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('payment.screen_claim_previous', $prevPh),
                    callback_data: 'pay:ok:' . $previous['year'] . ':' . $previous['month'],
                ),
            );
        }

        SafeTelegram::sendMessage(
            $bot,
            implode("\n\n", $lines),
            replyMarkup: $hasButtons ? $keyboard : null,
        );
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        PaymentDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW_DEFER_COMMENT) {
            return false;
        }

        $ctx = $state['context'] ?? [];
        $year = (int) ($ctx['year'] ?? 0);
        $month = (int) ($ctx['month'] ?? 0);
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || $year < 1 || $month < 1 || $month > 12) {
            $deps->flows->clear($telegramId);

            return true;
        }

        $comment = trim($text);
        if (mb_strlen($comment) > PaymentRecordRepository::MEMBER_COMMENT_MAX) {
            $bot->sendMessage($deps->texts->get('payment.defer_comment_too_long'));

            return true;
        }

        self::completeDefer($bot, $deps, $user, $year, $month, $comment === '' ? null : $comment);
        $deps->flows->clear($telegramId);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function completeDefer(
        Nutgram $bot,
        PaymentDeps $deps,
        array $user,
        int $year,
        int $month,
        ?string $comment,
    ): void {
        if (!self::isActivePayer($user)) {
            return;
        }

        $deps->payments->deferByMember((int) $user['id'], $year, $month, $comment);

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        SafeTelegram::sendMessage(
            $bot,
            $deps->texts->get('payment.member_deferred', $placeholders),
        );

        self::notifyConductorDefer($bot, $deps, $user, $year, $month, $comment);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function notifyConductorDefer(
        Nutgram $bot,
        PaymentDeps $deps,
        array $user,
        int $year,
        int $month,
        ?string $comment,
    ): void {
        $conductorTg = $deps->recipients->resolveTelegramId();
        if ($conductorTg === null) {
            return;
        }

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $commentText = trim((string) $comment);
        $textKey = $commentText !== ''
            ? 'payment.conductor_deferred_with_comment'
            : 'payment.conductor_deferred_no_comment';

        SafeTelegram::sendMessage(
            $bot,
            $deps->texts->get($textKey, [
                'name' => (string) ($user['display_name'] ?? ''),
                'month' => $placeholders['month'],
                'year' => $placeholders['year'],
                'comment' => $commentText,
            ]),
            chatId: $conductorTg,
        );
    }

    public static function beginDeferCommentFlow(
        Nutgram $bot,
        PaymentDeps $deps,
        int $telegramId,
        int $year,
        int $month,
    ): void {
        $deps->flows->set($telegramId, self::FLOW_DEFER_COMMENT, [
            'year' => $year,
            'month' => $month,
        ]);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('buttons.pay_later_skip'),
                callback_data: 'pay:later_skip:' . $year . ':' . $month,
            ),
        );

        SafeTelegram::sendMessage(
            $bot,
            $deps->texts->get('payment.defer_comment_prompt'),
            replyMarkup: $keyboard,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isActivePayer(array $user): bool
    {
        if (($user['status'] ?? '') !== UserStatus::ACTIVE) {
            return false;
        }

        $role = (string) ($user['role'] ?? '');

        return $role === UserRole::MEMBER
            || $role === UserRole::ADMIN
            || $role === UserRole::OWNER;
    }
}