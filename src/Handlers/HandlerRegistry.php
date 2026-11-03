<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

/**
 * Registers all command and message handlers (business logic to be added later).
 */
final class HandlerRegistry
{
    public static function register(Nutgram $bot, TextRepository $texts): void
    {
        StartHandler::register($bot, $texts);
    }
}
