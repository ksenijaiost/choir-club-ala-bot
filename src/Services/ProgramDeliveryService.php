<?php

declare(strict_types=1);

namespace App\Services;

use App\Clock;
use App\Config;
use App\Repositories\ProgramNotifyDeliveryRepository;
use App\Repositories\ProgramRepository;
use App\Repositories\UserRepository;
use App\Telegram\SafeTelegram;
use App\Telegram\TelegramApiRetry;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ProgramDeliveryService
{
    private const PAUSE_MICROSECONDS = 100_000;

    public function __construct(
        private readonly Config $config,
        private readonly UserRepository $users,
        private readonly ProgramRepository $programs,
        private readonly ProgramMessageBuilder $messages,
        private readonly TextRepository $texts,
        private readonly TelegramOutbound $outbound,
        private readonly ProgramNotifyDeliveryRepository $notifyDeliveries,
    ) {
    }

    /**
     * @param array<string, mixed> $program
     * @return array{
     *   sent: int,
     *   total: int,
     *   failures: list<array{user_id: int, name: string, reason_label: string, error_code: string}>
     * }
     */
    public function notifyParticipants(Nutgram $bot, array $program, bool $isUpdate): array
    {
        $programId = (int) ($program['id'] ?? 0);
        if ($programId <= 0 || $this->programs->songCount($programId) < 1) {
            return ['sent' => 0, 'total' => 0, 'failures' => []];
        }

        $text = $this->messages->notifyBody($program, $isUpdate);
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('programs.button_open_recordings'),
                callback_data: 'prg:in:' . $programId,
            ),
        );

        $total = 0;
        $sent = 0;
        $failures = [];

        foreach ($this->users->listActiveParticipants() as $participant) {
            $userId = (int) ($participant['id'] ?? 0);
            $telegramId = (int) ($participant['telegram_id'] ?? 0);
            if ($telegramId <= 0 || $userId <= 0) {
                continue;
            }
            $total++;
            $error = $this->sendToTelegramId($telegramId, $text, $keyboard);
            if ($error === null) {
                $this->notifyDeliveries->recordSent($programId, $userId);
                $sent++;
            } else {
                $code = TelegramDeliveryReason::codeFromRaw($error);
                $this->notifyDeliveries->recordFailed($programId, $userId, $code);
                $failures[] = [
                    'user_id' => $userId,
                    'name' => (string) ($participant['display_name'] ?? ''),
                    'reason_label' => TelegramDeliveryReason::label($this->texts, $code),
                    'error_code' => $code,
                ];
            }
        }

        if ($sent > 0) {
            $this->programs->markNotified($programId);
        }

        return ['sent' => $sent, 'total' => $total, 'failures' => $failures];
    }

    /**
     * @param array{
     *   sent: int,
     *   total: int,
     *   failures: list<array{user_id: int, name: string, reason_label: string, error_code: string}>
     * } $result
     */
    public function sendResultSummary(Nutgram $bot, int $chatId, array $result): void
    {
        if ($chatId <= 0) {
            return;
        }

        $summary = TelegramDeliveryReason::formatSummary(
            $this->texts,
            (int) $result['sent'],
            (int) $result['total'],
            $result['failures'],
        );

        try {
            TelegramApiRetry::run(static function () use ($bot, $chatId, $summary): void {
                SafeTelegram::sendMessage($bot, $summary, $chatId);
            });
        } catch (\Throwable) {
        }
    }

    public function todayIso(): string
    {
        $tz = new \DateTimeZone($this->config->timezone);

        return Clock::now($this->config)->setTimezone($tz)->format('Y-m-d');
    }

    /**
     * @return ?string error code on failure
     */
    private function sendToTelegramId(int $telegramId, string $text, InlineKeyboardMarkup $keyboard): ?string
    {
        try {
            $messageId = TelegramApiRetry::run(function () use ($telegramId, $text, $keyboard): ?int {
                return $this->outbound->sendMessage($telegramId, $text, $keyboard, ParseMode::HTML);
            });
            if ($messageId !== null) {
                usleep(self::PAUSE_MICROSECONDS);

                return null;
            }

            return TelegramDeliveryReason::OTHER;
        } catch (\Throwable $e) {
            $code = TelegramDeliveryReason::codeFromThrowable($e);
            error_log('[program-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());

            return $code;
        }
    }
}