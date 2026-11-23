<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserRole;
use App\Domain\VoicePart;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\VoicePartRules;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\InteractiveMenuService;
use App\Telegram\MenuKeyboard;
use App\Telegram\MenuReplyHelper;
use App\Telegram\PartKeyboard;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ProfileHandler
{
    /**
     * @param array<string, mixed> $user
     */
    public static function openProfile(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        array $user,
        InteractiveMenuService $interactiveMenus,
    ): void {
        $fresh = $users->findById((int) $user['id']) ?? $user;
        $isConductor = VoicePartRules::isConductor($fresh);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $texts->get('profile.change_name'),
                callback_data: 'prf:nm',
            ),
        );

        if (!$isConductor) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $texts->get('profile.change_part'),
                    callback_data: 'prf:ch',
                ),
            );
        }

        $notifyOn = $users->materialNotificationsEnabled($fresh);
        $notifyLine = $notifyOn
            ? $texts->get('profile.notify_line_on')
            : $texts->get('profile.notify_line_off');
        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $notifyOn
                    ? $texts->get('profile.material_notify_on')
                    : $texts->get('profile.material_notify_off'),
                callback_data: 'prf:matn:t',
            ),
        );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $texts);

        if ($isConductor) {
            $body = $texts->get('profile.card_conductor', [
                'name' => (string) ($fresh['display_name'] ?? ''),
                'notify_line' => $notifyLine,
            ]);
        } else {
            $partLabel = VoicePartRules::partLabelForDisplay($texts, $fresh) ?? $texts->get('profile.part_none');
            $body = $texts->get('profile.card', [
                'name' => (string) ($fresh['display_name'] ?? ''),
                'part' => $partLabel,
                'notify_line' => $notifyLine,
            ]);
        }

        $ownerTelegramId = (int) ($fresh['telegram_id'] ?? $bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $interactiveMenus,
            $ownerTelegramId,
            $body,
            $keyboard,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function promptOwnerPartIfNeeded(
        Nutgram $bot,
        TextRepository $texts,
        array $user,
        InteractiveMenuService $interactiveMenus,
    ): bool {
        return self::promptMissingVoicePartIfNeeded($bot, $texts, $user, $interactiveMenus);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function promptMissingVoicePartIfNeeded(
        Nutgram $bot,
        TextRepository $texts,
        array $user,
        InteractiveMenuService $interactiveMenus,
    ): bool {
        if (!VoicePartRules::requiresAssignedVoicePart($user)) {
            return false;
        }

        if (VoicePartRules::hasAssignedVoicePart($user)) {
            return false;
        }

        $prompt = ($user['role'] ?? '') === UserRole::OWNER
            ? $texts->get('profile.owner_ask_part')
            : $texts->get('profile.ask_part_required');

        $ownerTelegramId = (int) ($user['telegram_id'] ?? $bot->userId() ?? 0);
        $keyboard = PartKeyboard::profile($texts, $user);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $interactiveMenus,
            $ownerTelegramId,
            $prompt,
            $keyboard,
        );

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function sendPartPicker(
        Nutgram $bot,
        TextRepository $texts,
        array $user,
        InteractiveMenuService $interactiveMenus,
    ): void {
        if (VoicePartRules::isConductor($user)) {
            $bot->sendMessage($texts->get('profile.conductor_no_part'));

            return;
        }

        $ownerTelegramId = (int) ($user['telegram_id'] ?? $bot->userId() ?? 0);
        $keyboard = PartKeyboard::profile($texts, $user);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $interactiveMenus,
            $ownerTelegramId,
            $texts->get('profile.pick_part'),
            $keyboard,
        );
    }

    public static function beginRenameFlow(
        Nutgram $bot,
        TextRepository $texts,
        UserFlowRepository $flows,
        int $telegramId,
        InteractiveMenuService $interactiveMenus,
    ): void {
        $flows->set($telegramId, 'profile_rename', []);
        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $interactiveMenus,
            $telegramId,
            $texts->get('profile.ask_name'),
            $keyboard,
        );
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        UserFlowRepository $flows,
        int $telegramId,
        string $text,
        InteractiveMenuService $interactiveMenus,
    ): bool {
        $state = $flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'profile_rename') {
            return false;
        }

        $name = trim($text);
        $length = mb_strlen($name);
        if ($length < 1 || $length > 64) {
            $bot->sendMessage($texts->get('profile.invalid_name'));

            return true;
        }

        $user = $users->findByTelegramId($telegramId);
        if ($user === null) {
            $flows->clear($telegramId);

            return true;
        }

        $users->updateDisplayName((int) $user['id'], $name);
        $flows->clear($telegramId);
        $fresh = $users->findById((int) $user['id']) ?? $user;
        MenuReplyHelper::sendWithMenu($bot, $texts, $fresh, $texts->get('profile.name_saved'));
        self::openProfile($bot, $texts, $users, $fresh, $interactiveMenus);

        return true;
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        UserFlowRepository $flows,
        InteractiveMenuService $interactiveMenus,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'prf:')) {
            return false;
        }

        $user = $users->findByTelegramId($actorTelegramId);
        if ($user === null) {
            $bot->answerCallbackQuery();

            return true;
        }

        if (VoicePartRules::isConductor($user) && (str_starts_with($data, 'prf:pt:') || $data === 'prf:ch')) {
            $bot->answerCallbackQuery(text: $texts->get('profile.conductor_no_part'), show_alert: true);

            return true;
        }

        if ($data === 'prf:nm') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::beginRenameFlow($bot, $texts, $flows, $actorTelegramId, $interactiveMenus);

            return true;
        }

        if ($data === 'prf:ch') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendPartPicker($bot, $texts, $user, $interactiveMenus);

            return true;
        }

        if ($data === 'prf:matn:t') {
            $next = $users->toggleMaterialNotifications((int) $user['id']);
            $bot->answerCallbackQuery(text: $next
                ? $texts->get('profile.material_notify_turned_on')
                : $texts->get('profile.material_notify_turned_off'));
            CallbackUi::clearKeyboard($bot);
            $fresh = $users->findById((int) $user['id']) ?? $user;
            self::openProfile($bot, $texts, $users, $fresh, $interactiveMenus);

            return true;
        }

        if ($data === 'prf:pt:none') {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return true;
        }

        if (preg_match('/^prf:pt:([a-z0-9_]+)$/', $data, $m) === 1) {
            $part = $m[1];
            if (!VoicePart::isValid($part)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $users->setVoicePart((int) $user['id'], $part);
            $bot->answerCallbackQuery(text: $texts->get('profile.part_saved'));
            CallbackUi::clearKeyboard($bot);
            $user = $users->findById((int) $user['id']) ?? $user;
            $bot->sendMessage(
                $texts->get('profile.part_updated', ['part' => $texts->get('parts.' . $part)]),
                reply_markup: MenuKeyboard::forCurrentChat($bot, $texts, $user),
            );

            return true;
        }

        return false;
    }
}