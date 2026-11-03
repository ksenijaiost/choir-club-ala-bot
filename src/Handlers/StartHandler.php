<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class StartHandler
{
    public static function register(Nutgram $bot, TextRepository $texts): void
    {
        $bot->onCommand('start', function (Nutgram $bot) use ($texts): void {
            $bot->sendMessage($texts->get('dev.start_placeholder'));
        });
    }
}
