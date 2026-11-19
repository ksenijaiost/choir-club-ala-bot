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
    public function afterMaterialSaved(array $actor, array $result): void
    {
        $type = (string) ($result['type'] ?? '');
        $song = $result['song'] ?? null;
        if (!is_array($song)) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $voiceParts = is_array($result['voice_parts'] ?? null) ? $result['voice_parts'] : [];

        match ($type) {
            MaterialType::NOTES => $this->notifyEveryone($song, $actorId, 'notes'),
            MaterialType::TRANSLIT => $this->notifyEveryone($song, $actorId, 'translit'),
            MaterialType::RECORDING => $this->notifyRecording($song, $voiceParts, $actorId),
            default => null,
        };
    }

    /**
     * @param list<string> $voiceParts
     * @param array<string, mixed> $song
     */
    public function afterRecordingReassigned(array $song, array $voiceParts, int $actorUserId): void
    {
        if ($voiceParts === []) {
            return;
        }

        $this->notifyRecording($song, $voiceParts, $actorUserId);
    }

    /**
     * @param array<string, mixed> $song
     */
    public function afterNotesReplaced(array $song, int $actorUserId): void
    {
        $this->notifyEveryone($song, $actorUserId, 'notes');
    }

    /**
     * @param array<string, mixed> $song
     */
    private function notifyEveryone(array $song, int $excludeUserId, string $kind): void
    {
        $songId = (int) ($song['id'] ?? 0);
        $title = (string) ($song['title'] ?? '');
        if ($songId === 0 || $title === '') {
            return;
        }

        $text = match ($kind) {
            'translit' => $this->texts->get('notifications.material_translit', ['title' => $title]),
            default => $this->texts->get('notifications.material_notes', ['title' => $title]),
        };

        $keyboard = $this->openSongKeyboard($songId);
        foreach ($this->users->listActiveParticipants() as $user) {
            if (!$this->users->materialNotificationsEnabled($user)) {
                continue;
            }

            $userId = (int) ($user['id'] ?? 0);
            if ($userId === $excludeUserId) {
                continue;
            }

            $this->sendToTelegramId((int) ($user['telegram_id'] ?? 0), $text, $keyboard);
        }
    }

    /**
     * @param array<string, mixed> $song
     * @param list<string> $voiceParts
     */
    private function notifyRecording(array $song, array $voiceParts, int $excludeUserId): void
    {
        $normalized = $this->materials->normalizeVoiceParts($voiceParts);
        if ($normalized === []) {
            return;
        }

        $songId = (int) ($song['id'] ?? 0);
        $title = (string) ($song['title'] ?? '');
        if ($songId === 0 || $title === '') {
            return;
        }

        $partLabel = $this->materials->formatRecordingPartsLabel($normalized);
        $text = $this->texts->get('notifications.material_recording', [
            'title' => $title,
            'part' => $partLabel,
        ]);
        $keyboard = $this->openSongKeyboard($songId);

        $isTutti = VoicePart::isTuttiParts($normalized);
        foreach ($this->resolveRecordingRecipients($normalized, $isTutti, $excludeUserId) as $telegramId) {
            $this->sendToTelegramId($telegramId, $text, $keyboard);
        }
    }

    /**
     * @param list<string> $voiceParts
     * @return list<int>
     */
    private function resolveRecordingRecipients(array $voiceParts, bool $isTutti, int $excludeUserId): array
    {
        $telegramIds = [];
        foreach ($this->users->listActiveParticipants() as $user) {
            if (!$this->users->materialNotificationsEnabled($user)) {
                continue;
            }

            $userId = (int) ($user['id'] ?? 0);
            if ($userId === $excludeUserId) {
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

    private function sendToTelegramId(int $telegramId, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($telegramId <= 0) {
            return;
        }

        try {
            TelegramApiRetry::run(function () use ($telegramId, $text, $keyboard): void {
                $this->outbound->sendMessage($telegramId, $text, $keyboard);
            });
        } catch (TelegramException $e) {
            if (TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                return;
            }

            if (self::isBlockedOrUnreachable($e)) {
                error_log('[material-notify] blocked or unreachable chat_id=' . $telegramId . ': ' . $e->getMessage());

                return;
            }

            error_log('[material-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            if (TelegramApiErrors::isVoiceMessagesForbidden($e)) {
                return;
            }

            if (self::isBlockedOrUnreachable($e)) {
                error_log('[material-notify] blocked or unreachable chat_id=' . $telegramId . ': ' . $e->getMessage());

                return;
            }

            error_log('[material-notify] send failed chat_id=' . $telegramId . ': ' . $e->getMessage());
        }

        usleep(self::PAUSE_MICROSECONDS);
    }

    private static function isBlockedOrUnreachable(\Throwable $e): bool
    {
        $message = $e->getMessage();
        if ($e instanceof TelegramException && ($e->getCode() === 403 || $e->getCode() === 400)) {
            return true;
        }

        return str_contains($message, 'bot was blocked')
            || str_contains($message, 'user is deactivated')
            || str_contains($message, 'chat not found')
            || str_contains($message, 'PEER_ID_INVALID');
    }
}