<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MaterialsCallbackHandler
{
    public static function handle(
        Nutgram $bot,
        MaterialsDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'mat:')) {
            return false;
        }

        if (!$access->canUploadMaterials($actor)) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('materials.upload_forbidden'),
                'show_alert' => true,
            ]);

            return true;
        }

        $telegramId = (int) $actor['telegram_id'];
        $adminChatId = (int) ($bot->chatId() ?? 0);
        $isAdminChat = $adminChatId !== 0 && $access->isAdminChatId($adminChatId);

        if ($isAdminChat && str_starts_with($data, 'mat:ac:ed:')) {
            if (!MaterialsRecordingEditHandler::assertAdminChatMenuActor($bot, $deps, $access, $actor)) {
                return true;
            }

            if (preg_match('/^' . preg_quote(MaterialsRecordingEditHandler::ADMIN_SONG_PAGE_PREFIX, '/') . '(\d+)$/', $data, $m) === 1) {
                SafeTelegram::answerCallbackQuery($bot);
                $state = $deps->flows->get($telegramId);
                $query = is_array($state['context'] ?? null)
                    ? (string) ($state['context']['search_query'] ?? '')
                    : '';
                MaterialsRecordingEditHandler::sendAdminSongPicker(
                    $bot,
                    $deps,
                    $telegramId,
                    $adminChatId,
                    (int) $m[1],
                    $query,
                );

                return true;
            }

            if (preg_match('/^' . preg_quote(MaterialsRecordingEditHandler::ADMIN_SONG_PICK_PREFIX, '/') . '(\d+)$/', $data, $m) === 1) {
                SafeTelegram::answerCallbackQuery($bot);
                MaterialsRecordingEditHandler::onAdminSongPicked(
                    $bot,
                    $deps,
                    $telegramId,
                    (int) $m[1],
                    $adminChatId,
                );

                return true;
            }

            return true;
        }

        if ($isAdminChat && str_starts_with($data, 'mat:mg:erec:')) {
            if (!MaterialsRecordingEditHandler::assertAdminChatMenuActor($bot, $deps, $access, $actor)) {
                return true;
            }
        }

        if (preg_match('/^mat:up:sn:pg:(\d+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);
            MaterialsFlowHandler::sendSongPicker($bot, $deps, (int) $m[1]);

            return true;
        }

        if ($data === 'mat:up:back:sn') {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);
            MaterialsFlowHandler::sendSongPicker($bot, $deps, 0);

            return true;
        }

        if ($data === 'mat:up:back:tp') {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);
            MaterialsFlowHandler::sendTypePicker($bot, $deps, $telegramId);

            return true;
        }

        if (preg_match('/^mat:up:sn:(new|\d+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);

            $state = $deps->flows->get($telegramId);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                return true;
            }

            $ctx = $state['context'];
            if ($m[1] === 'new') {
                $ctx['step'] = 'new_song_title';
                $ctx['awaiting_title'] = true;
                $deps->flows->set($telegramId, 'material_upload', $ctx);
                if (MaterialsFlowHandler::isAdminUploadWizard($deps, $telegramId)) {
                    MaterialsFlowHandler::presentUploadStep(
                        $bot,
                        $deps,
                        $telegramId,
                        $deps->texts->get('materials.new_song_prompt'),
                        InlineMenuHelper::appendFooter(
                            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make(),
                            $deps->texts,
                            'mat:up:back:sn',
                        ),
                    );
                } else {
                    SafeTelegram::sendMessage($bot, $deps->texts->get('materials.new_song_prompt'));
                }

                return true;
            }

            $ctx['song_id'] = (int) $m[1];

            if (MaterialsFlowHandler::isTextTranslitUpload($ctx)) {
                $ctx['type'] = MaterialType::TRANSLIT;
                $ctx['step'] = 'save';
                $deps->flows->set((int) $actor['telegram_id'], 'material_upload', $ctx);
                MaterialsFlowHandler::completeUpload($bot, $deps, $actor);

                return true;
            }

            $ctx['step'] = 'type';
            $deps->flows->set($telegramId, 'material_upload', $ctx);
            MaterialsFlowHandler::sendTypePicker($bot, $deps, $telegramId);

            return true;
        }

        if (preg_match('/^mat:up:tp:(notes|translit|recording)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);

            if ($m[1] === MaterialType::TRANSLIT) {
                if (MaterialsFlowHandler::isAdminUploadWizard($deps, $telegramId)) {
                    MaterialsFlowHandler::presentUploadStep(
                        $bot,
                        $deps,
                        $telegramId,
                        $deps->texts->get('materials.translit_upload_hint'),
                        InlineMenuHelper::appendFooter(
                            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make(),
                            $deps->texts,
                            'mat:up:back:tp',
                        ),
                    );
                } else {
                    SafeTelegram::sendMessage($bot, $deps->texts->get('materials.translit_upload_hint'));
                }

                return true;
            }

            $type = $m[1];
            $state = $deps->flows->get($telegramId);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                return true;
            }

            $ctx = $state['context'];
            $ctx['type'] = $type;
            $ctx['step'] = $type === MaterialType::RECORDING ? 'part' : 'save';
            $deps->flows->set((int) $actor['telegram_id'], 'material_upload', $ctx);

            if ($type === MaterialType::RECORDING) {
                $ctx['selected_parts'] = [];
                $deps->flows->set((int) $actor['telegram_id'], 'material_upload', $ctx);
                MaterialsFlowHandler::sendRecordingPartPicker($bot, $deps, (int) $actor['telegram_id']);

                return true;
            }

            MaterialsFlowHandler::completeUpload($bot, $deps, $actor);

            return true;
        }

        if (preg_match('/^mat:up:pt:t:([a-z0-9_]+)$/', $data, $m) === 1) {
            $part = $m[1];
            if (!VoicePart::isValid($part)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $telegramId = (int) $actor['telegram_id'];
            $state = $deps->flows->get($telegramId);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                $bot->answerCallbackQuery();

                return true;
            }

            $ctx = $state['context'];
            $selected = $deps->service->normalizeVoiceParts($ctx['selected_parts'] ?? []);
            if (in_array($part, $selected, true)) {
                $selected = array_values(array_filter($selected, static fn (string $p): bool => $p !== $part));
            } else {
                $selected[] = $part;
                sort($selected);
            }

            $ctx['selected_parts'] = $selected;
            $deps->flows->set($telegramId, 'material_upload', $ctx);

            $bot->answerCallbackQuery();
            $chatId = (int) ($ctx['part_picker_chat_id'] ?? 0);
            $messageId = (int) ($ctx['part_picker_message_id'] ?? 0);
            if ($chatId !== 0 && $messageId !== 0) {
                MaterialsFlowHandler::refreshRecordingPartPicker($bot, $deps, $chatId, $messageId, $selected);
            }

            return true;
        }

        if ($data === 'mat:up:pt:go') {
            $telegramId = (int) $actor['telegram_id'];
            $state = $deps->flows->get($telegramId);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }

            $ctx = $state['context'];
            if (MaterialsFlowHandler::isUploadSaveLocked($deps, $telegramId)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('materials.already_saving'),
                ]);

                return true;
            }

            $selected = $deps->service->normalizeVoiceParts($ctx['selected_parts'] ?? []);
            if ($selected === []) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('materials.pick_parts_none'),
                    'show_alert' => true,
                ]);

                return true;
            }

            $ctx['selected_parts'] = $selected;
            $ctx['step'] = 'save';
            $deps->flows->set($telegramId, 'material_upload', $ctx);
            MaterialsFlowHandler::completeUpload($bot, $deps, $actor);
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'mat:up:rp:y') {
            $telegramId = (int) $actor['telegram_id'];
            if (MaterialsFlowHandler::isUploadSaveLocked($deps, $telegramId)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('materials.already_saving'),
                ]);

                return true;
            }

            $state = $deps->flows->get($telegramId);
            if ($state !== null && ($state['flow'] ?? '') === 'material_upload') {
                $ctx = $state['context'];
                $ctx['step'] = 'save';
                $deps->flows->set($telegramId, 'material_upload', $ctx);
            }

            MaterialsFlowHandler::completeUpload($bot, $deps, $actor, true);
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'mat:up:rp:n') {
            $telegramId = (int) $actor['telegram_id'];
            $state = $deps->flows->get($telegramId);
            $wizardMessageId = 0;
            $adminChatId = 0;
            if ($state !== null && ($state['flow'] ?? '') === 'material_upload') {
                $wizardMessageId = (int) ($state['context']['wizard_message_id'] ?? 0);
                $adminChatId = (int) ($state['context']['admin_chat_id'] ?? 0);
            }

            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);
            $deps->flows->clear($telegramId);
            if ($wizardMessageId !== 0 && $adminChatId !== 0) {
                SafeTelegram::editMessageText(
                    $bot,
                    $adminChatId,
                    $wizardMessageId,
                    $deps->texts->get('materials.cancelled'),
                    InlineKeyboardMarkup::make(),
                );
            } else {
                SafeTelegram::sendMessage($bot, $deps->texts->get('materials.cancelled'));
            }

            return true;
        }

        if (preg_match('/^mat:up:tr:(end|begin|repl)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            $state = $deps->flows->get($telegramId);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }

            if (MaterialsFlowHandler::isUploadSaveLocked($deps, $telegramId)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('materials.already_saving'),
                ]);

                return true;
            }

            $ctx = $state['context'];
            $ctx['text_notes_mode'] = match ($m[1]) {
                'end' => 'append_end',
                'begin' => 'append_start',
                default => 'replace',
            };
            $ctx['step'] = 'save';
            $deps->flows->set($telegramId, 'material_upload', $ctx);
            MaterialsFlowHandler::completeUpload($bot, $deps, $actor);
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'mat:up:nt:add' || $data === 'mat:up:nt:all') {
            $telegramId = (int) $actor['telegram_id'];
            $state = $deps->flows->get($telegramId);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }

            if (MaterialsFlowHandler::isUploadSaveLocked($deps, $telegramId)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $deps->texts->get('materials.already_saving'),
                ]);

                return true;
            }

            $ctx = $state['context'];
            $ctx['notes_mode'] = $data === 'mat:up:nt:add' ? 'add' : 'replace_all';
            $ctx['step'] = 'save';
            $deps->flows->set($telegramId, 'material_upload', $ctx);
            MaterialsFlowHandler::completeUpload($bot, $deps, $actor);
            SafeTelegram::answerCallbackQuery($bot);
            self::clearKeyboardUnlessAdminWizard($bot, $deps, $telegramId);

            return true;
        }

        if (preg_match('/^mat:mg:erec:(\d+)$/', $data, $m) === 1) {
            $songId = (int) $m[1];
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            CallbackUi::clearKeyboard($bot);
            $deps->flows->clear($telegramId);
            MaterialsRecordingEditHandler::openList($bot, $deps, $telegramId, $songId);

            return true;
        }

        if (preg_match('/^mat:mg:erec:src:set:(\d+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            $chatId = $isAdminChat ? $adminChatId : $bot->chatId();
            MaterialsRecordingEditHandler::beginSourceUrlEdit(
                $bot,
                $deps,
                $telegramId,
                (int) $m[1],
                $chatId !== null && $chatId !== 0 ? $chatId : null,
            );

            return true;
        }

        if (preg_match('/^mat:mg:erec:src:clr:(\d+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            $chatId = $isAdminChat ? $adminChatId : $bot->chatId();
            MaterialsRecordingEditHandler::clearSourceUrl(
                $bot,
                $deps,
                $telegramId,
                (int) $m[1],
                $chatId !== null && $chatId !== 0 ? $chatId : null,
            );

            return true;
        }

        if (preg_match('/^mat:mg:erec:p:(\d+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            $chatId = $isAdminChat ? $adminChatId : $bot->chatId();
            MaterialsRecordingEditHandler::openEditor(
                $bot,
                $deps,
                $telegramId,
                (int) $m[1],
                $chatId !== null && $chatId !== 0 ? $chatId : null,
            );

            return true;
        }

        if (preg_match('/^mat:mg:erec:lb:(\d+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            SafeTelegram::answerCallbackQuery($bot);
            MaterialsRecordingEditHandler::openList($bot, $deps, $telegramId, (int) $m[1]);

            return true;
        }

        if (preg_match('/^mat:mg:erec:s:(\d+):([a-z0-9_]+)$/', $data, $m) === 1) {
            $telegramId = (int) $actor['telegram_id'];
            $chatId = $isAdminChat ? $adminChatId : $bot->chatId();
            MaterialsRecordingEditHandler::applyPartChoice(
                $bot,
                $deps,
                $telegramId,
                (int) $m[1],
                $m[2],
                $chatId !== null && $chatId !== 0 ? $chatId : null,
            );

            return true;
        }

        if (preg_match('/^mat:mg:pg:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendManagementSongPicker($bot, $deps, (int) $m[1]);

            return true;
        }

        if (preg_match('/^mat:mg:sn:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendSongManageMenu($bot, $deps, (int) $m[1]);

            return true;
        }

        if (preg_match('/^mat:mg:ren:(\d+)$/', $data, $m) === 1) {
            $songId = (int) $m[1];
            $song = $deps->songs->findById($songId);
            if ($song === null) {
                $bot->answerCallbackQuery(text: $deps->texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $deps->flows->set((int) $actor['telegram_id'], 'material_rename', ['song_id' => $songId]);
            $keyboard = InlineMenuHelper::appendFooter(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make(),
                $deps->texts,
                'mat:mg:sn:' . $songId,
            );
            $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
            InlineMenuHelper::sendInteractiveMenu(
                $bot,
                $deps->interactiveMenus,
                $ownerTelegramId,
                $deps->texts->get('materials.rename_prompt'),
                $keyboard,
                $bot->chatId() ?? $ownerTelegramId,
            );

            return true;
        }

        if (preg_match('/^mat:mg:nt:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendNotesList($bot, $deps, (int) $m[1]);

            return true;
        }

        if (preg_match('/^mat:mg:rec:(\d+):([a-z0-9_]+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendRecordingList($bot, $deps, (int) $m[1], $m[2]);

            return true;
        }

        if (preg_match('/^mat:mg:del:(\d+)$/', $data, $m) === 1) {
            $id = (int) $m[1];
            $material = $deps->materials->findById($id);
            $type = (string) ($material['type'] ?? '');
            if ($material === null || !in_array($type, [MaterialType::RECORDING, MaterialType::NOTES], true)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $songId = (int) $material['song_id'];
            $deps->materials->archiveById($id);
            $deletedText = $type === MaterialType::NOTES
                ? $deps->texts->get('materials.notes_deleted')
                : $deps->texts->get('materials.recording_deleted');
            $bot->answerCallbackQuery(text: $deletedText);
            CallbackUi::clearKeyboard($bot);
            if ($type === MaterialType::NOTES) {
                self::sendNotesList($bot, $deps, $songId);
            }

            return true;
        }

        if (preg_match('/^mat:mg:rp:(\d+)$/', $data, $m) === 1) {
            $id = (int) $m[1];
            $material = $deps->materials->findById($id);
            $type = (string) ($material['type'] ?? '');
            if ($material === null || !in_array($type, [MaterialType::RECORDING, MaterialType::NOTES], true)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $deps->flows->set((int) $actor['telegram_id'], 'material_replace_recording', [
                'material_id' => $id,
            ]);
            $hint = $type === MaterialType::NOTES
                ? $deps->texts->get('materials.replace_notes_hint')
                : $deps->texts->get('materials.replace_upload_hint');
            $bot->sendMessage($hint);

            return true;
        }

        return false;
    }

    private static function clearKeyboardUnlessAdminWizard(Nutgram $bot, MaterialsDeps $deps, int $telegramId): void
    {
        if (!MaterialsFlowHandler::isAdminUploadWizard($deps, $telegramId)) {
            CallbackUi::clearKeyboard($bot);
        }
    }

    public static function openManagementHome(Nutgram $bot, MaterialsDeps $deps, int $page = 0): void
    {
        $total = $deps->songs->countAll();
        if ($total === 0) {
            $bot->sendMessage($deps->texts->get('materials.pick_song_empty'));

            return;
        }

        self::sendManagementSongPicker($bot, $deps, $page);
    }

    private static function sendManagementSongPicker(Nutgram $bot, MaterialsDeps $deps, int $page): void
    {
        $page = \App\Support\SqlPagination::clampPage($page);
        $total = $deps->songs->countAll();
        $pageSize = InlineMenuHelper::PAGE_SIZE;
        $songs = $deps->songs->listAlphabetical(
            \App\Support\SqlPagination::offsetForPage($page, $pageSize),
            $pageSize,
        );

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
        foreach ($songs as $song) {
            $id = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $label = mb_strlen($title) > 40 ? mb_substr($title, 0, 37) . '…' : $title;
            $keyboard = $keyboard->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $label,
                    callback_data: 'mat:mg:sn:' . $id,
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'mat:mg:pg:',
        );

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $deps->texts->get('materials.manage_pick_song'),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    private static function sendSongManageMenu(Nutgram $bot, MaterialsDeps $deps, int $songId): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $notesCount = $deps->materials->countCurrentNotes($songId);
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $deps->texts->get('buttons.rename_song'),
                    callback_data: 'mat:mg:ren:' . $songId,
                ),
            )
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $deps->texts->get('materials.manage_notes_button', ['count' => (string) $notesCount]),
                    callback_data: 'mat:mg:nt:' . $songId,
                ),
            );

        foreach ($deps->materials->listPartsWithRecordingsForSong($songId) as $code) {
            $count = $deps->materials->countRecordingsForPart($songId, $code);
            $keyboard = $keyboard->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $deps->texts->get('parts.' . $code) . ' (' . $count . ')',
                    callback_data: 'mat:mg:rec:' . $songId . ':' . $code,
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                $deps->texts->get('materials.edit_recordings_button'),
                callback_data: 'mat:mg:erec:' . $songId,
            ),
        );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:mg:pg:0');

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $deps->texts->get('materials.manage_song_menu', ['title' => (string) $song['title']]),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    private static function sendNotesList(Nutgram $bot, MaterialsDeps $deps, int $songId): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $rows = $deps->materials->listCurrentNotes($songId);
        if ($rows === []) {
            $bot->sendMessage($deps->texts->get('materials.notes_list_empty'));

            return;
        }

        $lines = [
            $deps->texts->get('materials.notes_list_header', ['title' => (string) $song['title']]),
        ];

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
        $index = 1;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $lines[] = $index . '. ' . ($row['uploaded_at'] ?? '');
            $keyboard = $keyboard->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    '✏️ #' . $index,
                    callback_data: 'mat:mg:rp:' . $id,
                ),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    '🗑 #' . $index,
                    callback_data: 'mat:mg:del:' . $id,
                ),
            );
            $index++;
        }

        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'mat:mg:sn:' . $songId,
        );

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            implode("\n", $lines),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    private static function sendRecordingList(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $songId,
        string $voicePart,
    ): void {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $rows = $deps->materials->listCurrentRecordings($songId, $voicePart);
        if ($rows === []) {
            $bot->sendMessage($deps->texts->get('materials.recording_list_empty'));

            return;
        }

        $lines = [
            $deps->texts->get('materials.recording_list_header', [
                'title' => (string) $song['title'],
                'part' => $deps->texts->get('parts.' . $voicePart),
            ]),
        ];

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
        $index = 1;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $partsLabel = $deps->service->formatVoicePartsLabel($row['voice_parts'] ?? []);
            $lines[] = $index . '. ' . ($row['uploaded_at'] ?? '') . ' — ' . $partsLabel;
            $keyboard = $keyboard->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    '✏️ #' . $index,
                    callback_data: 'mat:mg:rp:' . $id,
                ),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    '🗑 #' . $index,
                    callback_data: 'mat:mg:del:' . $id,
                ),
            );
            $index++;
        }

        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            'mat:mg:sn:' . $songId,
        );

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            implode("\n", $lines),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }
}
