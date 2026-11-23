<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
use App\Services\PaymentPeriod;
use App\Telegram\CallbackUi;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentCallbackHandler
{
    public static function handle(
        Nutgram $bot,
        PaymentDeps $deps,
        AccessControl $access,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (preg_match('/^pay:ok:(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            self::handleMemberClaim($bot, $deps, $actorTelegramId, (int) $m[1], (int) $m[2]);

            return true;
        }

        if (preg_match('/^pay:later:(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            self::handlePayLater($bot, $deps, $actorTelegramId, (int) $m[1], (int) $m[2]);

            return true;
        }

        if (preg_match('/^pay:later_skip:(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            self::handlePayLaterSkip($bot, $deps, $actorTelegramId, (int) $m[1], (int) $m[2]);

            return true;
        }

        if (preg_match('/^pay:cf:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            $actor = $deps->users->findByTelegramId($actorTelegramId);
            if ($actor === null || !$access->hasConductorPrivileges($actor)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('admin.only_conductor_or_owner'),
                    'show_alert' => true,
                ]);

                return true;
            }

            self::handleConductorConfirm($bot, $deps, $actor, (int) $m[1], (int) $m[2], (int) $m[3]);

            return true;
        }

        return false;
    }

    private static function handleMemberClaim(
        Nutgram $bot,
        PaymentDeps $deps,
        int $telegramId,
        int $year,
        int $month,
    ): void {
        $tz = new \DateTimeZone($deps->config->timezone);
        $local = Clock::now($deps->config)->setTimezone($tz);
        if (!PaymentPeriod::isInMemberClaimWindow($local, $year, $month)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || !PaymentMemberHandler::isActivePayer($user)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        $row = $deps->payments->find((int) $user['id'], $year, $month);
        if ($deps->payments->isConductorConfirmed($row) || $deps->payments->hasMemberClaim($row)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        $deps->payments->claimByMember((int) $user['id'], $year, $month);
        SafeTelegram::answerCallbackQuery($bot);
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('payment.inline_answer_claimed'));
        SafeTelegram::sendMessage($bot, $deps->texts->get('payment.member_marked'));

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $conductorTg = $deps->recipients->resolveTelegramId();
        if ($conductorTg === null) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('buttons.confirm_payment'),
                callback_data: 'pay:cf:' . (int) $user['id'] . ':' . $year . ':' . $month,
            ),
        );

        SafeTelegram::sendMessage(
            $bot,
            $deps->texts->get('payment.conductor_confirm_prompt', [
                'name' => (string) ($user['display_name'] ?? ''),
                'month' => $placeholders['month'],
                'year' => $placeholders['year'],
            ]),
            chatId: $conductorTg,
            replyMarkup: $keyboard,
        );
    }

    private static function handlePayLater(
        Nutgram $bot,
        PaymentDeps $deps,
        int $telegramId,
        int $year,
        int $month,
    ): void {
        $tz = new \DateTimeZone($deps->config->timezone);
        $local = Clock::now($deps->config)->setTimezone($tz);
        $current = PaymentPeriod::currentMonth($local);
        if (!PaymentPeriod::matchesMonth($year, $month, $current)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || !PaymentMemberHandler::isActivePayer($user)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        $row = $deps->payments->find((int) $user['id'], $year, $month);
        if ($deps->payments->isConductorConfirmed($row)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        SafeTelegram::answerCallbackQuery($bot);
        PaymentMemberHandler::beginDeferCommentFlow($bot, $deps, $telegramId, $year, $month);
    }

    private static function handlePayLaterSkip(
        Nutgram $bot,
        PaymentDeps $deps,
        int $telegramId,
        int $year,
        int $month,
    ): void {
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || !PaymentMemberHandler::isActivePayer($user)) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        SafeTelegram::answerCallbackQuery($bot);
        $deps->flows->clear($telegramId);
        PaymentMemberHandler::completeDefer($bot, $deps, $user, $year, $month, null);
    }

    /**
     * @param array<string, mixed> $conductor
     */
    private static function handleConductorConfirm(
        Nutgram $bot,
        PaymentDeps $deps,
        array $conductor,
        int $memberUserId,
        int $year,
        int $month,
    ): void {
        $member = $deps->users->findById($memberUserId);
        if ($member === null) {
            SafeTelegram::answerCallbackQuery($bot);

            return;
        }

        $deps->payments->confirmByConductor($memberUserId, $year, $month, (int) $conductor['id']);
        SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('payment.admin_confirmed_ok')]);
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('payment.inline_answer_confirmed'));

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        SafeTelegram::sendMessage(
            $bot,
            $deps->texts->get('payment.conductor_confirmed_member', $placeholders),
            chatId: (int) $member['telegram_id'],
        );
    }
}