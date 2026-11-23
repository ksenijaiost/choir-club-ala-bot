<?php

declare(strict_types=1);

namespace App\Services;

use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommand;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeChat;

final class AdminChatBotCommands
{
    public static function registerForAdminChat(Nutgram $bot, TextRepository $texts, int $adminChatId): void
    {
        $commands = [
            BotCommand::make($texts->get('admin_chat.cmd_notes'), $texts->get('admin_chat.cmd_desc_notes')),
            BotCommand::make($texts->get('admin_chat.cmd_songs'), $texts->get('admin_chat.cmd_desc_songs')),
            BotCommand::make($texts->get('admin_chat.cmd_members'), $texts->get('admin_chat.cmd_desc_members')),
            BotCommand::make($texts->get('admin_chat.cmd_edit'), $texts->get('admin_chat.cmd_desc_edit')),
            BotCommand::make($texts->get('admin_chat.cmd_programs'), $texts->get('admin_chat.cmd_desc_programs')),
            BotCommand::make($texts->get('admin_chat.cmd_broadcast'), $texts->get('admin_chat.cmd_desc_broadcast')),
            BotCommand::make($texts->get('admin_chat.cmd_polls'), $texts->get('admin_chat.cmd_desc_polls')),
            BotCommand::make($texts->get('admin_chat.cmd_stats'), $texts->get('admin_chat.cmd_desc_stats')),
        ];

        $scope = BotCommandScopeChat::make(chat_id: $adminChatId);
        $bot->setMyCommands($commands, $scope);
        $bot->setMyCommands($commands, $scope, 'ru');
    }
}