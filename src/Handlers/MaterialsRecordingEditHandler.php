<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Services\AccessControl;
use App\Services\RecordingSourceUrl;
use App\Services\SongRecordingAvailability;
use App\Support\SqlPagination;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class MaterialsRecordingEditHandler
{
    public const FLOW = 'material_recording_edit';

    public const ADMIN_SONG_PAGE_PREFIX = 'mat:ac:ed:pg:';

    public const ADMIN_SONG_PICK_PREFIX = 'mat:ac:ed:sn:';

    public static function availability(MaterialsDeps $deps): SongRecordingAvailability
    {
        return new SongRecordingAvailability($deps->materials, $deps->texts);
    }

    public static function openList(Nutgram $bot, MaterialsDeps $deps, int $telegramId, int $songId, ?int $chatId = null): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $recordings = $deps->materials->listCurrentRecordingsAllParts($songId);
        if ($recordings === []) {
            $text = $deps->texts->get('materials.edit_recordings_empty');
            $keyboard = InlineMenuHelper::appendFooter(
                InlineKeyboardMarkup::make(),
                $deps->texts,
                self::listFooterBackCallback($deps, $telegramId, $songId),
            );
            self::presentStep($bot, $deps, $telegramId, $songId, null, $text, $keyboard, $chatId);

            return;
        }

        $lines = [
            $deps->texts->get('materials.edit_recordings_list_header', ['title' => (string) $song['title']]),
        ];
        $keyboard = InlineKeyboardMarkup::make();
        $ordered = self::orderRecordingsNewestFirst($recordings);
        $total = count($ordered);
        foreach ($ordered as $index => $row) {
            $id = (int) $row['id'];
            $ordinal = $total - $index;
            $partsLabel = $deps->service->formatRecordingPartsLabel(
                is_array($row['voice_parts'] ?? null) ? $row['voice_parts'] : [],
            );
            $lines[] = $ordinal . '. ' . $partsLabel;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $partsLabel . ' #' . $ordinal,
                    callback_data: 'mat:mg:erec:p:' . $id,
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            self::listFooterBackCallback($deps, $telegramId, $songId),
        );
        self::presentStep(
            $bot,
            $deps,
            $telegramId,
            $songId,
            null,
            implode("\n", $lines),
            $keyboard,
            $chatId,
        );
    }

    public static function startAdminChat(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $adminChatId,
    ): void {
        $deps->flows->set($telegramId, self::FLOW, [
            'step' => 'pick_song',
            'admin_chat_edit' => true,
            'admin_chat_id' => $adminChatId,
            'menu_owner_telegram_id' => $telegramId,
            'search_query' => '',
            'song_page' => 0,
            'wizard_chat_id' => 0,
            'wizard_message_id' => 0,
        ]);
        self::sendAdminSongPicker($bot, $deps, $telegramId, $adminChatId, 0, '');
    }

    public static function sendAdminSongPicker(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $adminChatId,
        int $page,
        string $query,
    ): void {
        $page = SqlPagination::clampPage($page);
        $query = trim($query);
        $keyboard = InlineKeyboardMarkup::make();
        $total = 0;

        if ($query !== '') {
            $all = $deps->songs->searchByTitleFragment($query, 80);
            $total = count($all);
            $text = $deps->texts->get('materials.admin_edit_pick_song_search', ['query' => $query]);
            if ($total === 0) {
                $text .= "\n\n" . $deps->texts->get('songs.not_found');
            } else {
                foreach (InlineMenuHelper::pageSlice($all, $page) as $song) {
                    $id = (int) $song['id'];
                    $title = (string) ($song['title'] ?? '');
                    $label = mb_strlen($title) > 40 ? mb_substr($title, 0, 37) . '…' : $title;
                    $keyboard = $keyboard->addRow(
                        InlineKeyboardButton::make($label, callback_data: self::ADMIN_SONG_PICK_PREFIX . $id),
                    );
                }
            }
        } else {
            $total = $deps->songs->countAll();
            $songs = $deps->songs->listAlphabetical(
                SqlPagination::offsetForPage($page, InlineMenuHelper::PAGE_SIZE),
                InlineMenuHelper::PAGE_SIZE,
            );
            $text = $deps->texts->get('materials.admin_edit_pick_song');
            $text .= "\n\n" . $deps->texts->get('materials.admin_edit_search_hint');
            if ($total === 0) {
                $text .= "\n\n" . $deps->texts->get('materials.pick_song_empty');
            }

            foreach ($songs as $song) {
                $id = (int) $song['id'];
                $title = (string) ($song['title'] ?? '');
                $label = mb_strlen($title) > 40 ? mb_substr($title, 0, 37) . '…' : $title;
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make($label, callback_data: self::ADMIN_SONG_PICK_PREFIX . $id),
                );
            }
        }

        if ($total > 0) {
            $keyboard = InlineMenuHelper::appendPaginationAndFooter(
                $keyboard,
                $deps->texts,
                $page,
                $total,
                self::ADMIN_SONG_PAGE_PREFIX,
            );
        } else {
            $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts);
        }

        $state = $deps->flows->get($telegramId);
        $ctx = $state !== null && ($state['flow'] ?? '') === self::FLOW ? $state['context'] : [];
        $ctx['step'] = 'pick_song';
        $ctx['admin_chat_edit'] = true;
        $ctx['admin_chat_id'] = $adminChatId;
        $ctx['menu_owner_telegram_id'] = $telegramId;
        $ctx['search_query'] = $query;
        $ctx['song_page'] = $page;
        $deps->flows->set($telegramId, self::FLOW, $ctx);

        self::presentStep($bot, $deps, $telegramId, 0, null, $text, $keyboard, $adminChatId);
    }

    public static function handleAdminChatSearchText(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $adminChatId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return false;
        }

        $ctx = $state['context'];
        if (!($ctx['admin_chat_edit'] ?? false) || ($ctx['step'] ?? '') !== 'pick_song') {
            return false;
        }

        if (mb_strlen(trim($text)) < 2) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('songs.query_too_short'), $adminChatId);

            return true;
        }

        self::sendAdminSongPicker($bot, $deps, $telegramId, $adminChatId, 0, trim($text));

        return true;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public static function assertAdminChatMenuActor(
        Nutgram $bot,
        MaterialsDeps $deps,
        AccessControl $access,
        array $actor,
    ): bool {
        $chatId = $bot->chatId();
        if ($chatId === null || !$access->isAdminChatId($chatId)) {
            return true;
        }

        $telegramId = (int) ($actor['telegram_id'] ?? 0);
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('admin_chat.menu_other_user'),
                'show_alert' => true,
            ]);

            return false;
        }

        $owner = (int) ($state['context']['menu_owner_telegram_id'] ?? $telegramId);
        if ($owner !== $telegramId) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('admin_chat.menu_other_user'),
                'show_alert' => true,
            ]);

            return false;
        }

        return true;
    }

    public static function onAdminSongPicked(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $songId,
        int $adminChatId,
    ): void {
        $state = $deps->flows->get($telegramId);
        $ctx = $state !== null && ($state['flow'] ?? '') === self::FLOW ? $state['context'] : [];
        $ctx['step'] = 'pick_recording';
        $ctx['admin_chat_edit'] = true;
        $ctx['admin_chat_id'] = $adminChatId;
        $ctx['menu_owner_telegram_id'] = $telegramId;
        $ctx['song_id'] = $songId;
        $deps->flows->set($telegramId, self::FLOW, $ctx);
        self::openList($bot, $deps, $telegramId, $songId, $adminChatId);
    }

    public static function openEditor(Nutgram $bot, MaterialsDeps $deps, int $telegramId, int $materialId, ?int $chatId = null): void
    {
        $material = $deps->materials->findById($materialId);
        if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $songId = (int) $material['song_id'];
        $text = self::buildEditorText($deps, $songId, $material);
        $keyboard = self::buildEditorKeyboard($deps, $telegramId, $songId, $materialId);
        self::presentStep($bot, $deps, $telegramId, $songId, $materialId, $text, $keyboard, $chatId);
    }

    public static function beginSourceUrlEdit(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $materialId,
        ?int $chatId = null,
    ): void {
        $material = $deps->materials->findById($materialId);
        if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('errors.generic'),
                'show_alert' => true,
            ]);

            return;
        }

        $state = $deps->flows->get($telegramId);
        $ctx = $state !== null && ($state['flow'] ?? '') === self::FLOW ? $state['context'] : [];
        $songId = (int) ($material['song_id'] ?? ($ctx['song_id'] ?? 0));
        $ctx['step'] = 'await_source_url';
        $ctx['material_id'] = $materialId;
        $ctx['song_id'] = $songId;
        $deps->flows->set($telegramId, self::FLOW, $ctx);

        SafeTelegram::answerCallbackQuery($bot);
        $targetChatId = $chatId ?? $bot->chatId() ?? $telegramId;
        SafeTelegram::sendMessage($bot, $deps->texts->get('materials.recording_source_url_prompt'), $targetChatId);
    }

    public static function clearSourceUrl(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $materialId,
        ?int $chatId = null,
    ): void {
        $material = $deps->materials->findById($materialId);
        if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('errors.generic'),
                'show_alert' => true,
            ]);

            return;
        }

        $deps->materials->setSourceMessageUrl($materialId, null);
        SafeTelegram::answerCallbackQuery($bot, [
            'text' => $deps->texts->get('materials.recording_source_url_cleared'),
        ]);
        self::openEditor($bot, $deps, $telegramId, $materialId, $chatId);
    }

    public static function handleSourceUrlMessage(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
        ?int $chatId = null,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return false;
        }

        if (($state['context']['step'] ?? '') !== 'await_source_url') {
            return false;
        }

        $materialId = (int) ($state['context']['material_id'] ?? 0);
        if ($materialId <= 0) {
            return false;
        }

        $url = RecordingSourceUrl::normalize($text);
        if (!RecordingSourceUrl::isValid($url)) {
            $targetChatId = $chatId ?? $bot->chatId() ?? $telegramId;
            SafeTelegram::sendMessage($bot, $deps->texts->get('materials.recording_source_url_invalid'), $targetChatId);

            return true;
        }

        $deps->materials->setSourceMessageUrl($materialId, $url);
        $ctx = $state['context'];
        $ctx['step'] = 'pick_recording';
        unset($ctx['await_source_url']);
        $deps->flows->set($telegramId, self::FLOW, $ctx);

        $targetChatId = $chatId ?? $bot->chatId() ?? $telegramId;
        SafeTelegram::sendMessage($bot, $deps->texts->get('materials.recording_source_url_saved'), $targetChatId);
        self::openEditor($bot, $deps, $telegramId, $materialId, $chatId);

        return true;
    }

    public static function applyPartChoice(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $materialId,
        string $choice,
        ?int $chatId = null,
    ): void {
        $material = $deps->materials->findById($materialId);
        if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('errors.generic'),
                'show_alert' => true,
            ]);

            return;
        }

        $newParts = self::partsForChoice($choice);
        if ($newParts === []) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('errors.generic'),
                'show_alert' => true,
            ]);

            return;
        }

        $current = $deps->service->normalizeVoiceParts(
            is_array($material['voice_parts'] ?? null) ? $material['voice_parts'] : $deps->materials->listVoiceParts($materialId),
        );
        $songId = (int) $material['song_id'];

        if ($current === $deps->service->normalizeVoiceParts($newParts)) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('materials.recording_part_unchanged'),
            ]);
            $material = $deps->materials->findById($materialId) ?? $material;
            $text = self::buildEditorText($deps, $songId, $material, false);
            self::refreshStep($bot, $deps, $telegramId, $text, self::buildEditorKeyboard($deps, $telegramId, $songId, $materialId));

            return;
        }

        $deps->materials->setVoiceParts($materialId, $newParts);
        $material = $deps->materials->findById($materialId) ?? $material;
        $song = $deps->songs->findById($songId);
        if ($song !== null) {
            $actor = $deps->users->findByTelegramId($telegramId);
            $actorUserId = $actor !== null ? (int) $actor['id'] : 0;
            $deps->materialNotifications->afterRecordingReassigned($song, $newParts, $actorUserId);
        }
        $partLabel = $deps->service->formatRecordingPartsLabel($newParts);

        SafeTelegram::answerCallbackQuery($bot, [
            'text' => $deps->texts->get('materials.recording_part_updated', ['part' => $partLabel]),
        ]);

        $text = self::buildEditorText($deps, $songId, $material, true);
        self::refreshStep($bot, $deps, $telegramId, $text, self::buildEditorKeyboard($deps, $telegramId, $songId, $materialId));
    }

    /**
     * @param array<string, mixed> $material
     */
    private static function buildEditorText(
        MaterialsDeps $deps,
        int $songId,
        array $material,
        bool $showDone = false,
    ): string {
        $availability = self::availability($deps);
        $parts = is_array($material['voice_parts'] ?? null)
            ? $material['voice_parts']
            : $deps->materials->listVoiceParts((int) $material['id']);
        $current = $deps->service->formatRecordingPartsLabel($parts);

        $lines = [
            $deps->texts->get('materials.recording_edit_current', ['part' => $current]),
            $availability->formatHaveLine($songId),
            $availability->formatMissingLine($songId),
        ];

        if ($showDone) {
            $lines[] = $deps->texts->get('materials.recording_part_updated', ['part' => $current]);
        }

        $sourceUrl = trim((string) ($material['source_message_url'] ?? ''));
        $lines[] = $sourceUrl !== ''
            ? $deps->texts->get('materials.recording_source_line_set', ['url' => $sourceUrl])
            : $deps->texts->get('materials.recording_source_line_empty');

        return implode("\n", $lines);
    }

    private static function buildEditorKeyboard(MaterialsDeps $deps, int $telegramId, int $songId, int $materialId): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        $row = [];

        foreach (VoicePart::CODES as $code) {
            $row[] = InlineKeyboardButton::make(
                $deps->texts->get('parts.' . $code),
                callback_data: 'mat:mg:erec:s:' . $materialId . ':' . $code,
            );
            if (count($row) === 2) {
                $keyboard = $keyboard->addRow(...$row);
                $row = [];
            }
        }

        if ($row !== []) {
            $keyboard = $keyboard->addRow(...$row);
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('admin_chat.recording_tutti'),
                callback_data: 'mat:mg:erec:s:' . $materialId . ':tutti',
            ),
        );

        $sourceUrl = trim((string) ($deps->materials->findById($materialId)['source_message_url'] ?? ''));
        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('materials.recording_source_set_button'),
                callback_data: 'mat:mg:erec:src:set:' . $materialId,
            ),
        );
        if ($sourceUrl !== '') {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('materials.recording_source_clear_button'),
                    callback_data: 'mat:mg:erec:src:clr:' . $materialId,
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('materials.recording_edit_back'),
                callback_data: 'mat:mg:erec:lb:' . $songId,
            ),
        );

        return InlineMenuHelper::appendFooter(
            $keyboard,
            $deps->texts,
            self::listFooterBackCallback($deps, $telegramId, $songId),
        );
    }

    private static function listFooterBackCallback(MaterialsDeps $deps, int $telegramId, int $songId): string
    {
        if (self::isAdminChatEditFlow($deps, $telegramId)) {
            return self::ADMIN_SONG_PAGE_PREFIX . '0';
        }

        return 'mat:mg:sn:' . $songId;
    }

    private static function isAdminChatEditFlow(MaterialsDeps $deps, int $telegramId): bool
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return false;
        }

        return (bool) ($state['context']['admin_chat_edit'] ?? false);
    }

    private static function presentStep(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        int $songId,
        ?int $materialId,
        string $text,
        InlineKeyboardMarkup $keyboard,
        ?int $chatId = null,
    ): void {
        $target = self::wizardTarget($deps, $telegramId);
        $targetChatId = $chatId ?? $bot->chatId() ?? $telegramId;

        if ($target === null) {
            $message = InlineMenuHelper::sendInteractiveMenu(
                $bot,
                $deps->interactiveMenus,
                $telegramId,
                $text,
                $keyboard,
                $targetChatId,
            );
            if ($message instanceof Message) {
                self::persistWizard($deps, $telegramId, $songId, $materialId, (int) $message->chat->id, (int) $message->message_id);
            }

            return;
        }

        if ($target['message_id'] === 0) {
            $message = SafeTelegram::sendMessage($bot, $text, $target['chat_id'], $keyboard);
            if ($message !== null) {
                self::persistWizard(
                    $deps,
                    $telegramId,
                    $songId,
                    $materialId,
                    (int) $message->chat->id,
                    (int) $message->message_id,
                );
            }

            return;
        }

        SafeTelegram::editMessageText(
            $bot,
            $target['chat_id'],
            $target['message_id'],
            $text,
            $keyboard,
        );
        self::persistWizard($deps, $telegramId, $songId, $materialId, $target['chat_id'], $target['message_id']);
    }

    private static function refreshStep(
        Nutgram $bot,
        MaterialsDeps $deps,
        int $telegramId,
        string $text,
        InlineKeyboardMarkup $keyboard,
    ): void {
        $target = self::wizardTarget($deps, $telegramId);
        if ($target === null) {
            return;
        }

        SafeTelegram::editMessageText(
            $bot,
            $target['chat_id'],
            $target['message_id'],
            $text,
            $keyboard,
        );
    }

    /**
     * @return array{chat_id: int, message_id: int}|null
     */
    private static function wizardTarget(MaterialsDeps $deps, int $telegramId): ?array
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return null;
        }

        $ctx = $state['context'];
        $chatId = (int) ($ctx['wizard_chat_id'] ?? 0);
        $messageId = (int) ($ctx['wizard_message_id'] ?? 0);

        if ($chatId === 0) {
            return null;
        }

        return [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];
    }

    private static function persistWizard(
        MaterialsDeps $deps,
        int $telegramId,
        int $songId,
        ?int $materialId,
        int $chatId,
        int $messageId,
    ): void {
        $state = $deps->flows->get($telegramId);
        $ctx = $state !== null && ($state['flow'] ?? '') === self::FLOW
            ? $state['context']
            : [];
        if ($songId > 0) {
            $ctx['song_id'] = $songId;
        }
        $ctx['material_id'] = $materialId;
        $ctx['wizard_chat_id'] = $chatId;
        $ctx['wizard_message_id'] = $messageId;
        $deps->flows->set($telegramId, self::FLOW, $ctx);
    }

    /**
     * @return list<string>
     */
    private static function partsForChoice(string $choice): array
    {
        if ($choice === 'tutti') {
            return VoicePart::CODES;
        }

        if (VoicePart::isValid($choice)) {
            return [$choice];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $recordings
     * @return list<array<string, mixed>>
     */
    private static function orderRecordingsNewestFirst(array $recordings): array
    {
        usort($recordings, static function (array $a, array $b): int {
            $ta = (string) ($a['uploaded_at'] ?? '');
            $tb = (string) ($b['uploaded_at'] ?? '');
            if ($ta !== $tb) {
                return strcmp($tb, $ta);
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        return $recordings;
    }
}