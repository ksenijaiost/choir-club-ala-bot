<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Repositories\UserFlowRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class InlineMenuHelper
{
    public const PAGE_SIZE = 8;

    public const CLOSE_CALLBACK = 'ui:x';

    public static function closeButton(TextRepository $texts): InlineKeyboardButton
    {
        return InlineKeyboardButton::make(
            $texts->get('ui.close'),
            callback_data: self::CLOSE_CALLBACK,
        );
    }

    public static function backButton(TextRepository $texts, string $callbackData): InlineKeyboardButton
    {
        return InlineKeyboardButton::make(
            $texts->get('ui.back'),
            callback_data: $callbackData,
        );
    }

     /**
     * @param list<InlineKeyboardButton> $extra
     */
    public static function appendFooter(
        InlineKeyboardMarkup $keyboard,
        TextRepository $texts,
        ?string $backCallback = null,
        array $extra = [],
    ): InlineKeyboardMarkup {
        $row = [...$extra];

        if ($backCallback !== null && $backCallback !== '') {
            $row[] = self::backButton($texts, $backCallback);
        }

        $row[] = self::closeButton($texts);

        if ($row === []) {
            return $keyboard;
        }

        return $keyboard->addRow(...$row);
    }

    public static function paginationButtons(
        int $page,
        int $totalItems,
        string $pageCallbackPrefix,
    ): array {
        if ($totalItems <= self::PAGE_SIZE) {
            return [];
        }

        $buttons = [];
        $offset = $page * self::PAGE_SIZE;

        if ($page > 0) {
            $buttons[] = InlineKeyboardButton::make('‹', callback_data: $pageCallbackPrefix . ($page - 1));
        }

        if ($offset + self::PAGE_SIZE < $totalItems) {
            $buttons[] = InlineKeyboardButton::make('›', callback_data: $pageCallbackPrefix . ($page + 1));
        }

        return $buttons;
    }

    /**
     * @param list<InlineKeyboardButton> $extraBeforeNav
     */
    public static function appendPaginationAndFooter(
        InlineKeyboardMarkup $keyboard,
        TextRepository $texts,
        int $page,
        int $totalItems,
        string $pageCallbackPrefix,
        ?string $backCallback = null,
        array $extraBeforeNav = [],
    ): InlineKeyboardMarkup {
        $nav = self::paginationButtons($page, $totalItems, $pageCallbackPrefix);
        if ($nav !== []) {
            $keyboard = $keyboard->addRow(...$nav);
        }

        return self::appendFooter($keyboard, $texts, $backCallback, $extraBeforeNav);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     * @return list<T>
     */
    public static function pageSlice(array $items, int $page): array
    {
        $offset = $page * self::PAGE_SIZE;

        return array_slice($items, $offset, self::PAGE_SIZE);
    }

    public static function totalPages(int $totalItems): int
    {
        return max(1, (int) ceil($totalItems / self::PAGE_SIZE));
    }

    public static function keyboardHasCloseButton(?InlineKeyboardMarkup $keyboard): bool
    {
        if ($keyboard === null) {
            return false;
        }

        foreach ($keyboard->inline_keyboard ?? [] as $row) {
            foreach ($row as $button) {
                if (($button->callback_data ?? '') === self::CLOSE_CALLBACK) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function sendInteractiveMenu(
        Nutgram $bot,
        InteractiveMenuService $menus,
        int $ownerTelegramId,
        string $text,
        InlineKeyboardMarkup $keyboard,
        ?int $chatId = null,
        ?ParseMode $parseMode = null,
    ): ?Message {
        if (!self::keyboardHasCloseButton($keyboard)) {
            return $bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                reply_markup: $keyboard,
                parse_mode: $parseMode,
            );
        }

        $chatId ??= $bot->chatId() ?? $ownerTelegramId;
        $menus->dismissPrevious($bot, $ownerTelegramId, $chatId);

        $message = $bot->sendMessage(
            text: $text,
            chat_id: $chatId,
            reply_markup: $keyboard,
            parse_mode: $parseMode,
        );

        if ($message !== null) {
            $menus->recordOpen($ownerTelegramId, $chatId, $message->message_id);
        }

        return $message;
    }

    public static function trackEditedMenu(
        InteractiveMenuService $menus,
        int $ownerTelegramId,
        int $chatId,
        int $messageId,
        ?InlineKeyboardMarkup $keyboard,
    ): void {
        if (!self::keyboardHasCloseButton($keyboard)) {
            return;
        }

        $menus->recordOpen($ownerTelegramId, $chatId, $messageId);
    }

    public static function handleClose(
        Nutgram $bot,
        UserFlowRepository $flows,
        InteractiveMenuService $menus,
        int $telegramId,
    ): void {
        $flows->clear($telegramId);
        SafeTelegram::answerCallbackQuery($bot);

        $message = CallbackUi::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        $chatId = (int) $message->chat->id;
        $messageId = (int) $message->message_id;

        try {
            $bot->deleteMessage(chat_id: $chatId, message_id: $messageId);
            $menus->clearOpen($telegramId, $chatId);
        } catch (\Throwable $e) {
            error_log('[interactive-menu] close delete failed: ' . $e->getMessage());
            CallbackUi::clearKeyboard($bot, $message);
            $menus->clearOpen($telegramId, $chatId);
        }
    }
}