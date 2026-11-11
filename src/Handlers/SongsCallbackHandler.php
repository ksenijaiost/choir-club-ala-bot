<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Services\SongDeliveryService;
use App\Telegram\CallbackUi;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class SongsCallbackHandler
{
    public static function handle(
        Nutgram $bot,
        MaterialsDeps $deps,
        SongDeliveryService $delivery,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'snd:')) {
            return false;
        }

        if (preg_match('/^snd:pk:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $song = $deps->songs->findById((int) $m[1]);
            if ($song === null) {
                $bot->sendMessage($deps->texts->get('songs.not_found'));

                return true;
            }

            $delivery->sendSongCard($bot, $song);

            return true;
        }

        if (preg_match('/^snd:nt:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $delivery->sendSingletonFile($bot, (int) $m[1], MaterialType::NOTES);

            return true;
        }

        if (preg_match('/^snd:tr:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $delivery->sendSingletonFile($bot, (int) $m[1], MaterialType::TRANSLIT);

            return true;
        }

        if (preg_match('/^snd:pr:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            self::sendPartPicker($bot, $deps, (int) $m[1]);

            return true;
        }

        if (preg_match('/^snd:rp:(\d+):([a-z0-9_]+)$/', $data, $m) === 1) {
            $part = $m[2];
            if (!VoicePart::isValid($part)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $rows = $deps->materials->listCurrentRecordings((int) $m[1], $part);
            $delivery->sendRecordingFiles($bot, $rows);

            return true;
        }

        if (preg_match('/^snd:my:(\d+):([a-z0-9_]+)$/', $data, $m) === 1) {
            $part = $m[2];
            if (!VoicePart::isValid($part)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $rows = $deps->materials->listCurrentRecordings((int) $m[1], $part);
            $delivery->sendRecordingFiles($bot, $rows);

            return true;
        }

        return false;
    }

    private static function sendPartPicker(Nutgram $bot, MaterialsDeps $deps, int $songId): void
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            $bot->sendMessage($deps->texts->get('songs.not_found'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $hasRows = false;
        foreach (VoicePart::CODES as $code) {
            $count = count($deps->materials->listCurrentRecordings($songId, $code));
            if ($count === 0) {
                continue;
            }

            $hasRows = true;
            $label = $deps->texts->get('parts.' . $code) . ' (' . $count . ')';
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'snd:rp:' . $songId . ':' . $code),
            );
        }

        if (!$hasRows) {
            $bot->sendMessage($deps->texts->get('songs.no_material'));

            return;
        }

        $bot->sendMessage(
            $deps->texts->get('songs.pick_part_for_recordings', ['title' => (string) $song['title']]),
            reply_markup: $keyboard,
        );
    }
}
