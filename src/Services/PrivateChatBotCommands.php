<?php

declare(strict_types=1);

namespace App\Services;

use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommand;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeAllPrivateChats;

final class PrivateChatBotCommands
{
    public static function register(Nutgram $bot, TextRepository $texts): void
    {
        $commands = [
            BotCommand::make($texts->get('commands.start'), $texts->get('commands.desc_start')),
            BotCommand::make($texts->get('commands.help'), $texts->get('commands.desc_help')),
        ];

        $scope = new BotCommandScopeAllPrivateChats();
        $bot->setMyCommands($commands, $scope, 'ru');
    }
}