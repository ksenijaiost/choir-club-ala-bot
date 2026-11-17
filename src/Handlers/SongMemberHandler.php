<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\VoicePart;
use App\Services\VoicePartRules;
use App\Support\SqlPagination;
use App\Services\SongDeliveryService;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class SongMemberHandler
{
    public static function beginSearch(Nutgram $bot, MaterialsDeps $deps, int $telegramId): void
    {
        $deps->flows->set($telegramId, 'song_search', []);
        self::sendMaterialCatalog($bot, $deps, 0);
    }

    public static function sendMaterialCatalog(Nutgram $bot, MaterialsDeps $deps, int $page): void
    {
        $built = self::buildMaterialCatalog($bot, $deps, $page);
        if ($built === null) {
            $bot->sendMessage($deps->texts->get('songs.catalog_empty'));

            return;
        }

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $built['text'],
            $built['keyboard'],
        );
    }

    public static function editMaterialCatalog(Nutgram $bot, MaterialsDeps $deps, int $page): void
    {
        $built = self::buildMaterialCatalog($bot, $deps, $page);
        if ($built === null) {
            return;
        }

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        CallbackUi::editCurrentMessage(
            $bot,
            $built['text'],
            $built['keyboard'],
            $deps->interactiveMenus,
            $ownerTelegramId,
        );
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    private static function buildMaterialCatalog(Nutgram $bot, MaterialsDeps $deps, int $page): ?array
    {
        $total = $deps->songs->countWithCurrentMaterials();
        if ($total === 0) {
            return null;
        }

        $page = SqlPagination::clampPage($page);
        $songs = $deps->songs->listWithCurrentMaterials(
            SqlPagination::offsetForPage($page, InlineMenuHelper::PAGE_SIZE),
            InlineMenuHelper::PAGE_SIZE,
        );
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

        return [
            'text' => $deps->texts->get('songs.catalog_header', [
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            'keyboard' => $keyboard,
        ];
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

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $deps->texts->get('songs.pick_from_list'),
            $keyboard,
        );
    }

    public static function sendMyRecordingsPage(
        Nutgram $bot,
        MaterialsDeps $deps,
        string $partCode,
        int $page,
    ): void {
        $built = self::buildMyRecordingsPage($deps, $partCode, $page);
        if ($built === null) {
            $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
            $textKey = $allParts ? 'songs.my_recordings_empty_all' : 'songs.my_recordings_empty';
            SafeTelegram::sendMessage($bot, $deps->texts->get($textKey));

            return;
        }

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $built['text'],
            $built['keyboard'],
        );
    }

    public static function editMyRecordingsPage(
        Nutgram $bot,
        MaterialsDeps $deps,
        string $partCode,
        int $page,
    ): void {
        $built = self::buildMyRecordingsPage($deps, $partCode, $page);
        if ($built === null) {
            return;
        }

        $ownerTelegramId = (int) ($bot->userId() ?? 0);
        CallbackUi::editCurrentMessage(
            $bot,
            $built['text'],
            $built['keyboard'],
            $deps->interactiveMenus,
            $ownerTelegramId,
        );
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    private static function buildMyRecordingsPage(MaterialsDeps $deps, string $partCode, int $page): ?array
    {
        $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
        $songs = $allParts
            ? $deps->materials->listSongsWithRecordingsAnyPart()
            : $deps->materials->listSongsWithRecordingsForPart($partCode);
        $total = count($songs);
        if ($total === 0) {
            return null;
        }

        $page = SqlPagination::clampPage($page);
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

        $partLabel = $allParts
            ? ''
            : $deps->texts->get('parts.' . $partCode);
        $headerKey = $allParts ? 'songs.my_recordings_pick_header_all' : 'songs.my_recordings_pick_header';
        $pageNum = (string) ($page + 1);
        $pages = (string) InlineMenuHelper::totalPages($total);
        $text = $allParts
            ? $deps->texts->get($headerKey, ['page' => $pageNum, 'pages' => $pages])
            : $deps->texts->get($headerKey, [
                'part' => $partLabel,
                'page' => $pageNum,
                'pages' => $pages,
            ]);

        return [
            'text' => $text,
            'keyboard' => $keyboard,
        ];
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
            $viewer = $deps->users->findByTelegramId($telegramId);
            $delivery->sendSongCard($bot, $matches[0], $viewer);

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
        if (VoicePartRules::isConductor($user)) {
            self::sendMyRecordingsPage($bot, $deps, VoicePartRules::RECORDINGS_ALL_PARTS, 0);

            return;
        }

        $partCode = (string) ($user['voice_part'] ?? '');
        if ($partCode === '' || !VoicePart::isValid($partCode)) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('songs.my_recordings_need_part'));

            return;
        }

        self::sendMyRecordingsPage($bot, $deps, $partCode, 0);
    }
}
