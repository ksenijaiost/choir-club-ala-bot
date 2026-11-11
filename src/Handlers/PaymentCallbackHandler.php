<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
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

        if (preg_match('/^pay:cf:(\d+):(\d{4}):(\d{1,2})$/', $data, $m) === 1) {
            $actor = $deps->users->findByTelegramId($actorTelegramId);
            if ($actor === null || !$access->hasConductorPrivileges($actor)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

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
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::ACTIVE) {
            $bot->answerCallbackQuery();

            return;
        }

        $role = (string) ($user['role'] ?? '');
        if (
            $role !== UserRole::MEMBER
            && $role !== UserRole::ADMIN
            && $role !== UserRole::OWNER
        ) {
            $bot->answerCallbackQuery();

            return;
        }

        $deps->payments->claimByMember((int) $user['id'], $year, $month);
        $bot->answerCallbackQuery();
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('payment.inline_answer_claimed'));
        $bot->sendMessage($deps->texts->get('payment.member_marked'));

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

        $bot->sendMessage(
            chat_id: $conductorTg,
            text: $deps->texts->get('payment.conductor_confirm_prompt', [
                'name' => (string) ($user['display_name'] ?? ''),
                'month' => $placeholders['month'],
                'year' => $placeholders['year'],
            ]),
            reply_markup: $keyboard,
        );
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
            $bot->answerCallbackQuery();

            return;
        }

        $deps->payments->confirmByConductor($memberUserId, $year, $month, (int) $conductor['id']);
        $bot->answerCallbackQuery(text: $deps->texts->get('payment.admin_confirmed_ok'));
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('payment.inline_answer_confirmed'));

        $placeholders = $deps->paymentTexts->monthYearPlaceholders($year, $month);
        $bot->sendMessage(
            chat_id: (int) $member['telegram_id'],
            text: $deps->texts->get('payment.conductor_confirmed_member', $placeholders),
        );
    }
}
