<?php

declare(strict_types=1);

namespace App\Tests\Support;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use App\Telegram\TelegramOutbound;

final class RecordingTelegramOutbound implements TelegramOutbound
{
    /** @var list<array{chat_id: int, text: string}> */
    public array $messages = [];

    public function sendMessage(int $chatId, string $text, ?InlineKeyboardMarkup $replyMarkup = null): void
    {
        $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
    }
}
