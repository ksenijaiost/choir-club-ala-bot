<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\VoicePart;
use App\Services\SongDeliveryService;
use App\Telegram\InlineMenuHelper;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class SongMemberHandler
{
    public static function beginSearch(Nutgram $bot, MaterialsDeps $deps, int $telegramId): void
    {
        $deps->flows->set($telegramId, 'song_search', []);
        self::sendMaterialCatalog($bot, $deps, 0);
        $bot->sendMessage($deps->texts->get('songs.ask_title'));
    }

    public static function sendMaterialCatalog(Nutgram $bot, MaterialsDeps $deps, int $page): void
    {
        $total = $deps->songs->countWithCurrentMaterials();
        if ($total === 0) {
            $bot->sendMessage($deps->texts->get('songs.catalog_empty'));

            return;
        }

        $offset = $page * InlineMenuHelper::PAGE_SIZE;
        $songs = $deps->songs->listWithCurrentMaterials($offset, InlineMenuHelper::PAGE_SIZE);
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($songs as $song) {
            $id = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $label = mb_strlen($title) > 42 ? mb_substr($title, 0, 39) . '…' : $title;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'snd:pk:' . $id),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'snd:cat:pg:',
        );

        $bot->sendMessage(
            $deps->texts->get('songs.catalog_header', [
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            reply_markup: $keyboard,
        );
    }

    public static function sendSearchResults(
        Nutgram $bot,
        MaterialsDeps $deps,
        string $query,
        int $page,
    ): void {
        $all = $deps->songs->searchByTitleFragment($query, 80);
        $total = count($all);
        if ($total === 0) {
            $bot->sendMessage($deps->texts->get('songs.not_found'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($all, $page) as $song) {
            $id = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $label = mb_strlen($title) > 42 ? mb_substr($title, 0, 39) . '…' : $title;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'snd:pk:' . $id),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'snd:sr:pg:',
        );

        $bot->sendMessage($deps->texts->get('songs.pick_from_list'), reply_markup: $keyboard);
    }

    public static function sendMyRecordingsPage(
        Nutgram $bot,
        MaterialsDeps $deps,
        string $partCode,
        int $page,
    ): void {
        $songs = $deps->materials->listSongsWithRecordingsForPart($partCode);
        $partLabel = $deps->texts->get('parts.' . $partCode);
        $total = count($songs);

        if ($total === 0) {
            $bot->sendMessage($deps->texts->get('songs.my_recordings_empty', ['part' => $partLabel]));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($songs, $page) as $song) {
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

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'snd:my:pg:' . $partCode . ':',
        );

        $bot->sendMessage(
            $deps->texts->get('songs.my_recordings_header', ['part' => $partLabel]),
            reply_markup: $keyboard,
        );
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

        $matches = $deps->songs->searchByTitleFragment($query, 80);

        if ($matches === []) {
            $deps->flows->clear($telegramId);
            $bot->sendMessage($deps->texts->get('songs.not_found'));

            return true;
        }

        if (count($matches) === 1) {
            $deps->flows->clear($telegramId);
            $delivery->sendSongCard($bot, $matches[0]);

            return true;
        }

        $deps->flows->set($telegramId, 'song_search', ['query' => $query]);
        self::sendSearchResults($bot, $deps, $query, 0);

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

        self::sendMyRecordingsPage($bot, $deps, $partCode, 0);
    }
}
