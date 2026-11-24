<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Repositories\UserFlowRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class GroupFlowSession
{
    public const CTX_CHAT = 'session_chat_id';

    public const CTX_OWNER = 'session_owner_telegram_id';

    public const CTX_REPLY_TO = 'session_reply_to_message_id';

  /**
     * @param array<string, mixed> $ctx
     */
    public static function isGroupContext(array $ctx): bool
    {
        return (int) ($ctx[self::CTX_CHAT] ?? 0) < 0;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function bindGroup(array &$ctx, int $chatId, int $ownerTelegramId, ?int $replyToMessageId = null): void
    {
        $ctx[self::CTX_CHAT] = $chatId;
        $ctx[self::CTX_OWNER] = $ownerTelegramId;
        if ($replyToMessageId !== null && $replyToMessageId > 0) {
            $ctx[self::CTX_REPLY_TO] = $replyToMessageId;
        }
    }

    /**
     * @param array<string, mixed>|null $ctx
     */
    public static function matchesChat(?array $ctx, ?int $chatId): bool
    {
        $ctx ??= [];
        $stored = (int) ($ctx[self::CTX_CHAT] ?? 0);

        if ($stored === 0) {
            if ($chatId === null) {
                return true;
            }

            return $chatId > 0;
        }

        $chatId ??= 0;

        return $stored === $chatId;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function targetChatId(array $ctx, Nutgram $bot, int $ownerTelegramId): int
    {
        $stored = (int) ($ctx[self::CTX_CHAT] ?? 0);
        if ($stored !== 0) {
            return $stored;
        }

        return $bot->chatId() ?? $ownerTelegramId;
    }

    public static function sendTextPrompt(
        Nutgram $bot,
        TextRepository $texts,
        string $promptText,
        array &$ctx,
        ?string $cancelCallback = null,
    ): void {
        FlowStepCleanup::sendStepPrompt($bot, $texts, $promptText, $ctx, $cancelCallback);
    }

    public static function flowBlocksOtherChat(
        UserFlowRepository $flows,
        int $telegramId,
        ?int $chatId,
        string $flowPrefix = '',
    ): bool {
        $state = $flows->get($telegramId);
        if ($state === null) {
            return false;
        }
        if ($flowPrefix !== '' && !str_starts_with((string) ($state['flow'] ?? ''), $flowPrefix)) {
            return false;
        }
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];

        return !self::matchesChat($ctx, $chatId);
    }
}