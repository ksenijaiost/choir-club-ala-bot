<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\VoicePart;
use App\Services\SongDeliveryService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class SongMemberHandler
{
    public static function beginSearch(Nutgram $bot, MaterialsDeps $deps, int $telegramId): void
    {
        $deps->flows->set($telegramId, 'song_search', []);
        $bot->sendMessage($deps->texts->get('songs.ask_title'));
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        MaterialsDeps $deps,
        SongDeliveryService $delivery,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'song_search') {
            return false;
        }

        $query = trim($text);
        if (mb_strlen($query) < 1) {
            return true;
        }

        $matches = $deps->songs->searchByTitleFragment($query, 12);
        $deps->flows->clear($telegramId);

        if ($matches === []) {
            $bot->sendMessage($deps->texts->get('songs.not_found'));

            return true;
        }

        if (count($matches) === 1) {
            $delivery->sendSongCard($bot, $matches[0]);

            return true;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($matches as $song) {
            $id = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $label = mb_strlen($title) > 42 ? mb_substr($title, 0, 39) . '…' : $title;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'snd:pk:' . $id),
            );
        }

        $bot->sendMessage($deps->texts->get('songs.pick_from_list'), reply_markup: $keyboard);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function openMyRecordings(
        Nutgram $bot,
        MaterialsDeps $deps,
        SongDeliveryService $delivery,
        array $user,
    ): void {
        $partCode = (string) ($user['voice_part'] ?? '');
        if ($partCode === '' || !VoicePart::isValid($partCode)) {
            $bot->sendMessage($deps->texts->get('songs.my_recordings_need_part'));

            return;
        }

        $songs = $deps->materials->listSongsWithRecordingsForPart($partCode);
        $partLabel = $deps->texts->get('parts.' . $partCode);

        if ($songs === []) {
            $bot->sendMessage($deps->texts->get('songs.my_recordings_empty', ['part' => $partLabel]));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($songs as $song) {
            $id = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $count = (int) ($song['recording_count'] ?? 0);
            $label = $title . ' (' . $count . ')';
            if (mb_strlen($label) > 48) {
                $label = mb_substr($title, 0, 35) . '… (' . $count . ')';
            }
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'snd:my:' . $id . ':' . $partCode),
            );
        }

        $bot->sendMessage(
            $deps->texts->get('songs.my_recordings_header', ['part' => $partLabel]),
            reply_markup: $keyboard,
        );
    }
}
