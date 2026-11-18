<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Services\SongRecordingAvailability;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class MaterialsRecordingEditHandler
{
    public const FLOW = 'material_recording_edit';

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
                'mat:mg:sn:' . $songId,
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

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:mg:sn:' . $songId);
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

    public static function openEditor(Nutgram $bot, MaterialsDeps $deps, int $telegramId, int $materialId, ?int $chatId = null): void
    {
        $material = $deps->materials->findById($materialId);
        if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $songId = (int) $material['song_id'];
        $text = self::buildEditorText($deps, $songId, $material);
        $keyboard = self::buildEditorKeyboard($deps, $songId, $materialId);
        self::presentStep($bot, $deps, $telegramId, $songId, $materialId, $text, $keyboard, $chatId);
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
            self::refreshStep($bot, $deps, $telegramId, $text, self::buildEditorKeyboard($deps, $songId, $materialId));

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
        self::refreshStep($bot, $deps, $telegramId, $text, self::buildEditorKeyboard($deps, $songId, $materialId));
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

        return implode("\n", $lines);
    }

    private static function buildEditorKeyboard(MaterialsDeps $deps, int $songId, int $materialId): InlineKeyboardMarkup
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

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $deps->texts->get('materials.recording_edit_back'),
                callback_data: 'mat:mg:erec:lb:' . $songId,
            ),
        );

        return InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'mat:mg:sn:' . $songId);
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
        $deps->flows->set($telegramId, self::FLOW, [
            'song_id' => $songId,
            'material_id' => $materialId,
            'wizard_chat_id' => $chatId,
            'wizard_message_id' => $messageId,
        ]);
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