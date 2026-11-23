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
            BotCommand::make($texts->get('commands.song'), $texts->get('commands.desc_song')),
            BotCommand::make($texts->get('commands.recordings'), $texts->get('commands.desc_recordings')),
            BotCommand::make($texts->get('commands.profile'), $texts->get('commands.desc_profile')),
            BotCommand::make($texts->get('commands.about'), $texts->get('commands.desc_about')),
        ];

        $scope = new BotCommandScopeAllPrivateChats();
        $bot->setMyCommands($commands, $scope);
        $bot->setMyCommands($commands, $scope, 'ru');
    }
}