<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Services\UserIdentitySync;
use App\Telegram\TelegramFileExtractor;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class MaterialsAdminChatHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        MaterialsDeps $deps,
    ): void {
        $bot->onMessage(function (Nutgram $bot) use ($access, $deps): void {
            $chat = $bot->chat();
            if ($chat === null || !$access->isAdminChatId($chat->id)) {
                return;
            }

            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            UserIdentitySync::syncPrivilegedTelegramUser(
                $telegramId,
                trim($bot->user()?->first_name ?? ''),
                $access,
                $deps->users,
            );

            $user = $deps->users->findByTelegramId($telegramId);
            if ($user === null || !$access->canUploadMaterials($user)) {
                return;
            }

            $message = $bot->message();
            if ($message === null) {
                return;
            }

            $file = TelegramFileExtractor::fromMessage($message);
            if ($file !== null) {
                self::onFileReceived($bot, $deps, $user, $file, (int) $chat->id, (int) $message->message_id);

                return;
            }

            $text = trim($message->text ?? '');
            if ($text !== '' && !str_starts_with($text, '/')) {
                MaterialsFlowHandler::handleAdminChatText($bot, $deps, $telegramId, $text);
            }
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array{file_id: string, file_unique_id: string} $file
     */
    private static function onFileReceived(
        Nutgram $bot,
        MaterialsDeps $deps,
        array $user,
        array $file,
        int $chatId,
        int $messageId,
    ): void {
        $duplicate = $deps->service->findDuplicate($file['file_unique_id']);
        if ($duplicate !== null) {
            $bot->sendMessage(
                chat_id: $chatId,
                text: $deps->service->duplicateCardText($duplicate),
            );

            return;
        }

        $state = $deps->flows->get((int) $user['telegram_id']);
        if ($state !== null && ($state['flow'] ?? '') === 'material_replace_recording') {
            $ctx = $state['context'];
            $targetId = (int) ($ctx['material_id'] ?? 0);
            $target = $deps->materials->findById($targetId);
            if ($target === null) {
                $deps->flows->clear((int) $user['telegram_id']);
                $bot->sendMessage($deps->texts->get('errors.generic'));

                return;
            }

            $dup = $deps->service->findDuplicate($file['file_unique_id']);
            if ($dup !== null) {
                $bot->sendMessage($deps->service->duplicateCardText($dup));

                return;
            }

            $deps->service->replaceRecordingFile($user, $target, $file['file_id'], $file['file_unique_id'], $messageId);
            $deps->flows->clear((int) $user['telegram_id']);
            $song = $deps->songs->findById((int) $target['song_id']);
            if ($song !== null) {
                $bot->sendMessage($deps->service->uploadSuccessText(
                    $song,
                    (string) $target['type'],
                    (string) ($target['voice_part'] ?? ''),
                ));
            }

            return;
        }

        $deps->flows->set((int) $user['telegram_id'], 'material_upload', [
            'file_id' => $file['file_id'],
            'file_unique_id' => $file['file_unique_id'],
            'admin_chat_id' => $chatId,
            'admin_chat_message_id' => $messageId,
        ]);

        MaterialsFlowHandler::sendSongPicker($bot, $deps, $chatId);
    }
}
