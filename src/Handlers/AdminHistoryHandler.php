<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Repositories\PaymentRecordRepository;
use App\Services\AccessControl;
use App\Services\AttendanceHistoryPresenter;
use App\Services\PaymentTexts;
use App\Services\UserRolePresenter;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AdminHistoryHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function openHome(
        Nutgram $bot,
        AccessControl $access,
        array $actor,
        AdminHistoryContext $ctx,
    ): void {
        if (!$access->hasConductorPrivileges($actor)) {
            $bot->sendMessage($ctx->texts->get('admin.only_conductor_or_owner'));

            return;
        }

        self::sendMemberPicker($bot, $ctx->texts, $ctx->users, $ctx->roles, 0);
    }

    public static function sendMemberPicker(
        Nutgram $bot,
        TextRepository $texts,
        \App\Repositories\UserRepository $users,
        UserRolePresenter $roles,
        int $page,
    ): void {
        $all = $users->listSingingMembersForAdmin(null, false);
        $total = count($all);
        if ($total === 0) {
            $bot->sendMessage($texts->get('history.no_members'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($all, $page) as $member) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $roles->displayNameWithBadge($member),
                    callback_data: 'hst:u:' . (int) $member['id'],
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $texts,
            $page,
            $total,
            'hst:pg:',
        );

        $bot->sendMessage($texts->get('history.pick_member'), reply_markup: $keyboard);
    }

  public static function sendMemberReport(
        Nutgram $bot,
        AdminHistoryContext $ctx,
        int $userId,
    ): void {
        $user = $ctx->users->findById($userId);
        if ($user === null) {
            $bot->sendMessage($ctx->texts->get('errors.generic'));

            return;
        }

        $tz = new \DateTimeZone($ctx->config->timezone);
        $now = Clock::now($ctx->config)->setTimezone($tz);
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $prev = $now->modify('first day of last month');
        $prevYear = (int) $prev->format('Y');
        $prevMonth = (int) $prev->format('n');

        $partCode = (string) ($user['voice_part'] ?? '');
        $partLabel = $partCode !== ''
            ? $ctx->texts->get('parts.' . $partCode)
            : $ctx->texts->get('profile.part_none');

        $lines = [
            $ctx->texts->get('history.report_header', [
                'name' => $ctx->roles->displayNameWithBadge($user),
                'part' => $partLabel,
            ]),
            $ctx->texts->get('history.streak_line', [
                'streak' => (string) (int) ($user['consecutive_unexcused'] ?? 0),
            ]),
            $ctx->texts->get('history.payment_line', [
                'current' => self::paymentLine($ctx, $userId, $currentYear, $currentMonth),
                'previous' => self::paymentLine($ctx, $userId, $prevYear, $prevMonth),
            ]),
            '',
            $ctx->texts->get('history.rehearsals_header'),
        ];

        $history = $ctx->marks->listForUserRecent($userId, 10);
        if ($history === []) {
            $lines[] = $ctx->texts->get('attendance.history_empty');
        } else {
            foreach ($history as $row) {
                $lines[] = $ctx->history->formatAdminMemberLine($row, $tz);
            }
        }

        $keyboard = InlineMenuHelper::appendFooter(
            InlineKeyboardMarkup::make(),
            $ctx->texts,
            'hst:pg:0',
        );

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }

    private static function paymentLine(AdminHistoryContext $ctx, int $userId, int $year, int $month): string
    {
        $placeholders = $ctx->paymentTexts->monthYearPlaceholders($year, $month);
        $row = $ctx->payments->find($userId, $year, $month);

        if ($ctx->payments->isConductorConfirmed($row)) {
            $status = $ctx->texts->get('payment.status_confirmed');
        } elseif ($ctx->payments->hasMemberClaim($row)) {
            $status = $ctx->texts->get('payment.status_awaiting');
        } else {
            $status = $ctx->texts->get('payment.status_unpaid');
        }

        return $ctx->texts->get('history.payment_month', [
            'month' => $placeholders['month'],
            'year' => $placeholders['year'],
            'status' => $status,
        ]);
    }

    public static function handleCallback(
        Nutgram $bot,
        AdminHistoryContext $ctx,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'hst:')) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            $bot->answerCallbackQuery(text: $ctx->texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        if (preg_match('/^hst:pg:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMemberPicker($bot, $ctx->texts, $ctx->users, $ctx->roles, (int) $m[1]);

            return true;
        }

        if (preg_match('/^hst:u:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMemberReport($bot, $ctx, (int) $m[1]);

            return true;
        }

        return false;
    }
}
