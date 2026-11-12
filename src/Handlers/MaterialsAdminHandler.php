<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Telegram\AdminChatLink;
use App\Telegram\InlineMenuHelper;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MaterialsAdminHandler
{
    /**
     * @param array<string, mixed> $user
     */
    public static function openHome(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        MaterialsDeps $deps,
        array $user,
    ): void {
        if (!$access->canUploadMaterials($user)) {
            $bot->sendMessage($texts->get('materials.upload_forbidden'));

            return;
        }

        $bot->sendMessage(
            $texts->get('materials.admin_chat_hint'),
            reply_markup: self::adminChatHintKeyboard($texts, $deps->config),
        );
        MaterialsCallbackHandler::openManagementHome($bot, $deps);
    }

    public static function adminChatHintKeyboard(
        TextRepository $texts,
        \App\Config $config,
    ): InlineKeyboardMarkup {
        $url = AdminChatLink::resolveUrl($config);
        if ($url === null) {
            return InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);
        }

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($texts->get('materials.open_admin_chat'), url: $url),
            InlineMenuHelper::closeButton($texts),
        );
    }
}
