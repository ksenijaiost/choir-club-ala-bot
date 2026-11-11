<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class MaterialsFlowHandler
{
    public static function sendSongPicker(Nutgram $bot, MaterialsDeps $deps, ?int $chatId = null): void
    {
        $songs = $deps->songs->listRecent(20);
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

        $text = $deps->texts->get('materials.ask_song');
        if ($songs === []) {
            $text .= "\n\n" . $deps->texts->get('materials.pick_song_empty');
        }

        if ($chatId !== null) {
            $bot->sendMessage(chat_id: $chatId, text: $text, reply_markup: $keyboard);

            return;
        }

        $bot->sendMessage($text, reply_markup: $keyboard);
    }

    public static function sendTypePicker(Nutgram $bot, MaterialsDeps $deps): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('material_types.notes'),
                    callback_data: 'mat:up:tp:' . MaterialType::NOTES,
                ),
                InlineKeyboardButton::make(
                    $deps->texts->get('material_types.translit'),
                    callback_data: 'mat:up:tp:' . MaterialType::TRANSLIT,
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $deps->texts->get('material_types.recording'),
                    callback_data: 'mat:up:tp:' . MaterialType::RECORDING,
                ),
            );

        $bot->sendMessage($deps->texts->get('materials.ask_type'), reply_markup: $keyboard);
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

        return $keyboard->addRow(
            InlineKeyboardButton::make($doneLabel, callback_data: 'mat:up:pt:go'),
        );
    }

    public static function sendRecordingPartPicker(Nutgram $bot, MaterialsDeps $deps, int $telegramId): void
    {
        $message = $bot->sendMessage(
            $deps->texts->get('materials.ask_part_multi'),
            reply_markup: self::buildRecordingPartKeyboard($deps, []),
        );

        if ($message instanceof Message) {
            $state = $deps->flows->get($telegramId);
            if ($state !== null && ($state['flow'] ?? '') === 'material_upload') {
                $ctx = $state['context'];
                $ctx['selected_parts'] = [];
                $ctx['part_picker_message_id'] = (int) $message->message_id;
                $ctx['part_picker_chat_id'] = (int) $message->chat->id;
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
        try {
            $bot->editMessageReplyMarkup(
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: self::buildRecordingPartKeyboard($deps, $selected),
            );
        } catch (\Throwable) {
        }
    }

    public static function sendReplaceConfirm(Nutgram $bot, MaterialsDeps $deps, int $songId, string $type): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            return;
        }

        $key = $type === MaterialType::NOTES ? 'materials.replace_confirm_notes' : 'materials.replace_confirm_translit';
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

        $bot->sendMessage(
            $deps->texts->get($key, ['title' => (string) $song['title']]),
            reply_markup: $keyboard,
        );
    }

    public static function handleAdminChatText(
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
        if (($ctx['step'] ?? '') !== 'new_song_title') {
            return false;
        }

        if (mb_strlen($text) < 2 || mb_strlen($text) > 255) {
            $bot->sendMessage($deps->texts->get('materials.invalid_song_title'));

            return true;
        }

        $songId = $deps->songs->create($text);
        $ctx['song_id'] = $songId;
        $ctx['step'] = 'type';
        unset($ctx['awaiting_title']);
        $deps->flows->set($telegramId, 'material_upload', $ctx);
        self::sendTypePicker($bot, $deps);

        return true;
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
        $songId = (int) ($ctx['song_id'] ?? 0);
        $type = (string) ($ctx['type'] ?? '');
        $voiceParts = null;
        if ($type === MaterialType::RECORDING) {
            $voiceParts = $deps->service->normalizeVoiceParts($ctx['selected_parts'] ?? []);
        }

        try {
            $result = $deps->service->saveUpload(
                $user,
                $songId,
                $type,
                $voiceParts,
                (string) $ctx['file_id'],
                (string) $ctx['file_unique_id'],
                isset($ctx['admin_chat_message_id']) ? (int) $ctx['admin_chat_message_id'] : null,
                $replaceConfirmed,
            );
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'replace_confirm_required') {
                $ctx['step'] = 'replace_confirm';
                $deps->flows->set($telegramId, 'material_upload', $ctx);
                self::sendReplaceConfirm($bot, $deps, $songId, $type);

                return;
            }

            $bot->sendMessage($deps->texts->get('errors.generic'));
            $deps->flows->clear($telegramId);

            return;
        }

        $deps->flows->clear($telegramId);

        $chatId = (int) ($ctx['admin_chat_id'] ?? 0);
        $partList = is_array($result['voice_parts'] ?? null) ? $result['voice_parts'] : [];
        $lines = [
            $deps->service->uploadSuccessText($result['song'], $type, $partList),
        ];
        if ($result['archived_previous'] !== null) {
            $lines[] = $deps->texts->get('materials.archived_notice');
        }

        $text = implode("\n", $lines);
        if ($chatId !== 0) {
            $bot->sendMessage(chat_id: $chatId, text: $text);
        } else {
            $bot->sendMessage($text);
        }
    }
}
