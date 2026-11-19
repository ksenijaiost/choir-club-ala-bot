<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Services\AccessControl;
use App\Services\TranslitUploadCommand;
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
            if ($user === null) {
                return;
            }

            $message = $bot->message();
            if ($message === null) {
                return;
            }

            $rawText = $message->text ?? $message->caption;
            if ($rawText !== null && TranslitUploadCommand::isInvoked($rawText, $deps->config->botUsername)) {
                if (!$access->canUploadMaterials($user)) {
                    return;
                }

                self::onTranslitCommand($bot, $deps, $user, $message, (int) $chat->id, (int) $message->message_id);

                return;
            }

            if (!$access->canUploadMaterials($user)) {
                return;
            }

            $file = TelegramFileExtractor::fromMessage($message);
            if ($file !== null) {
                self::onFileReceived($bot, $deps, $user, $file, (int) $chat->id, (int) $message->message_id);

                return;
            }

            $text = trim($rawText ?? '');
            if ($text !== '' && !str_starts_with($text, '/')) {
                if (MaterialsRecordingEditHandler::handleAdminChatSearchText($bot, $deps, $telegramId, (int) $chat->id, $text)) {
                    return;
                }

                MaterialsFlowHandler::handleAdminChatText($bot, $deps, $telegramId, $text);
            }
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function onTranslitCommand(
        Nutgram $bot,
        MaterialsDeps $deps,
        array $user,
        \SergiX44\Nutgram\Telegram\Types\Message\Message $message,
        int $chatId,
        int $messageId,
    ): void {
        $text = TranslitUploadCommand::extractText($message, $deps->config->botUsername);
        if ($text === null) {
            $bot->sendMessage(
                chat_id: $chatId,
                text: $deps->texts->get('materials.translit_upload_hint'),
            );

            return;
        }

        $deps->flows->set((int) $user['telegram_id'], 'material_upload', [
            'source' => 'text_translit',
            'text_content' => $text,
            'type' => MaterialType::TRANSLIT,
            'step' => 'song',
            'admin_chat_id' => $chatId,
            'admin_chat_message_id' => $messageId,
        ]);

        MaterialsFlowHandler::sendSongPicker($bot, $deps, page: 0, chatId: $chatId);
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

            $type = (string) ($target['type'] ?? '');
            if ($type === MaterialType::NOTES) {
                $deps->service->replaceNotesFile($user, $target, $file['file_id'], $file['file_unique_id'], $messageId);
            } else {
                $deps->service->replaceRecordingFile($user, $target, $file['file_id'], $file['file_unique_id'], $messageId);
            }
            $deps->flows->clear((int) $user['telegram_id']);
            $song = $deps->songs->findById((int) $target['song_id']);
            if ($song !== null) {
                if ($type === MaterialType::NOTES) {
                    $deps->materialNotifications->afterNotesReplaced($song, (int) $user['id']);
                }
                $bot->sendMessage($deps->service->uploadSuccessText(
                    $song,
                    $type,
                    [],
                ));
            }

            return;
        }

        $deps->flows->set((int) $user['telegram_id'], 'material_upload', [
            'source' => 'file',
            'file_id' => $file['file_id'],
            'file_unique_id' => $file['file_unique_id'],
            'admin_chat_id' => $chatId,
            'admin_chat_message_id' => $messageId,
            'step' => 'song',
        ]);

        MaterialsFlowHandler::sendSongPicker($bot, $deps, page: 0, chatId: $chatId);
    }
}