<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Repositories\UserFlowRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

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

    public static function handleClose(
        Nutgram $bot,
        UserFlowRepository $flows,
        TextRepository $texts,
        int $telegramId,
    ): void {
        $flows->clear($telegramId);
        $bot->answerCallbackQuery();

        $message = CallbackUi::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        try {
            $bot->editMessageText(
                text: $texts->get('ui.menu_closed'),
                chat_id: $message->chat->id,
                message_id: $message->message_id,
                reply_markup: InlineKeyboardMarkup::make(),
            );
        } catch (\Throwable) {
            CallbackUi::clearKeyboard($bot, $message);
        }
    }
}
