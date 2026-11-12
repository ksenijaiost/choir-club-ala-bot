<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserRole;
use App\Domain\VoicePart;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\MenuKeyboard;
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
    public static function openProfile(Nutgram $bot, TextRepository $texts, UserRepository $users, array $user): void
    {
        $fresh = $users->findById((int) $user['id']) ?? $user;
        $partCode = (string) ($fresh['voice_part'] ?? '');
        $partLabel = $partCode !== '' && VoicePart::isValid($partCode)
            ? $texts->get('parts.' . $partCode)
            : $texts->get('profile.part_none');

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $texts->get('profile.change_name'),
                    callback_data: 'prf:nm',
                ),
                InlineKeyboardButton::make(
                    $texts->get('profile.change_part'),
                    callback_data: 'prf:ch',
                ),
            );
        $keyboard = InlineMenuHelper::appendFooter($keyboard, $texts);

        $bot->sendMessage(
            $texts->get('profile.card', [
                'name' => (string) ($fresh['display_name'] ?? ''),
                'part' => $partLabel,
            ]),
            reply_markup: $keyboard,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function promptOwnerPartIfNeeded(Nutgram $bot, TextRepository $texts, array $user): bool
    {
        if (($user['role'] ?? '') !== UserRole::OWNER) {
            return false;
        }

        $part = $user['voice_part'] ?? null;
        if ($part !== null && $part !== '') {
            return false;
        }

        $bot->sendMessage(
            $texts->get('profile.owner_ask_part'),
            reply_markup: PartKeyboard::profile($texts, $user),
        );

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function sendPartPicker(Nutgram $bot, TextRepository $texts, array $user): void
    {
        $bot->sendMessage(
            $texts->get('profile.pick_part'),
            reply_markup: PartKeyboard::profile($texts, $user),
        );
    }

    public static function beginRenameFlow(
        Nutgram $bot,
        TextRepository $texts,
        UserFlowRepository $flows,
        int $telegramId,
    ): void {
        $flows->set($telegramId, 'profile_rename', []);
        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);
        $bot->sendMessage($texts->get('profile.ask_name'), reply_markup: $keyboard);
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        UserFlowRepository $flows,
        int $telegramId,
        string $text,
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
        $bot->sendMessage($texts->get('profile.name_saved'));
        self::openProfile($bot, $texts, $users, $fresh);

        return true;
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        UserFlowRepository $flows,
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

        if ($data === 'prf:nm') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::beginRenameFlow($bot, $texts, $flows, $actorTelegramId);

            return true;
        }

        if ($data === 'prf:ch') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendPartPicker($bot, $texts, $user);

            return true;
        }

        if ($data === 'prf:pt:none') {
            $role = (string) ($user['role'] ?? '');
            if (!in_array($role, [UserRole::OWNER, UserRole::CONDUCTOR], true)) {
                $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $users->setVoicePart((int) $user['id'], null);
            $bot->answerCallbackQuery(text: $texts->get('profile.part_saved'));
            CallbackUi::clearKeyboard($bot);
            $user = $users->findById((int) $user['id']) ?? $user;
            $bot->sendMessage(
                $texts->get('profile.part_updated', ['part' => $texts->get('profile.part_none')]),
                reply_markup: MenuKeyboard::forUser($texts, $user),
            );

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
                reply_markup: MenuKeyboard::forUser($texts, $user),
            );

            return true;
        }

        return false;
    }
}
