<?php

declare(strict_types=1);

namespace App\Services;

use SergiX44\Nutgram\Nutgram;

final class AdminChatCommandMessenger
{
    /**
     * @param list<string> $chunks
     */
    public static function sendChunks(Nutgram $bot, int $chatId, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $bot->sendMessage(text: $chunk, chat_id: $chatId);
        }
    }
}