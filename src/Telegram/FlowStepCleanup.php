<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class FlowStepCleanup
{
    public const CTX_PROMPTS = 'flow_step_prompt_ids';

    public const CTX_USER_REPLIES = 'flow_step_user_reply_ids';

    /**
     * @param array<string, mixed> $ctx
     */
    public static function deleteStoredPrompts(Nutgram $bot, array &$ctx): void
    {
        foreach (self::messageRefs($ctx, self::CTX_PROMPTS) as $ref) {
            SafeTelegram::deleteMessage($bot, $ref['chat_id'], $ref['message_id']);
        }
        $ctx[self::CTX_PROMPTS] = [];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function purgeAll(Nutgram $bot, array $ctx): void
    {
        self::deleteMessageRefs($bot, self::messageRefs($ctx, self::CTX_PROMPTS));
        self::deleteMessageRefs($bot, self::messageRefs($ctx, self::CTX_USER_REPLIES));
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function appendPrompt(array &$ctx, int $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }
        $list = self::messageRefs($ctx, self::CTX_PROMPTS);
        $list[] = ['chat_id' => $chatId, 'message_id' => $messageId];
        $ctx[self::CTX_PROMPTS] = $list;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function recordUserReply(Nutgram $bot, array &$ctx, ?int $chatId = null): void
    {
        if (!GroupFlowSession::isGroupContext($ctx)) {
            return;
        }
        $message = $bot->message();
        if ($message === null) {
            return;
        }
        $resolvedChatId = $chatId ?? $bot->chatId();
        if ($resolvedChatId === null) {
            return;
        }
        $messageId = (int) ($message->message_id ?? 0);
        if ($messageId <= 0) {
            return;
        }
        $list = self::messageRefs($ctx, self::CTX_USER_REPLIES);
        $list[] = ['chat_id' => (int) $resolvedChatId, 'message_id' => $messageId];
        $ctx[self::CTX_USER_REPLIES] = $list;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function sendStepPrompt(
        Nutgram $bot,
        TextRepository $texts,
        string $promptText,
        array &$ctx,
        ?string $cancelCallback,
    ): void {
        self::deleteStoredPrompts($bot, $ctx);

        $ownerTelegramId = (int) ($ctx[GroupFlowSession::CTX_OWNER] ?? $bot->userId() ?? 0);
        $chatId = GroupFlowSession::targetChatId($ctx, $bot, $ownerTelegramId);
        $replyTo = (int) ($ctx[GroupFlowSession::CTX_REPLY_TO] ?? 0);

        $body = $promptText;
        if ($cancelCallback !== null && $cancelCallback !== '') {
            $body .= "\n\n" . $texts->get('common.tap_cancel_to_abort');
        }
        if (GroupFlowSession::isGroupContext($ctx)) {
            $body .= "\n\n" . $texts->get('broadcast.wizard_group_step_hint');
        }

        $markup = null;
        if ($cancelCallback !== null && $cancelCallback !== '') {
            $markup = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make($texts->get('buttons.cancel'), callback_data: $cancelCallback),
            );
        }

        $message = SafeTelegram::sendMessage(
            $bot,
            $body,
            $chatId > 0 ? $chatId : null,
            $markup,
            null,
            false,
            GroupFlowSession::isGroupContext($ctx) && $replyTo > 0 ? $replyTo : null,
        );

        if ($message !== null) {
            self::appendPrompt($ctx, $chatId, (int) $message->message_id);
        }
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{chat_id: int, message_id: int}>
     */
    /**
     * @param list<array{chat_id: int, message_id: int}> $refs
     */
    private static function deleteMessageRefs(Nutgram $bot, array $refs): void
    {
        $seen = [];
        foreach ($refs as $ref) {
            $key = $ref['chat_id'] . ':' . $ref['message_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            SafeTelegram::deleteMessage($bot, $ref['chat_id'], $ref['message_id']);
        }
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{chat_id: int, message_id: int}>
     */
    private static function messageRefs(array $ctx, string $key): array
    {
        $raw = $ctx[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $chatId = (int) ($item['chat_id'] ?? 0);
            $messageId = (int) ($item['message_id'] ?? 0);
            if ($chatId === 0 || $messageId <= 0) {
                continue;
            }
            $out[] = ['chat_id' => $chatId, 'message_id' => $messageId];
        }

        return $out;
    }
}