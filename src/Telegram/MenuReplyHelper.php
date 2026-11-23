<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class MenuReplyHelper
{
    /**
     * @param array<string, mixed> $user
     */
    public static function sendWithMenu(Nutgram $bot, TextRepository $texts, array $user, string $text): void
    {
        SafeTelegram::sendMessage(
            $bot,
            $text,
            replyMarkup: MenuKeyboard::forCurrentChat($bot, $texts, $user),
        );
    }
}