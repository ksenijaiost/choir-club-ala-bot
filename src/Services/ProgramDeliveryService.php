<?php

declare(strict_types=1);

namespace App\Services;

use App\Clock;
use App\Config;
use App\Repositories\ProgramRepository;
use App\Repositories\UserRepository;
use App\Telegram\TelegramApiErrors;
use App\Telegram\TelegramApiRetry;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
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
    ) {
    }

    /**
     * @param array<string, mixed> $program
     * @return array{sent: int, total: int}
     */
    public function notifyParticipants(Nutgram $bot, array $program, bool $isUpdate): array
    {
        if ($this->programs->songCount((int) $program['id']) < 1) {
            return ['sent' => 0, 'total' => 0];
        }

        $text = $this->messages->notifyBody($program, $isUpdate);
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('programs.button_open_recordings'),
                callback_data: 'prg:mo:' . (int) $program['id'],
            ),
        );

        $total = 0;
        $sent = 0;
        foreach ($this->users->listActiveParticipants() as $participant) {
            $telegramId = (int) ($participant['telegram_id'] ?? 0);
            if ($telegramId <= 0) {
                continue;
            }
            $total++;
            if ($this->sendToTelegramId($telegramId, $text, $keyboard)) {
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->programs->markNotified((int) $program['id']);
        }

        return ['sent' => $sent, 'total' => $total];
    }

    public function sendResultToConductor(Nutgram $bot, int $conductorTelegramId, int $sent, int $total): void
    {
        if ($conductorTelegramId <= 0) {
            return;
        }
        try {
            TelegramApiRetry::run(function () use ($conductorTelegramId, $sent, $total): void {
                $this->outbound->sendMessage(
                    $conductorTelegramId,
                    $this->texts->get('programs.notify_result', [
                        'sent' => (string) $sent,
                        'total' => (string) $total,
                    ]),
                );
            });
        } catch (\Throwable) {
        }
    }

    public function todayIso(): string
    {
        $tz = new \DateTimeZone($this->config->timezone);

        return Clock::now($this->config)->setTimezone($tz)->format('Y-m-d');
    }

    private function sendToTelegramId(int $telegramId, string $text, InlineKeyboardMarkup $keyboard): bool
    {
        try {
            $messageId = TelegramApiRetry::run(function () use ($telegramId, $text, $keyboard): ?int {
                return $this->outbound->sendMessage($telegramId, $text, $keyboard);
            });
            if ($messageId !== null) {
                usleep(self::PAUSE_MICROSECONDS);

                return true;
            }

            return false;
        } catch (TelegramException $e) {
            if (TelegramApiErrors::isRecipientUnreachable($e) || TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                error_log('[program-notify] skip chat_id=' . $telegramId . ': ' . $e->getMessage());

                return false;
            }
            error_log('[program-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            if (TelegramApiErrors::isRecipientUnreachable($e) || TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                error_log('[program-notify] skip chat_id=' . $telegramId . ': ' . $e->getMessage());

                return false;
            }
            error_log('[program-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());

            return false;
        }
    }
}