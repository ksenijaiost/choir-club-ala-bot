<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AdminChatBinder;
use App\Services\AccessControl;
use App\Telegram\AdminChatLink;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AdminChatOwnerHandler
{
    private const FLOW = 'admin_chat_change';

    public const CB_CHANGE = 'ach:chg';

    public const CB_CANCEL = 'ach:cancel';

    /**
     * @param array<string, mixed> $user
     */
    public static function openHome(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        AdminChatDeps $deps,
        array $user,
    ): void {
        if (!$access->isOwner($user)) {
            $bot->sendMessage($texts->get('admin.only_owner'));

            return;
        }

        $chatId = $deps->resolver->resolvedIdAsInt();
        if ($chatId === null) {
            $body = $texts->get('admin_chat.not_bound');
        } else {
            $title = self::fetchChatTitle($bot, $chatId, $texts);
            $body = $texts->get('admin_chat.current', [
                'id' => (string) $chatId,
                'title' => $title,
            ]);
        }

        $bot->sendMessage($body, reply_markup: self::homeKeyboard($texts, $deps));
    }

    public static function beginChangeFlow(Nutgram $bot, TextRepository $texts, AdminChatDeps $deps, int $telegramId): void
    {
        $deps->flows->set($telegramId, self::FLOW, []);
        $keyboard = InlineMenuHelper::appendFooter(
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make($texts->get('admin_chat.cancel'), callback_data: self::CB_CANCEL),
            ),
            $texts,
        );
        $bot->sendMessage($texts->get('admin_chat.ask_id'), reply_markup: $keyboard);
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        AdminChatDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return false;
        }

        if (mb_strtolower(trim($text)) === mb_strtolower($deps->texts->get('admin_chat.cancel'))) {
            $deps->flows->clear($telegramId);
            $bot->sendMessage($deps->texts->get('admin_chat.change_cancelled'));

            return true;
        }

        $result = AdminChatBinder::tryBind($bot, $deps->settings, $deps->texts, $text);
        if (!$result['ok']) {
            $errorKey = (string) ($result['error_key'] ?? 'errors.generic');
            $bot->sendMessage($deps->texts->get($errorKey) . "\n\n" . $deps->texts->get('admin_chat.try_again'));

            return true;
        }

        $deps->flows->clear($telegramId);
        $chatId = (int) ($result['chat_id'] ?? 0);
        $title = (string) ($result['title'] ?? '');

        $keyboard = self::successKeyboard($deps);
        $bot->sendMessage(
            $deps->texts->get('admin_chat.changed', [
                'id' => (string) $chatId,
                'title' => $title,
            ]),
            reply_markup: $keyboard,
        );

        $bot->sendMessage(
            chat_id: $chatId,
            text: $deps->texts->get('admin_chat.new_chat_notice'),
        );

        return true;
    }

    public static function handleCallback(
        Nutgram $bot,
        AccessControl $access,
        AdminChatDeps $deps,
        array $actor,
        string $data,
        int $telegramId,
    ): bool {
        if (!str_starts_with($data, 'ach:')) {
            return false;
        }

        if (!$access->isOwner($actor)) {
            $bot->answerCallbackQuery(text: $deps->texts->get('admin.only_owner'), show_alert: true);

            return true;
        }

        $bot->answerCallbackQuery();

        if ($data === self::CB_CANCEL) {
            $deps->flows->clear($telegramId);
            CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('admin_chat.change_cancelled'));

            return true;
        }

        if ($data === self::CB_CHANGE) {
            self::beginChangeFlow($bot, $deps->texts, $deps, $telegramId);

            return true;
        }

        return false;
    }

    private static function homeKeyboard(TextRepository $texts, AdminChatDeps $deps): InlineKeyboardMarkup
    {
        $row = [];
        $url = AdminChatLink::resolveUrl($deps->resolver, $deps->config);
        if ($url !== null) {
            $row[] = InlineKeyboardButton::make($texts->get('materials.open_admin_chat'), url: $url);
        }

        $row[] = InlineKeyboardButton::make($texts->get('admin_chat.change_button'), callback_data: self::CB_CHANGE);
        $row[] = InlineMenuHelper::closeButton($texts);

        return InlineKeyboardMarkup::make()->addRow(...$row);
    }

    private static function successKeyboard(AdminChatDeps $deps): InlineKeyboardMarkup
    {
        $url = AdminChatLink::resolveUrl($deps->resolver, $deps->config);
        if ($url === null) {
            return InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $deps->texts);
        }

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($deps->texts->get('materials.open_admin_chat'), url: $url),
            InlineMenuHelper::closeButton($deps->texts),
        );
    }

    private static function fetchChatTitle(Nutgram $bot, int $chatId, TextRepository $texts): string
    {
        try {
            $chat = $bot->getChat($chatId);
            if ($chat !== null && trim((string) ($chat->title ?? '')) !== '') {
                return (string) $chat->title;
            }
        } catch (\Throwable) {
            // getChat may fail if bot was removed
        }

        return $texts->get('admin_chat.unnamed');
    }
}
