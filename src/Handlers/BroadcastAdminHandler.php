<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\BroadcastRepository;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class BroadcastAdminHandler
{
  /**
   * @param array<string, mixed> $actor
   */
    public static function openList(Nutgram $bot, BroadcastDeps $deps, AccessControl $access, array $actor, bool $pollsOnly = false): void
    {
        if (!$access->canManageBroadcasts($actor)) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.forbidden'));

            return;
        }

        $recent = $deps->broadcasts->listRecent(15);
        if ($pollsOnly) {
            $recent = array_values(array_filter(
                $recent,
                static fn (array $row): bool => ($row['kind'] ?? '') === BroadcastRepository::KIND_POLL,
            ));
        }

        $header = $pollsOnly
            ? $deps->texts->get('broadcast.polls_list_header')
            : $deps->texts->get('broadcast.list_header');

        if ($recent === []) {
            $header .= "\n\n" . $deps->texts->get('broadcast.list_empty');
        }

        $keyboard = $recent === []
            ? InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $deps->texts)
            : InlineMenuHelper::appendFooter($deps->keyboards->buildAdminListKeyboard($recent), $deps->texts);

        $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $header,
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    /**
     * @param array<string, mixed> $actor
     */
    public static function handleCallback(
        Nutgram $bot,
        BroadcastDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (preg_match('/^bc:st:(\d+)$/', $data, $m) === 1) {
            if (!$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.forbidden'), 'show_alert' => true]);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            self::sendStatus($bot, $deps, (int) $m[1]);

            return true;
        }

        if (preg_match('/^bc:rf:(\d+)$/', $data, $m) === 1) {
            if (!$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.forbidden'), 'show_alert' => true]);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            self::editStatus($bot, $deps, (int) $m[1], $actor);

            return true;
        }

        if (preg_match('/^bc:cl:(\d+)$/', $data, $m) === 1) {
            if (!$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.forbidden'), 'show_alert' => true]);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            $broadcastId = (int) $m[1];
            $text = $deps->texts->get('broadcast.close_confirm');
            $keyboard = InlineMenuHelper::appendFooter(
                $deps->keyboards->buildCloseConfirmKeyboard($broadcastId),
                $deps->texts,
                'bc:st:' . $broadcastId,
            );
            CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, (int) ($actor['telegram_id'] ?? 0));

            return true;
        }

        if (preg_match('/^bc:cn:(\d+)$/', $data, $m) === 1) {
            if (!$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.forbidden'), 'show_alert' => true]);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            self::editStatus($bot, $deps, (int) $m[1], $actor);

            return true;
        }

        if (preg_match('/^bc:cy:(\d+)$/', $data, $m) === 1) {
            if (!$access->canManageBroadcasts($actor)) {
                SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.forbidden'), 'show_alert' => true]);

                return true;
            }
            SafeTelegram::answerCallbackQuery($bot);
            self::closeBroadcast($bot, $deps, (int) $m[1], $actor);

            return true;
        }

        return false;
    }

    private static function sendStatus(Nutgram $bot, BroadcastDeps $deps, int $broadcastId): void
    {
        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.not_found'));

            return;
        }

        $chunks = $deps->statusPresenter->statusChunks($broadcastId);
        $isOpen = $deps->broadcasts->isOpen($broadcast);
        $keyboard = InlineMenuHelper::appendFooter(
            $deps->keyboards->buildStatusActionsKeyboard($broadcastId, $isOpen),
            $deps->texts,
        );
        $first = true;
        foreach ($chunks as $chunk) {
            if ($first) {
                $first = false;
                SafeTelegram::sendMessage($bot, $chunk, replyMarkup: $keyboard);
            } else {
                SafeTelegram::sendMessage($bot, $chunk);
            }
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function editStatus(Nutgram $bot, BroadcastDeps $deps, int $broadcastId, array $actor): void
    {
        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null) {
            CallbackUi::editCurrentMessage($bot, $deps->texts->get('broadcast.not_found'), null, $deps->interactiveMenus, (int) ($actor['telegram_id'] ?? 0));

            return;
        }

        $chunks = $deps->statusPresenter->statusChunks($broadcastId);
        $text = $chunks[0] ?? '';
        $isOpen = $deps->broadcasts->isOpen($broadcast);
        $keyboard = InlineMenuHelper::appendFooter(
            $deps->keyboards->buildStatusActionsKeyboard($broadcastId, $isOpen),
            $deps->texts,
        );
        CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, (int) ($actor['telegram_id'] ?? 0));

        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            SafeTelegram::sendMessage($bot, $chunks[$i]);
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function closeBroadcast(Nutgram $bot, BroadcastDeps $deps, int $broadcastId, array $actor): void
    {
        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null || !$deps->broadcasts->isOpen($broadcast)) {
            self::editStatus($bot, $deps, $broadcastId, $actor);

            return;
        }

        $deps->broadcasts->close($broadcastId);
        $broadcast = $deps->broadcasts->find($broadcastId) ?? $broadcast;

        self::clearRecipientKeyboards($bot, $deps, $broadcast);
        self::notifyNonResponders($deps, $broadcast);

        self::editStatus($bot, $deps, $broadcastId, $actor);
    }

    /**
     * @param array<string, mixed> $broadcast
     */
    private static function clearRecipientKeyboards(Nutgram $bot, BroadcastDeps $deps, array $broadcast): void
    {
        $broadcastId = (int) ($broadcast['id'] ?? 0);
        foreach ($deps->broadcasts->listRecipients($broadcastId) as $recipient) {
            $telegramId = (int) ($recipient['telegram_id'] ?? 0);
            $messageId = (int) ($recipient['telegram_message_id'] ?? 0);
            if ($telegramId <= 0 || $messageId <= 0) {
                continue;
            }

            SafeTelegram::editMessageReplyMarkup($bot, $telegramId, $messageId, InlineKeyboardMarkup::make());
        }
    }

    /**
     * @param array<string, mixed> $broadcast
     */
    private static function notifyNonResponders(BroadcastDeps $deps, array $broadcast): void
    {
        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $kind = (string) ($broadcast['kind'] ?? '');
        $recipients = $deps->broadcasts->listRecipients($broadcastId);
        $notifyText = $kind === BroadcastRepository::KIND_POLL
            ? $deps->texts->get('broadcast.poll_closed_notice')
            : $deps->texts->get('broadcast.text_closed_notice');

        foreach ($recipients as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $telegramId = (int) ($row['telegram_id'] ?? 0);
            if ($telegramId <= 0) {
                continue;
            }

            $needsNotify = false;
            if ($kind === BroadcastRepository::KIND_TEXT) {
                $needsNotify = ($row['read_at'] ?? null) === null || ($row['read_at'] ?? '') === '';
            } else {
                $needsNotify = !$deps->broadcasts->hasAnswered($broadcastId, $userId);
            }

            if ($needsNotify) {
                $deps->outbound->sendMessage($telegramId, $notifyText);
            }
        }
    }
}