<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BroadcastRepository;
use App\Repositories\UserRepository;
use App\Telegram\TelegramApiRetry;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class BroadcastDeliveryService
{
    private const PAUSE_MICROSECONDS = 100_000;

    public function __construct(
        private readonly UserRepository $users,
        private readonly BroadcastRepository $broadcasts,
        private readonly TextRepository $texts,
        private readonly UserRolePresenter $roles,
        private readonly BroadcastKeyboardBuilder $keyboards,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @param array<string, mixed> $author
     * @return array{
     *   sent: int,
     *   failed: int,
     *   total: int,
     *   failures: list<array{user_id: int, name: string, reason_label: string, error_code: string}>
     * }
     */
    public function deliverToParticipants(int $broadcastId, array $author): array
    {
        $broadcast = $this->broadcasts->find($broadcastId);
        if ($broadcast === null) {
            return ['sent' => 0, 'failed' => 0, 'total' => 0, 'failures' => []];
        }

        $kind = (string) ($broadcast['kind'] ?? '');
        $body = (string) ($broadcast['text'] ?? '');
        $header = $this->texts->get('broadcast.message_header', [
            'author' => $this->roles->displayNameWithBadge($author),
        ]);
        $messageText = $header . "\n\n" . $body;

        $options = $kind === BroadcastRepository::KIND_POLL
            ? $this->broadcasts->listOptions($broadcastId)
            : [];

        $sent = 0;
        $failed = 0;
        $failures = [];

        foreach ($this->users->listActiveParticipants() as $participant) {
            $userId = (int) ($participant['id'] ?? 0);
            $telegramId = (int) ($participant['telegram_id'] ?? 0);
            if ($userId <= 0 || $telegramId <= 0) {
                continue;
            }

            $this->broadcasts->addRecipient($broadcastId, $userId);
            $keyboard = $this->recipientKeyboard($broadcast, $options, $userId);

            $sendResult = $this->sendToTelegramId($telegramId, $messageText, $keyboard);
            if (is_int($sendResult)) {
                $this->broadcasts->markDelivered($broadcastId, $userId, $sendResult);
                $sent++;
            } else {
                $this->broadcasts->markDeliveryFailed($broadcastId, $userId, $sendResult);
                $failed++;
                $failures[] = [
                    'user_id' => $userId,
                    'name' => (string) ($participant['display_name'] ?? ''),
                    'reason_label' => TelegramDeliveryReason::label($this->texts, $sendResult),
                    'error_code' => $sendResult,
                ];
            }
        }

        $total = $sent + $failed;

        return ['sent' => $sent, 'failed' => $failed, 'total' => $total, 'failures' => $failures];
    }

    /**
     * @param list<array<string, mixed>> $options
     */
    public function recipientKeyboard(
        array $broadcast,
        array $options,
        int $userId,
    ): InlineKeyboardMarkup {
        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $kind = (string) ($broadcast['kind'] ?? '');
        if ($kind === BroadcastRepository::KIND_TEXT) {
            $recipients = $this->broadcasts->listRecipients($broadcastId);
            $read = false;
            foreach ($recipients as $row) {
                if ((int) ($row['user_id'] ?? 0) === $userId && ($row['read_at'] ?? null) !== null) {
                    $read = true;
                    break;
                }
            }

            return $this->keyboards->buildTextKeyboard($broadcastId, $read);
        }

        $selected = $this->broadcasts->selectedOptionIds($broadcastId, $userId);
        $custom = $this->broadcasts->findCustomAnswer($broadcastId, $userId);

        return $this->keyboards->buildPollKeyboard(
            $broadcast,
            $options,
            $selected,
            $custom !== null,
            (bool) ((int) ($broadcast['allow_custom'] ?? 0)),
        );
    }

    /**
     * @return int|string message id or error code
     */
    private function sendToTelegramId(int $telegramId, string $text, InlineKeyboardMarkup $keyboard): int|string
    {
        if ($telegramId <= 0) {
            return TelegramDeliveryReason::NEVER_STARTED;
        }

        try {
            $messageId = TelegramApiRetry::run(function () use ($telegramId, $text, $keyboard): ?int {
                return $this->outbound->sendMessage($telegramId, $text, $keyboard);
            });
            if ($messageId !== null) {
                usleep(self::PAUSE_MICROSECONDS);

                return $messageId;
            }

            return TelegramDeliveryReason::OTHER;
        } catch (\Throwable $e) {
            $code = TelegramDeliveryReason::codeFromThrowable($e);
            error_log('[broadcast-delivery] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());

            return $code;
        }
    }
}