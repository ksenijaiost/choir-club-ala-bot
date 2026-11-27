<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\TelegramOutbound;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ChoirWrappedAdminHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function handleCallback(
        Nutgram $bot,
        AttendanceDeps $deps,
        AccessControl $access,
        AdminChatResolver $adminChat,
        TelegramOutbound $outbound,
        array $actor,
        string $data,
    ): bool {
        if ($data === 'atd:sw') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendPresetPicker($bot, $deps, $actor);

            return true;
        }

        if (preg_match('/^atd:sw:pr:([36y])$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendPreview($bot, $deps, $m[1], $actor);

            return true;
        }

        if (preg_match('/^atd:sw:ok:([36y])$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            self::sendConfirmPrompt($bot, $deps, $m[1], $actor);

            return true;
        }

        if (preg_match('/^atd:sw:send:([36y])$/', $data, $m) === 1) {
            if (!$access->hasConductorPrivileges($actor)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_conductor_or_owner'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::broadcastSeason($bot, $deps, $adminChat, $outbound, $m[1], $actor);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function sendPresetPicker(Nutgram $bot, AttendanceDeps $deps, array $actor): void
    {
        $texts = $deps->texts;
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make($texts->get('wrapped.preset_3'), callback_data: 'atd:sw:pr:3'))
            ->addRow(InlineKeyboardButton::make($texts->get('wrapped.preset_6'), callback_data: 'atd:sw:pr:6'))
            ->addRow(InlineKeyboardButton::make($texts->get('wrapped.preset_year'), callback_data: 'atd:sw:pr:y'));
        $keyboard = InlineMenuHelper::appendFooter($keyboard, $texts, 'atd:');

        self::sendMenu($bot, $deps, $actor, $texts->get('wrapped.preset_picker'), $keyboard);
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function sendPreview(Nutgram $bot, AttendanceDeps $deps, string $preset, array $actor): void
    {
        $period = $deps->wrapped->presetPeriod($deps->config, $preset);
        $admin = $deps->wrapped->buildPeriodAdminSummary(
            $deps->config,
            $period['year'],
            $period['month'],
            $period['year2'],
            $period['month2'],
        );
        $body = $deps->wrapped->formatPeriodAdminMessage($admin);
        $texts = $deps->texts;
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make($texts->get('wrapped.broadcast_button'), callback_data: 'atd:sw:ok:' . $preset))
            ->addRow(InlineKeyboardButton::make($texts->get('wrapped.back_presets'), callback_data: 'atd:sw'));
        $keyboard = InlineMenuHelper::appendFooter($keyboard, $texts, 'atd:');

        self::sendMenu($bot, $deps, $actor, $body, $keyboard);
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function sendConfirmPrompt(Nutgram $bot, AttendanceDeps $deps, string $preset, array $actor): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($deps->texts->get('wrapped.confirm_send'), callback_data: 'atd:sw:send:' . $preset),
                InlineKeyboardButton::make($deps->texts->get('wrapped.confirm_cancel'), callback_data: 'atd:sw:pr:' . $preset),
            );
        CallbackUi::editCurrentMessage(
            $bot,
            $deps->texts->get('wrapped.confirm_body'),
            $keyboard,
            $deps->interactiveMenus,
            (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function broadcastSeason(
        Nutgram $bot,
        AttendanceDeps $deps,
        AdminChatResolver $adminChat,
        TelegramOutbound $outbound,
        string $preset,
        array $actor,
    ): int {
        $period = $deps->wrapped->presetPeriod($deps->config, $preset);
        $adminSummary = $deps->wrapped->buildPeriodAdminSummary(
            $deps->config,
            $period['year'],
            $period['month'],
            $period['year2'],
            $period['month2'],
        );
        $adminText = $deps->wrapped->formatPeriodAdminMessage($adminSummary);

        $sent = 0;
        foreach ($deps->users->listAttendanceParticipants() as $user) {
            if (\App\Services\VoicePartRules::isConductor($user)) {
                continue;
            }
            $memberSummary = $deps->wrapped->buildPeriodMemberSummary(
                $user,
                $deps->config,
                $period['year'],
                $period['month'],
                $period['year2'],
                $period['month2'],
            );
            $outbound->sendMessage(
                (int) $user['telegram_id'],
                $deps->wrapped->formatPeriodMemberMessage($memberSummary),
            );
            $sent++;
        }

        $chatId = $adminChat->resolvedIdAsInt();
        if ($chatId !== null) {
            $outbound->sendMessage($chatId, $adminText);
        }

        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $deps->texts, 'atd:');
        self::sendMenu(
            $bot,
            $deps,
            $actor,
            $deps->texts->get('wrapped.broadcast_done', ['count' => (string) $sent]),
            $keyboard,
        );

        return $sent;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function sendMenu(
        Nutgram $bot,
        AttendanceDeps $deps,
        array $actor,
        string $text,
        InlineKeyboardMarkup $keyboard,
    ): void {
        $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $text,
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }
}