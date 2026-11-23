<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\BroadcastRepository;
use App\Telegram\CallbackUi;
use App\Telegram\MenuReplyHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;

final class BroadcastRecipientHandler
{
    public const FLOW_CUSTOM = 'broadcast_custom_answer';

    public static function handleCallback(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $actor,
        string $data,
    ): bool {
        if (preg_match('/^bc:rd:(\d+)$/', $data, $m) === 1) {
            $handled = self::handleRead($bot, $deps, $actor, (int) $m[1]);
            SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.read_ack')]);

            return $handled;
        }

        if (preg_match('/^bc:pv:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $broadcastId = (int) $m[1];
            $optionId = (int) $m[2];

            return self::handlePollVote($bot, $deps, $actor, $broadcastId, $optionId);
        }

        if (preg_match('/^bc:cs:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);

            return self::handleCustomStart($bot, $deps, $actor, (int) $m[1]);
        }

        if (preg_match('/^bc:cx:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);

            return self::handleCustomCancel($bot, $deps, $actor, (int) $m[1]);
        }

        return false;
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        BroadcastDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW_CUSTOM) {
            return false;
        }

        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
        $broadcastId = (int) ($ctx['broadcast_id'] ?? 0);
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || $broadcastId <= 0) {
            $deps->flows->clear($telegramId);

            return false;
        }

        $answer = trim($text);
        if ($answer === '' || mb_strlen($answer) > BroadcastRepository::CUSTOM_TEXT_MAX) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.custom_invalid'));

            return true;
        }

        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null || !$deps->broadcasts->isOpen($broadcast)) {
            $deps->flows->clear($telegramId);
            SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.poll_closed'));

            return true;
        }

        $userId = (int) ($user['id'] ?? 0);
        $deps->broadcasts->setCustomAnswer($broadcastId, $userId, $answer);
        $deps->flows->clear($telegramId);

        self::redrawRecipientMessage($bot, $deps, $broadcast, $user);
        MenuReplyHelper::sendWithMenu($bot, $deps->texts, $user, $deps->texts->get('broadcast.custom_saved'));

        return true;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function handleRead(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $actor,
        int $broadcastId,
    ): bool {
        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null || (string) ($broadcast['kind'] ?? '') !== BroadcastRepository::KIND_TEXT) {
            return true;
        }

        if (!$deps->broadcasts->isOpen($broadcast)) {
            CallbackUi::clearKeyboard($bot);

            return true;
        }

        $userId = (int) ($actor['id'] ?? 0);
        if ($userId <= 0) {
            return true;
        }

        $deps->broadcasts->markRead($broadcastId, $userId);
        self::redrawTextMessage($bot, $deps, $broadcast, $actor);

        return true;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function handlePollVote(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $actor,
        int $broadcastId,
        int $optionId,
    ): bool {
        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null || (string) ($broadcast['kind'] ?? '') !== BroadcastRepository::KIND_POLL) {
            return true;
        }

        if (!$deps->broadcasts->isOpen($broadcast)) {
            CallbackUi::clearKeyboard($bot);
            SafeTelegram::answerCallbackQuery($bot, ['text' => $deps->texts->get('broadcast.poll_closed'), 'show_alert' => true]);

            return true;
        }

        $userId = (int) ($actor['id'] ?? 0);
        if ($userId <= 0) {
            return true;
        }

        $multi = (bool) ((int) ($broadcast['multi_choice'] ?? 0));
        $deps->broadcasts->setAnswerOption($broadcastId, $userId, $optionId, $multi);
        self::redrawRecipientMessage($bot, $deps, $broadcast, $actor);

        return true;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function handleCustomStart(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $actor,
        int $broadcastId,
    ): bool {
        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast === null || !(bool) ((int) ($broadcast['allow_custom'] ?? 0))) {
            return true;
        }

        if (!$deps->broadcasts->isOpen($broadcast)) {
            CallbackUi::clearKeyboard($bot);

            return true;
        }

        $telegramId = (int) ($actor['telegram_id'] ?? 0);
        $deps->flows->set($telegramId, self::FLOW_CUSTOM, ['broadcast_id' => $broadcastId]);
        SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.custom_prompt'));

        return true;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function handleCustomCancel(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $actor,
        int $broadcastId,
    ): bool {
        $telegramId = (int) ($actor['telegram_id'] ?? 0);
        $state = $deps->flows->get($telegramId);
        if ($state !== null && ($state['flow'] ?? '') === self::FLOW_CUSTOM) {
            $deps->flows->clear($telegramId);
        }

        $broadcast = $deps->broadcasts->find($broadcastId);
        if ($broadcast !== null) {
            self::redrawRecipientMessage($bot, $deps, $broadcast, $actor);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $user
     */
    private static function redrawTextMessage(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $broadcast,
        array $user,
    ): void {
        $message = CallbackUi::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $body = (string) ($broadcast['text'] ?? '');
        $author = $deps->users->findById((int) ($broadcast['author_user_id'] ?? 0));
        $header = $deps->texts->get('broadcast.message_header', [
            'author' => $author !== null
                ? $deps->rolePresenter->displayNameWithBadge($author)
                : '—',
        ]);
        $text = $header . "\n\n" . $body;
        if (($user['id'] ?? 0) > 0) {
            $text .= "\n\n" . $deps->texts->get('broadcast.read_marked');
        }

        $keyboard = $deps->keyboards->buildTextKeyboard($broadcastId, true);
        SafeTelegram::editMessageText(
            $bot,
            (int) $message->chat->id,
            (int) $message->message_id,
            $text,
            $keyboard,
        );
    }

    /**
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $user
     */
    public static function redrawRecipientMessage(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $broadcast,
        array $user,
    ): void {
        $message = CallbackUi::callbackMessage($bot);
        if ($message === null) {
            return;
        }

        $kind = (string) ($broadcast['kind'] ?? '');
        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $body = (string) ($broadcast['text'] ?? '');
        $author = $deps->users->findById((int) ($broadcast['author_user_id'] ?? 0));
        $header = $deps->texts->get('broadcast.message_header', [
            'author' => $author !== null
                ? $deps->rolePresenter->displayNameWithBadge($author)
                : '—',
        ]);
        $text = $header . "\n\n" . $body;

        $userId = (int) ($user['id'] ?? 0);
        if ($kind === BroadcastRepository::KIND_TEXT) {
            $recipients = $deps->broadcasts->listRecipients($broadcastId);
            foreach ($recipients as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId && ($row['read_at'] ?? null) !== null) {
                    $text .= "\n\n" . $deps->texts->get('broadcast.read_marked');
                    break;
                }
            }
            $keyboard = $deps->delivery->recipientKeyboard($broadcast, [], $userId);
        } else {
            $options = $deps->broadcasts->listOptions($broadcastId);
            $keyboard = $deps->delivery->recipientKeyboard($broadcast, $options, $userId);
        }

        SafeTelegram::editMessageText(
            $bot,
            (int) $message->chat->id,
            (int) $message->message_id,
            $text,
            $keyboard,
        );
    }
}