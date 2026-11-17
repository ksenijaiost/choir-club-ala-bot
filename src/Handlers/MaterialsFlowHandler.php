<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Support\SqlPagination;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class MaterialsFlowHandler
{
    public static function isAdminUploadWizard(MaterialsDeps $deps, int $telegramId): bool
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
            return false;
        }

        return (int) ($state['context']['admin_chat_id'] ?? 0) !== 0;
    }

    public static function isUploadSaveLocked(MaterialsDeps $deps, int $telegramId): bool
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
            return false;
        }

        $ctx = $state['context'];

        return ($ctx['step'] ?? '') === 'save'
            || !empty($ctx['save_in_progress'])
            || !empty($ctx['material_saved']);
    }

    public static function presentUploadStep(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
        InlineKeyboardMarkup $keyboard,
        ?int $chatId = null,
    ): ?Message {
        $target = self::wizardTarget($bot, $deps, $telegramId, $chatId);
        if ($target === null) {
            return InlineMenuHelper::sendInteractiveMenu(
                $bot,
                $deps->interactiveMenus,
                $telegramId,
                $text,
                $keyboard,
                $chatId ?? $bot->chatId() ?? $telegramId,
            );
        }

        if ($target['message_id'] === 0) {
            $message = SafeTelegram::sendMessage($bot, $text, $target['chat_id'], $keyboard);
            if ($message !== null) {
                self::persistWizardMessageId($deps, $telegramId, (int) $message->message_id, (int) $message->chat->id);

                return $message;
            }

            return null;
        }

        SafeTelegram::editMessageText(
            $bot,
            $target['chat_id'],
            $target['message_id'],
            $text,
            $keyboard,
        );

        return null;
    }

    /**
     * @return array{chat_id: int, message_id: int}|null
     */
    private static function wizardTarget(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        ?int $chatId = null,
    ): ?array {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
            return null;
        }

        $ctx = $state['context'];
        $wizardChatId = (int) ($ctx['admin_chat_id'] ?? 0);
        if ($wizardChatId === 0) {
            return null;
        }

        $messageId = (int) ($ctx['wizard_message_id'] ?? 0);
        if ($messageId === 0) {
            $callbackMessage = CallbackUi::callbackMessage($bot);
            if ($callbackMessage !== null && (int) $callbackMessage->chat->id === $wizardChatId) {
                $messageId = (int) $callbackMessage->message_id;
                self::persistWizardMessageId($deps, $telegramId, $messageId, $wizardChatId);
            }
        }

        return [
            'chat_id' => $chatId ?? $wizardChatId,
            'message_id' => $messageId,
        ];
    }

    private static function persistWizardMessageId(
        MaterialsDeps $deps,
        int $telegramId,
        int $messageId,
        int $chatId,
    ): void {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
            return;
        }

        $ctx = $state['context'];
        $ctx['wizard_message_id'] = $messageId;
        $ctx['admin_chat_id'] = $chatId;
        $ctx['part_picker_message_id'] = $messageId;
        $ctx['part_picker_chat_id'] = $chatId;
        $deps->flows->set($telegramId, 'material_upload', $ctx);
    }

    public static function sendSongPicker(Nutgram $bot, MaterialsDeps $deps, int $page = 0, ?int $chatId = null): void
    {
        $page = SqlPagination::clampPage($page);
        $total = $deps->songs->countAll();
        $songs = $deps->songs->listAlphabetical(
            SqlPagination::offsetForPage($page, InlineMenuHelper::PAGE_SIZE),
            InlineMenuHelper::PAGE_SIZE,
        );
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.new_song'),
                    callback_data: 'mat:up:sn:new',
                ),
            );

        foreach ($songs as $song) {
            $id = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $label = mb_strlen($title) > 40 ? mb_substr($title, 0, 37) . '…' : $title;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'mat:up:sn:' . $id),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'mat:up:sn:pg:',
        );

        $text = $deps->texts->get('materials.ask_song');
        if ($total === 0) {
            $text .= "\n\n" . $deps->texts->get('materials.pick_song_empty');
        }

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        $targetChatId = $chatId ?? $bot->chatId() ?? $ownerTelegramId;
        self::presentUploadStep($bot, $deps, $ownerTelegramId, $text, $keyboard, $targetChatId);
    }

    public static function sendTypePicker(Nutgram $bot, MaterialsDeps $deps, ?int $telegramId = null): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('material_types.notes'),
                    callback_data: 'mat:up:tp:' . MaterialType::NOTES,
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('material_types.recording'),
                    callback_data: 'mat:up:tp:' . MaterialType::RECORDING,
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:up:back:sn');

        $ownerTelegramId = $telegramId ?? (int) ($bot->userId() ?? 0);
        self::presentUploadStep(
            $bot,
            $deps,
            $ownerTelegramId,
            $deps->texts->get('materials.ask_type'),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    /**
     * @param list<string> $selected
     */
    public static function buildRecordingPartKeyboard(MaterialsDeps $deps, array $selected): InlineKeyboardMarkup
    {
        $selectedMap = array_fill_keys($selected, true);
        $keyboard = InlineKeyboardMarkup::make();
        $row = [];

        foreach (VoicePart::CODES as $code) {
            $label = $deps->texts->get('parts.' . $code);
            if (isset($selectedMap[$code])) {
                $label = '✅ ' . $label;
            }

            $row[] = InlineKeyboardButton::make(
                $label,
                callback_data: 'mat:up:pt:t:' . $code,
            );
            if (count($row) === 2) {
                $keyboard = $keyboard->addRow(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard = $keyboard->addRow(...$row);
        }

        $doneLabel = $deps->texts->get('buttons.parts_done');
        if ($selected === []) {
            $doneLabel .= ' ⚠';
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make($doneLabel, callback_data: 'mat:up:pt:go'),
        );

        return InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:up:back:tp');
    }

    public static function sendRecordingPartPicker(Nutgram $bot, MaterialsDeps $deps, int $telegramId): void
    {
        $keyboard = self::buildRecordingPartKeyboard($deps, []);
        $message = self::presentUploadStep(
            $bot,
            $deps,
            $telegramId,
            $deps->texts->get('materials.ask_part_multi'),
            $keyboard,
            $bot->chatId() ?? $telegramId,
        );

        if ($message instanceof Message) {
            $state = $deps->flows->get($telegramId);
            if ($state !== null && ($state['flow'] ?? '') === 'material_upload') {
                $ctx = $state['context'];
                $ctx['selected_parts'] = [];
                $ctx['part_picker_message_id'] = (int) $message->message_id;
                $ctx['part_picker_chat_id'] = (int) $message->chat->id;
                $ctx['wizard_message_id'] = (int) $message->message_id;
                $deps->flows->set($telegramId, 'material_upload', $ctx);
            }
        } elseif (self::isAdminUploadWizard($deps, $telegramId)) {
            $state = $deps->flows->get($telegramId);
            if ($state !== null) {
                $ctx = $state['context'];
                $ctx['selected_parts'] = [];
                $deps->flows->set($telegramId, 'material_upload', $ctx);
            }
        }
    }

    /**
     * @param list<string> $selected
     */
    public static function refreshRecordingPartPicker(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $chatId,
        int $messageId,
        array $selected,
    ): void {
        SafeTelegram::editMessageReplyMarkup(
            $bot,
            $chatId,
            $messageId,
            self::buildRecordingPartKeyboard($deps, $selected),
        );
    }

    public static function sendReplaceConfirm(Nutgram $bot, MaterialsDeps $deps, int $songId, string $type): void
    {
        if ($type === MaterialType::NOTES) {
            self::sendNotesUploadChoice($bot, $deps, $songId);

            return;
        }

        $song = $deps->songs->findById($songId);
        if ($song === null) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.replace_yes'),
                    callback_data: 'mat:up:rp:y',
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.replace_no'),
                    callback_data: 'mat:up:rp:n',
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:up:back:tp');

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        self::presentUploadStep(
            $bot,
            $deps,
            $ownerTelegramId,
            $deps->texts->get('materials.replace_confirm_text_notes_file', ['title' => (string) $song['title']]),
            $keyboard,
            $bot->chatId() ?? $ownerTelegramId,
        );
    }

    public static function sendTextNotesUploadChoice(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $songId,
        ?int $chatId = null,
    ): void {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.text_notes_append_end'),
                    callback_data: 'mat:up:tr:end',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.text_notes_append_start'),
                    callback_data: 'mat:up:tr:begin',
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.text_notes_replace'),
                    callback_data: 'mat:up:tr:repl',
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:up:back:sn');

        $text = $deps->texts->get('materials.text_notes_upload_choice', ['title' => (string) $song['title']]);
        self::sendInteractiveMenuMessage($bot, $deps, $text, $keyboard, $chatId);
    }

    public static function sendTextNotesFileOnlyChoice(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $songId,
        ?int $chatId = null,
    ): void {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.text_notes_replace'),
                    callback_data: 'mat:up:tr:repl',
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.replace_no'),
                    callback_data: 'mat:up:rp:n',
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:up:back:sn');

        $text = $deps->texts->get('materials.text_notes_file_only', ['title' => (string) $song['title']]);
        self::sendInteractiveMenuMessage($bot, $deps, $text, $keyboard, $chatId);
    }

    public static function sendNotesUploadChoice(Nutgram $bot, MaterialsDeps $deps, int $songId, ?int $chatId = null): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.notes_add'),
                    callback_data: 'mat:up:nt:add',
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('buttons.notes_replace_all'),
                    callback_data: 'mat:up:nt:all',
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:up:back:tp');

        $text = $deps->texts->get('materials.notes_upload_choice', ['title' => (string) $song['title']]);
        self::sendInteractiveMenuMessage($bot, $deps, $text, $keyboard, $chatId);
    }

    private static function sendInteractiveMenuMessage(
        Nutgram $bot,
        MaterialsDeps $deps,
        string $text,
        InlineKeyboardMarkup $keyboard,
        ?int $chatId = null,
    ): void {
        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        self::presentUploadStep(
            $bot,
            $deps,
            $ownerTelegramId,
            $text,
            $keyboard,
            $chatId ?? $bot->chatId() ?? $ownerTelegramId,
        );
    }

    public static function handleAdminChatText(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        return self::handleFlowMessage($bot, $deps, $telegramId, $text);
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null) {
            return false;
        }

        $flow = (string) ($state['flow'] ?? '');
        if ($flow === 'material_rename') {
            return self::handleRenameTitle($bot, $deps, $telegramId, $text, $state['context'] ?? []);
        }

        if ($flow === 'material_upload' && ($state['context']['step'] ?? '') === 'new_song_title') {
            return self::handleNewSongTitle($bot, $deps, $telegramId, $text);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function handleRenameTitle(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
        array $ctx,
    ): bool {
        $songId = (int) ($ctx['song_id'] ?? 0);
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $deps->flows->clear($telegramId);
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return true;
        }

        $newTitle = trim($text);
        if (mb_strlen($newTitle) < 2 || mb_strlen($newTitle) > 255) {
            $bot->sendMessage($deps->texts->get('materials.invalid_song_title'));

            return true;
        }

        $oldTitle = (string) $song['title'];
        $result = $deps->songs->rename($songId, $newTitle);
        if ($result === 'duplicate') {
            $bot->sendMessage($deps->texts->get('materials.rename_duplicate'));

            return true;
        }

        $deps->flows->clear($telegramId);
        $bot->sendMessage($deps->texts->get('materials.rename_success', [
            'old' => $oldTitle,
            'new' => trim($newTitle),
        ]));

        return true;
    }

    private static function handleNewSongTitle(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
            return false;
        }

        $ctx = $state['context'];

        if (mb_strlen($text) < 2 || mb_strlen($text) > 255) {
            $bot->sendMessage($deps->texts->get('materials.invalid_song_title'));

            return true;
        }

        $songId = $deps->songs->create($text);
        $ctx['song_id'] = $songId;
        unset($ctx['awaiting_title']);

        if (($ctx['source'] ?? '') === 'text_translit') {
            $ctx['type'] = MaterialType::TRANSLIT;
            $ctx['step'] = 'save';
            $deps->flows->set($telegramId, 'material_upload', $ctx);
            $user = $deps->users->findByTelegramId($telegramId);
            if ($user !== null) {
                self::completeUpload($bot, $deps, $user);
            }

            return true;
        }

        $ctx['step'] = 'type';
        $deps->flows->set($telegramId, 'material_upload', $ctx);
        self::sendTypePicker($bot, $deps);

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function isTextTranslitUpload(array $context): bool
    {
        return ($context['source'] ?? '') === 'text_translit'
            && isset($context['text_content'])
            && trim((string) $context['text_content']) !== '';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function completeUpload(
        Nutgram $bot,
        MaterialsDeps $deps,
        array $user,
        bool $replaceConfirmed = false,
    ): void {
        $telegramId = (int) $user['telegram_id'];
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
            return;
        }

        $ctx = $state['context'];
        if (!empty($ctx['material_saved'])) {
            return;
        }
        if (!empty($ctx['save_in_progress'])) {
            return;
        }
        if (($ctx['step'] ?? '') === 'save') {
            $ctx['save_in_progress'] = true;
            $deps->flows->set($telegramId, 'material_upload', $ctx);
        }

        $songId = (int) ($ctx['song_id'] ?? 0);
        $type = (string) ($ctx['type'] ?? '');
        $voiceParts = null;
        if ($type === MaterialType::RECORDING) {
            $voiceParts = $deps->service->normalizeVoiceParts($ctx['selected_parts'] ?? []);
        }

        try {
            if (self::isTextTranslitUpload($ctx)) {
                $textNotesMode = isset($ctx['text_notes_mode']) ? (string) $ctx['text_notes_mode'] : null;
                if ($textNotesMode === null && $replaceConfirmed) {
                    $textNotesMode = 'replace';
                }
                $result = $deps->service->saveTextUpload(
                    $user,
                    $songId,
                    MaterialType::TRANSLIT,
                    (string) $ctx['text_content'],
                    isset($ctx['admin_chat_message_id']) ? (int) $ctx['admin_chat_message_id'] : null,
                    $replaceConfirmed,
                    $textNotesMode,
                );
            } else {
                $notesMode = isset($ctx['notes_mode']) ? (string) $ctx['notes_mode'] : null;
                $result = $deps->service->saveUpload(
                    $user,
                    $songId,
                    $type,
                    $voiceParts,
                    (string) ($ctx['file_id'] ?? ''),
                    (string) ($ctx['file_unique_id'] ?? ''),
                    isset($ctx['admin_chat_message_id']) ? (int) $ctx['admin_chat_message_id'] : null,
                    $replaceConfirmed,
                    $notesMode,
                );
            }
        } catch (\InvalidArgumentException $e) {
            $ctx['save_in_progress'] = false;
            if ($e->getMessage() === 'replace_confirm_required') {
                $ctx['step'] = 'replace_confirm';
                $deps->flows->set($telegramId, 'material_upload', $ctx);
                self::sendReplaceConfirm($bot, $deps, $songId, $type);

                return;
            }

            if ($e->getMessage() === 'notes_choice_required') {
                $ctx['step'] = 'notes_choice';
                $deps->flows->set($telegramId, 'material_upload', $ctx);
                $adminChatId = (int) ($ctx['admin_chat_id'] ?? 0);
                self::sendNotesUploadChoice(
                    $bot,
                    $deps,
                    $songId,
                    $adminChatId !== 0 ? $adminChatId : null,
                );

                return;
            }

            if ($e->getMessage() === 'text_notes_choice_required') {
                $ctx['step'] = 'text_notes_choice';
                $deps->flows->set($telegramId, 'material_upload', $ctx);
                $adminChatId = (int) ($ctx['admin_chat_id'] ?? 0);
                self::sendTextNotesUploadChoice(
                    $bot,
                    $deps,
                    $songId,
                    $adminChatId !== 0 ? $adminChatId : null,
                );

                return;
            }

            if ($e->getMessage() === 'text_notes_file_only') {
                $ctx['step'] = 'text_notes_file_only';
                $deps->flows->set($telegramId, 'material_upload', $ctx);
                $adminChatId = (int) ($ctx['admin_chat_id'] ?? 0);
                self::sendTextNotesFileOnlyChoice(
                    $bot,
                    $deps,
                    $songId,
                    $adminChatId !== 0 ? $adminChatId : null,
                );

                return;
            }

            SafeTelegram::sendMessage($bot, $deps->texts->get('errors.generic'));
            $deps->flows->clear($telegramId);

            return;
        }

        $chatId = (int) ($ctx['admin_chat_id'] ?? 0);
        $partList = is_array($result['voice_parts'] ?? null) ? $result['voice_parts'] : [];
        $lines = [
            self::isTextTranslitUpload($ctx)
                ? $deps->service->textNotesSavedMessage($result)
                : $deps->service->uploadSuccessText($result['song'], $type, $partList),
        ];
        if ($result['archived_previous'] !== null) {
            $lines[] = $deps->texts->get('materials.archived_notice');
        }

        $text = implode("\n", $lines);
        $wizardMessageId = (int) ($ctx['wizard_message_id'] ?? 0);
        $deps->flows->clear($telegramId);

        if ($chatId !== 0 && $wizardMessageId !== 0) {
            SafeTelegram::editMessageText(
                $bot,
                $chatId,
                $wizardMessageId,
                $text,
                InlineKeyboardMarkup::make(),
            );

            return;
        }

        if ($chatId !== 0) {
            SafeTelegram::sendMessage($bot, $text, $chatId);
        } else {
            SafeTelegram::sendMessage($bot, $text);
        }
    }
}
