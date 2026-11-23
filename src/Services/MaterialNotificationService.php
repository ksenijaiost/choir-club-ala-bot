<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\VoicePart;
use App\Repositories\UserRepository;
use App\Telegram\TelegramApiErrors;
use App\Telegram\TelegramApiRetry;
use App\Telegram\TelegramOutbound;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MaterialNotificationService
{
    private const PAUSE_MICROSECONDS = 100_000;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TextRepository $texts,
        private readonly MaterialsService $materials,
        private readonly TelegramOutbound $outbound,
    ) {
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $result saveUpload / saveTextUpload result
     */
    public function afterMaterialSaved(array $actor, array $result): int
    {
        try {
            $type = (string) ($result['type'] ?? '');
            $song = $result['song'] ?? null;
            if (!is_array($song)) {
                return 0;
            }

            $voiceParts = is_array($result['voice_parts'] ?? null) ? $result['voice_parts'] : [];

            return match ($type) {
                MaterialType::NOTES => $this->notifyEveryone($song, 'notes'),
                MaterialType::TRANSLIT => $this->notifyEveryone($song, 'translit'),
                MaterialType::RECORDING => $this->notifyRecording($song, $voiceParts),
                default => 0,
            };
        } catch (\Throwable $e) {
            error_log('[material-notify] afterMaterialSaved failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * @param list<string> $voiceParts
     * @param array<string, mixed> $song
     */
    public function afterRecordingReassigned(array $song, array $voiceParts): int
    {
        if ($voiceParts === []) {
            return 0;
        }

        try {
            return $this->notifyRecording($song, $voiceParts);
        } catch (\Throwable $e) {
            error_log('[material-notify] afterRecordingReassigned failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * @param array<string, mixed> $song
     */
    public function afterNotesReplaced(array $song): int
    {
        try {
            return $this->notifyEveryone($song, 'notes');
        } catch (\Throwable $e) {
            error_log('[material-notify] afterNotesReplaced failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * @param array<string, mixed> $song
     */
    private function notifyEveryone(array $song, string $kind): int
    {
        $songId = (int) ($song['id'] ?? 0);
        $title = (string) ($song['title'] ?? '');
        if ($songId === 0 || $title === '') {
            return 0;
        }

        $text = match ($kind) {
            'translit' => $this->texts->get('notifications.material_translit', ['title' => $title]),
            default => $this->texts->get('notifications.material_notes', ['title' => $title]),
        };

        $keyboard = $this->openSongKeyboard($songId);
        $sent = 0;
        foreach ($this->users->listActiveParticipants() as $user) {
            if (!$this->users->materialNotificationsEnabled($user)) {
                continue;
            }

            if ($this->sendToTelegramId((int) ($user['telegram_id'] ?? 0), $text, $keyboard)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $song
     * @param list<string> $voiceParts
     */
    private function notifyRecording(array $song, array $voiceParts): int
    {
        $normalized = $this->materials->normalizeVoiceParts($voiceParts);
        if ($normalized === []) {
            return 0;
        }

        $songId = (int) ($song['id'] ?? 0);
        $title = (string) ($song['title'] ?? '');
        if ($songId === 0 || $title === '') {
            return 0;
        }

        $partLabel = $this->materials->formatRecordingPartsLabel($normalized);
        $text = $this->texts->get('notifications.material_recording', [
            'title' => $title,
            'part' => $partLabel,
        ]);
        $keyboard = $this->openSongKeyboard($songId);

        $isTutti = VoicePart::isTuttiParts($normalized);
        $sent = 0;
        foreach ($this->resolveRecordingRecipients($normalized, $isTutti) as $telegramId) {
            if ($this->sendToTelegramId($telegramId, $text, $keyboard)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param list<string> $voiceParts
     * @return list<int>
     */
    private function resolveRecordingRecipients(array $voiceParts, bool $isTutti): array
    {
        $telegramIds = [];
        foreach ($this->users->listActiveParticipants() as $user) {
            if (!$this->users->materialNotificationsEnabled($user)) {
                continue;
            }

            $role = (string) ($user['role'] ?? '');
            if ($role === UserRole::CONDUCTOR) {
                $telegramIds[] = (int) ($user['telegram_id'] ?? 0);

                continue;
            }

            if ($isTutti) {
                $telegramIds[] = (int) ($user['telegram_id'] ?? 0);

                continue;
            }

            $userPart = (string) ($user['voice_part'] ?? '');
            if ($userPart !== '' && in_array($userPart, $voiceParts, true)) {
                $telegramIds[] = (int) ($user['telegram_id'] ?? 0);
            }
        }

        $telegramIds = array_values(array_unique(array_filter($telegramIds, static fn (int $id): bool => $id > 0)));
        sort($telegramIds);

        return $telegramIds;
    }

    private function openSongKeyboard(int $songId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('notifications.open_song'),
                callback_data: 'snd:pk:' . $songId,
            ),
        );
    }

    private function sendToTelegramId(int $telegramId, string $text, InlineKeyboardMarkup $keyboard): bool
    {
        if ($telegramId <= 0) {
            return false;
        }

        $sent = false;
        try {
            $messageId = TelegramApiRetry::run(function () use ($telegramId, $text, $keyboard): ?int {
                return $this->outbound->sendMessage($telegramId, $text, $keyboard);
            });
            $sent = $messageId !== null;
        } catch (TelegramException $e) {
            if (TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                return false;
            }

            if (TelegramApiErrors::isRecipientUnreachable($e)) {
                error_log('[material-notify] skip chat_id=' . $telegramId . ': ' . $e->getMessage());

                return false;
            }

            error_log('[material-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            if (TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                return false;
            }

            if (TelegramApiErrors::isRecipientUnreachable($e)) {
                error_log('[material-notify] skip chat_id=' . $telegramId . ': ' . $e->getMessage());

                return false;
            }

            error_log('[material-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());
        }

        if ($sent) {
            usleep(self::PAUSE_MICROSECONDS);
        }

        return $sent;
    }
}