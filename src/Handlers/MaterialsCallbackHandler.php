<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use SergiX44\Nutgram\Nutgram;

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
            $bot->answerCallbackQuery(text: $deps->texts->get('materials.upload_forbidden'), show_alert: true);

            return true;
        }

        if (preg_match('/^mat:up:sn:(new|\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            $state = $deps->flows->get((int) $actor['telegram_id']);
            if ($state === null || ($state['flow'] ?? '') !== 'material_upload') {
                return true;
            }

            $ctx = $state['context'];
            if ($m[1] === 'new') {
                $ctx['step'] = 'new_song_title';
                $ctx['awaiting_title'] = true;
                $deps->flows->set((int) $actor['telegram_id'], 'material_upload', $ctx);
                $bot->sendMessage($deps->texts->get('materials.new_song_prompt'));

                return true;
            }

            $ctx['song_id'] = (int) $m[1];
            $ctx['step'] = 'type';
            $deps->flows->set((int) $actor['telegram_id'], 'material_upload', $ctx);
            MaterialsFlowHandler::sendTypePicker($bot, $deps);

            return true;
        }

        if (preg_match('/^mat:up:tp:(notes|translit|recording)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            $type = $m[1];
            $state = $deps->flows->get((int) $actor['telegram_id']);
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
                $bot->answerCallbackQuery();

                return true;
            }

            $ctx = $state['context'];
            $selected = $deps->service->normalizeVoiceParts($ctx['selected_parts'] ?? []);
            if ($selected === []) {
                $bot->answerCallbackQuery(text: $deps->texts->get('materials.pick_parts_none'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $ctx['selected_parts'] = $selected;
            $ctx['step'] = 'save';
            $deps->flows->set($telegramId, 'material_upload', $ctx);
            MaterialsFlowHandler::completeUpload($bot, $deps, $actor);

            return true;
        }

        if ($data === 'mat:up:rp:y') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            MaterialsFlowHandler::completeUpload($bot, $deps, $actor, true);

            return true;
        }

        if ($data === 'mat:up:rp:n') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $deps->flows->clear((int) $actor['telegram_id']);
            $bot->sendMessage($deps->texts->get('materials.cancelled'));

            return true;
        }

        if (preg_match('/^mat:mg:sn:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendRecordingPartPicker($bot, $deps, (int) $m[1]);

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
            if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
                $bot->answerCallbackQuery(text: $deps->texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $deps->materials->archiveById($id);
            $bot->answerCallbackQuery(text: $deps->texts->get('materials.recording_deleted'));
            CallbackUi::clearKeyboard($bot);

            return true;
        }

        if (preg_match('/^mat:mg:rp:(\d+)$/', $data, $m) === 1) {
            $id = (int) $m[1];
            $material = $deps->materials->findById($id);
            if ($material === null || ($material['type'] ?? '') !== MaterialType::RECORDING) {
                $bot->answerCallbackQuery(text: $deps->texts->get('errors.generic'), show_alert: true);

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $deps->flows->set((int) $actor['telegram_id'], 'material_replace_recording', [
                'material_id' => $id,
            ]);
            $bot->sendMessage($deps->texts->get('materials.replace_upload_hint'));

            return true;
        }

        return false;
    }

    public static function openManagementHome(Nutgram $bot, MaterialsDeps $deps): void
    {
        $songs = $deps->songs->listRecent(25);
        if ($songs === []) {
            $bot->sendMessage($deps->texts->get('materials.pick_song_empty'));

            return;
        }

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

        $bot->sendMessage($deps->texts->get('materials.manage_pick_song'), reply_markup: $keyboard);
    }

    private static function sendRecordingPartPicker(Nutgram $bot, MaterialsDeps $deps, int $songId): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $bot->sendMessage($deps->texts->get('errors.generic'));

            return;
        }

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
        foreach ($deps->materials->listPartsWithRecordingsForSong($songId) as $code) {
            $count = $deps->materials->countRecordingsForPart($songId, $code);
            $keyboard = $keyboard->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    $deps->texts->get('parts.' . $code) . ' (' . $count . ')',
                    callback_data: 'mat:mg:rec:' . $songId . ':' . $code,
                ),
            );
        }

        $bot->sendMessage(
            $deps->texts->get('materials.manage_pick_part', ['title' => (string) $song['title']]),
            reply_markup: $keyboard,
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

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }
}
