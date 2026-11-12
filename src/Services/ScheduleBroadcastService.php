<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ScheduleBroadcastService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TextRepository $texts,
    ) {
    }

    public function notifyScheduleChanged(Nutgram $bot): int
    {
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                text: $this->texts->get('buttons.schedule'),
                callback_data: 'sch:view',
            ),
        );

        $sent = 0;
        foreach ($this->users->listAllActiveTelegramIds() as $telegramId) {
            $bot->sendMessage(
                text: $this->texts->get('schedule.changed'),
                chat_id: $telegramId,
                reply_markup: $keyboard,
            );
            $sent++;
        }

        return $sent;
    }
}
