<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BotSettingsRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatMemberStatus;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMember;

final class AdminChatBinder
{
    public static function parseChatId(string $raw): ?string
    {
        $text = trim($raw);
        if ($text === '' || !preg_match('/^-\d+$/', $text)) {
            return null;
        }

        return $text;
    }

    /**
     * @return array{ok: bool, error_key?: string, chat_id?: int, title?: string}
     */
    public static function tryBind(
        Nutgram $bot,
        BotSettingsRepository $settings,
        TextRepository $texts,
        string $raw,
    ): array {
        $chatIdString = self::parseChatId($raw);
        if ($chatIdString === null) {
            return ['ok' => false, 'error_key' => 'admin_chat.invalid_id'];
        }

        $chatId = (int) $chatIdString;

        try {
            $chat = $bot->getChat($chatId);
        } catch (\Throwable) {
            return ['ok' => false, 'error_key' => 'admin_chat.bot_not_in_chat'];
        }

        if ($chat === null) {
            return ['ok' => false, 'error_key' => 'admin_chat.bot_not_in_chat'];
        }

        $me = $bot->getMe();
        if ($me === null) {
            return ['ok' => false, 'error_key' => 'errors.generic'];
        }

        try {
            $member = $bot->getChatMember($chatId, $me->id);
        } catch (\Throwable) {
            return ['ok' => false, 'error_key' => 'admin_chat.bot_not_in_chat'];
        }

        if ($member === null || !self::isBotAdministrator($member)) {
            return ['ok' => false, 'error_key' => 'admin_chat.bot_not_admin'];
        }

        $settings->set(AdminChatResolver::SETTING_KEY, $chatIdString);

        return [
            'ok' => true,
            'chat_id' => $chatId,
            'title' => (string) ($chat->title ?? $texts->get('admin_chat.unnamed')),
        ];
    }

    private static function isBotAdministrator(ChatMember $member): bool
    {
        $status = $member->status ?? '';
        if ($status instanceof ChatMemberStatus) {
            $status = $status->value;
        }

        return in_array((string) $status, ['creator', 'administrator'], true);
    }
}
