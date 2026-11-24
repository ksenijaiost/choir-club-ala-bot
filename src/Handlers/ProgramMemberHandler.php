<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\VoicePart;
use App\Services\VoicePartRules;
use App\Support\SqlPagination;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use App\Telegram\TelegramHtml;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ProgramMemberHandler
{
    /**
     * @param array<string, mixed> $user
     */
    public static function openCatalog(
        Nutgram $bot,
        ProgramDeps $programs,
        array $user,
    ): void {
        $today = $programs->delivery->todayIso();
        if ($programs->programs->countActiveForMembers($today) === 0) {
            SafeTelegram::sendMessage($bot, $programs->texts->get('programs.member_list_empty'));

            return;
        }

        $ownerTelegramId = (int) ($user['telegram_id'] ?? $bot->userId() ?? 0);
        $built = self::buildProgramListPage($programs, 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $programs->interactiveMenus,
            $ownerTelegramId,
            $built['text'],
            $built['keyboard'],
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function openHub(
        Nutgram $bot,
        ProgramDeps $programs,
        MaterialsDeps $materials,
        array $user,
    ): void {
        $built = self::buildHub($programs, $materials, $user);
        if ($built === null) {
            SafeTelegram::sendMessage($bot, $materials->texts->get('songs.my_recordings_need_part'));

            return;
        }

        $ownerTelegramId = (int) ($user['telegram_id'] ?? $bot->userId() ?? 0);
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $programs->interactiveMenus,
            $ownerTelegramId,
            $built['text'],
            $built['keyboard'],
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function editHub(
        Nutgram $bot,
        ProgramDeps $programs,
        MaterialsDeps $materials,
        array $user,
        int $actorTelegramId,
    ): void {
        $built = self::buildHub($programs, $materials, $user);
        if ($built === null) {
            return;
        }

        CallbackUi::editCurrentMessage(
            $bot,
            $built['text'],
            $built['keyboard'],
            $programs->interactiveMenus,
            $actorTelegramId,
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    private static function buildHub(
        ProgramDeps $programs,
        MaterialsDeps $materials,
        array $user,
    ): ?array {
        $partCode = self::resolvePartCode($user);
        if ($partCode === null) {
            return null;
        }

        $recordingCount = self::recordingCountForUser($materials, $partCode);

        $keyboard = InlineKeyboardMarkup::make();
        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $programs->texts->get('programs.hub_pick_button'),
                callback_data: 'prg:mr:pk',
            ),
        );
        if ($recordingCount > 1) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $programs->texts->get('programs.hub_all_button'),
                    callback_data: 'prg:mr:al',
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $programs->texts);

        return [
            'text' => $programs->texts->get('programs.hub_header'),
            'keyboard' => $keyboard,
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function handleCallback(
        Nutgram $bot,
        ProgramDeps $programs,
        MaterialsDeps $materials,
        array $user,
        string $data,
    ): bool {
        $telegramId = (int) ($user['telegram_id'] ?? 0);

        if (preg_match('/^prg:ls:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramList($bot, $programs, (int) $m[1], $telegramId);

            return true;
        }

        if (preg_match('/^prg:mr:ls:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramList($bot, $programs, (int) $m[1], $telegramId);

            return true;
        }

        if (preg_match('/^prg:in:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramCard($bot, $programs, (int) $m[1], $telegramId);

            return true;
        }

        if (preg_match('/^prg:nd:(\d+):al$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            CallbackUi::clearKeyboard($bot);
            $programId = (int) $m[1];
            $songs = self::mapSongsForDelivery(
                self::filterProgramSongsWithNotes($programs, $materials, $programId),
            );
            $materials->delivery->sendAllNotesForProgramSongs($bot, $songs);

            return true;
        }

        if (preg_match('/^prg:nd:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            CallbackUi::clearKeyboard($bot);
            $materials->delivery->sendNotes($bot, (int) $m[2]);

            return true;
        }

        if (preg_match('/^prg:nd:(\d+):pg:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramNotes($bot, $programs, $materials, (int) $m[1], (int) $m[2], $telegramId);

            return true;
        }

        if (preg_match('/^prg:nd:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramNotes($bot, $programs, $materials, (int) $m[1], 0, $telegramId);

            return true;
        }

        if (preg_match('/^prg:pk:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            CallbackUi::clearKeyboard($bot);
            $programId = (int) $m[1];
            $songId = (int) $m[2];
            $song = $programs->songs->findById($songId);
            if ($song === null) {
                SafeTelegram::sendMessage($bot, $programs->texts->get('songs.not_found'));

                return true;
            }
            $materials->delivery->sendSongCard($bot, $song, $user, 'prg:pk:' . $programId);

            return true;
        }

        if (preg_match('/^prg:pk:(\d+):pg:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramPick($bot, $programs, (int) $m[1], (int) $m[2], $telegramId);

            return true;
        }

        if (preg_match('/^prg:pk:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editProgramPick($bot, $programs, (int) $m[1], 0, $telegramId);

            return true;
        }

        if (str_starts_with($data, 'prg:mo:')) {
            $partCode = self::resolvePartCode($user);
            if ($partCode === null) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $materials->texts->get('songs.my_recordings_need_part'),
                    'show_alert' => true,
                ]);

                return true;
            }

            if (preg_match('/^prg:mo:(\d+):al$/', $data, $m) === 1) {
                SafeTelegram::answerCallbackQuery($bot);
                CallbackUi::clearKeyboard($bot);
                $programId = (int) $m[1];
                $filtered = self::filterProgramSongsWithRecordings($programs, $materials, $programId, $partCode);
                $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
                $materials->delivery->sendAllMyRecordingsForSongs(
                    $bot,
                    self::mapSongsForDelivery($filtered),
                    $partCode,
                    $allParts,
                );

                return true;
            }

            if (preg_match('/^prg:mo:(\d+)$/', $data, $m) === 1) {
                SafeTelegram::answerCallbackQuery($bot);
                self::editProgramSongs($bot, $programs, $materials, (int) $m[1], $partCode, 0, $telegramId);

                return true;
            }

            if (preg_match('/^prg:mo:(\d+):pg:(\d+)$/', $data, $m) === 1) {
                SafeTelegram::answerCallbackQuery($bot);
                self::editProgramSongs($bot, $programs, $materials, (int) $m[1], $partCode, (int) $m[2], $telegramId);

                return true;
            }

            return false;
        }

        if (!str_starts_with($data, 'prg:mr:')) {
            return false;
        }

        $partCode = self::resolvePartCode($user);
        if ($partCode === null) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $materials->texts->get('songs.my_recordings_need_part'),
                'show_alert' => true,
            ]);

            return true;
        }

        if ($data === 'prg:mr:hub') {
            SafeTelegram::answerCallbackQuery($bot);
            self::editHub($bot, $programs, $materials, $user, $telegramId);

            return true;
        }

        if ($data === 'prg:mr:pk') {
            SafeTelegram::answerCallbackQuery($bot);
            SongMemberHandler::editMyRecordingsPage($bot, $materials, $partCode, 0);

            return true;
        }

        if ($data === 'prg:mr:al') {
            SafeTelegram::answerCallbackQuery($bot);
            CallbackUi::clearKeyboard($bot);
            $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
            $songs = $allParts
                ? $materials->materials->listSongsWithRecordingsAnyPart()
                : $materials->materials->listSongsWithRecordingsForPart($partCode);
            $materials->delivery->sendAllMyRecordingsForSongs($bot, $songs, $partCode, $allParts);

            return true;
        }

        return false;
    }

    public static function editProgramList(
        Nutgram $bot,
        ProgramDeps $programs,
        int $page,
        int $actorTelegramId,
    ): void {
        $today = $programs->delivery->todayIso();
        $rows = $programs->programs->listActiveForMembers($today);
        $total = count($rows);

        if ($total === 0) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.member_list_empty'),
                InlineMenuHelper::appendFooter(
                    InlineKeyboardMarkup::make(),
                    $programs->texts,
                ),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $page = SqlPagination::clampPage($page);
        $built = self::buildProgramListPage($programs, $page);

        CallbackUi::editCurrentMessage(
            $bot,
            $built['text'],
            $built['keyboard'],
            $programs->interactiveMenus,
            $actorTelegramId,
        );
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}
     */
    private static function buildProgramListPage(ProgramDeps $programs, int $page): array
    {
        $today = $programs->delivery->todayIso();
        $rows = $programs->programs->listActiveForMembers($today);
        $total = count($rows);
        $page = SqlPagination::clampPage($page);

        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($rows, $page) as $row) {
            $id = (int) $row['id'];
            $title = (string) ($row['title'] ?? '');
            $date = $programs->programMessages->listButtonDate($row);
            $label = $title . ' · ' . $date;
            if (mb_strlen($label) > 48) {
                $label = mb_substr($title, 0, 30) . '… · ' . $date;
            }
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'prg:in:' . $id),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $programs->texts,
            $page,
            $total,
            'prg:ls:',
        );

        return [
            'text' => $programs->texts->get('programs.member_list_header', [
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            'keyboard' => $keyboard,
        ];
    }

    public static function editProgramCard(
        Nutgram $bot,
        ProgramDeps $programs,
        int $programId,
        int $actorTelegramId,
    ): void {
        $program = $programs->programs->find($programId);
        if ($program === null) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.not_found'),
                InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $programs->texts, 'prg:ls:0'),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $programs->texts->get('programs.member_card_button_notes'),
                    callback_data: 'prg:nd:' . $programId,
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $programs->texts->get('programs.member_card_button_recordings'),
                    callback_data: 'prg:mo:' . $programId,
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $programs->texts->get('programs.member_card_button_about'),
                    callback_data: 'prg:pk:' . $programId,
                ),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $programs->texts, 'prg:ls:0');

        CallbackUi::editCurrentMessage(
            $bot,
            $programs->programMessages->memberCardBody($program),
            $keyboard,
            $programs->interactiveMenus,
            $actorTelegramId,
            ParseMode::HTML,
        );
    }

    public static function editProgramNotes(
        Nutgram $bot,
        ProgramDeps $programs,
        MaterialsDeps $materials,
        int $programId,
        int $page,
        int $actorTelegramId,
    ): void {
        $program = $programs->programs->find($programId);
        if ($program === null) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.not_found'),
                InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $programs->texts, 'prg:ls:0'),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $songs = self::filterProgramSongsWithNotes($programs, $materials, $programId);
        $total = count($songs);

        $title = (string) ($program['title'] ?? '');
        if ($total === 0) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.member_notes_empty', [
                    'title' => TelegramHtml::escape($title),
                ]),
                InlineMenuHelper::appendFooter(
                    InlineKeyboardMarkup::make(),
                    $programs->texts,
                    'prg:in:' . $programId,
                ),
                $programs->interactiveMenus,
                $actorTelegramId,
                ParseMode::HTML,
            );

            return;
        }

        $page = SqlPagination::clampPage($page);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($songs, $page) as $song) {
            $songId = (int) $song['song_id'];
            $songTitle = (string) ($song['title'] ?? '');
            $label = mb_strlen($songTitle) > 42 ? mb_substr($songTitle, 0, 39) . '…' : $songTitle;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'prg:nd:' . $programId . ':' . $songId),
            );
        }

        $noteFileCount = self::programNoteFileCount($materials, $songs);
        if ($noteFileCount > 1) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $programs->texts->get('programs.member_notes_send_all_button'),
                    callback_data: 'prg:nd:' . $programId . ':al',
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $programs->texts,
            $page,
            $total,
            'prg:nd:' . $programId . ':pg:',
            'prg:in:' . $programId,
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $programs->texts->get('programs.member_notes_header', [
                'title' => TelegramHtml::escape($title),
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            $keyboard,
            $programs->interactiveMenus,
            $actorTelegramId,
            ParseMode::HTML,
        );
    }

    public static function editProgramPick(
        Nutgram $bot,
        ProgramDeps $programs,
        int $programId,
        int $page,
        int $actorTelegramId,
    ): void {
        $program = $programs->programs->find($programId);
        if ($program === null) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.not_found'),
                InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $programs->texts, 'prg:ls:0'),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $songs = $programs->programs->getSongs($programId);
        $total = count($songs);
        $title = (string) ($program['title'] ?? '');

        if ($total === 0) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.detail_no_songs'),
                InlineMenuHelper::appendFooter(
                    InlineKeyboardMarkup::make(),
                    $programs->texts,
                    'prg:in:' . $programId,
                ),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $page = SqlPagination::clampPage($page);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($songs, $page) as $song) {
            $songId = (int) $song['song_id'];
            $songTitle = (string) ($song['title'] ?? '');
            $label = mb_strlen($songTitle) > 42 ? mb_substr($songTitle, 0, 39) . '…' : $songTitle;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'prg:pk:' . $programId . ':' . $songId),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $programs->texts,
            $page,
            $total,
            'prg:pk:' . $programId . ':pg:',
            'prg:in:' . $programId,
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $programs->texts->get('programs.member_pick_header', [
                'title' => TelegramHtml::escape($title),
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            $keyboard,
            $programs->interactiveMenus,
            $actorTelegramId,
            ParseMode::HTML,
        );
    }

    public static function editProgramSongs(
        Nutgram $bot,
        ProgramDeps $programs,
        MaterialsDeps $materials,
        int $programId,
        string $partCode,
        int $page,
        int $actorTelegramId,
    ): void {
        $program = $programs->programs->find($programId);
        if ($program === null) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.not_found'),
                InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $programs->texts, 'prg:ls:0'),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
        $filtered = self::filterProgramSongsWithRecordings($programs, $materials, $programId, $partCode);

        $total = count($filtered);
        $back = 'prg:in:' . $programId;
        if ($total === 0) {
            CallbackUi::editCurrentMessage(
                $bot,
                $programs->texts->get('programs.member_songs_empty', [
                    'title' => (string) ($program['title'] ?? ''),
                ]),
                InlineMenuHelper::appendFooter(
                    InlineKeyboardMarkup::make(),
                    $programs->texts,
                    $back,
                ),
                $programs->interactiveMenus,
                $actorTelegramId,
            );

            return;
        }

        $page = SqlPagination::clampPage($page);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (InlineMenuHelper::pageSlice($filtered, $page) as $song) {
            $songId = (int) $song['song_id'];
            $songTitle = (string) ($song['title'] ?? '');
            $label = mb_strlen($songTitle) > 42 ? mb_substr($songTitle, 0, 39) . '…' : $songTitle;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'snd:my:' . $songId . ':' . $partCode),
            );
        }

        if (self::programRecordingCount($materials, $filtered, $partCode, $allParts) > 1) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $programs->texts->get('programs.member_send_all_button'),
                    callback_data: 'prg:mo:' . $programId . ':al',
                ),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $programs->texts,
            $page,
            $total,
            'prg:mo:' . $programId . ':pg:',
            $back,
        );

        $title = (string) ($program['title'] ?? '');
        $dateLine = $programs->programMessages->formatDateLine($program);
        CallbackUi::editCurrentMessage(
            $bot,
            $programs->texts->get('programs.member_songs_header', [
                'title' => TelegramHtml::escape($title),
                'date' => TelegramHtml::escape($dateLine),
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            $keyboard,
            $programs->interactiveMenus,
            $actorTelegramId,
            ParseMode::HTML,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function resolvePartCode(array $user): ?string
    {
        if (VoicePartRules::isConductor($user)) {
            return VoicePartRules::RECORDINGS_ALL_PARTS;
        }

        $partCode = (string) ($user['voice_part'] ?? '');
        if ($partCode === '' || !VoicePart::isValid($partCode)) {
            return null;
        }

        return $partCode;
    }

    private static function recordingCountForUser(MaterialsDeps $materials, string $partCode): int
    {
        $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
        $songs = $allParts
            ? $materials->materials->listSongsWithRecordingsAnyPart()
            : $materials->materials->listSongsWithRecordingsForPart($partCode);
        $sum = 0;
        foreach ($songs as $song) {
            $sum += (int) ($song['recording_count'] ?? 0);
        }

        return $sum;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function filterProgramSongsWithRecordings(
        ProgramDeps $programs,
        MaterialsDeps $materials,
        int $programId,
        string $partCode,
    ): array {
        $songs = $programs->programs->getSongs($programId);
        $allParts = $partCode === VoicePartRules::RECORDINGS_ALL_PARTS;
        $filtered = [];
        foreach ($songs as $song) {
            $songId = (int) $song['song_id'];
            $has = $allParts
                ? $materials->materials->listCurrentRecordingsAllParts($songId) !== []
                : $materials->materials->songHasRecordingsForPart($songId, $partCode);
            if ($has) {
                $filtered[] = $song;
            }
        }

        return $filtered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function filterProgramSongsWithNotes(
        ProgramDeps $programs,
        MaterialsDeps $materials,
        int $programId,
    ): array {
        $filtered = [];
        foreach ($programs->programs->getSongs($programId) as $song) {
            $songId = (int) $song['song_id'];
            if ($materials->materials->countCurrentNotes($songId) > 0) {
                $filtered[] = $song;
            }
        }

        return $filtered;
    }

    /**
     * @return list<int>
     */
    private static function programSongIdsWithNotes(
        ProgramDeps $programs,
        MaterialsDeps $materials,
        int $programId,
    ): array {
        $ids = [];
        foreach (self::filterProgramSongsWithNotes($programs, $materials, $programId) as $song) {
            $ids[] = (int) $song['song_id'];
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $songs
     */
    private static function programNoteFileCount(MaterialsDeps $materials, array $songs): int
    {
        $sum = 0;
        foreach ($songs as $song) {
            $sum += $materials->materials->countCurrentNotes((int) $song['song_id']);
        }

        return $sum;
    }

    /**
     * @param list<array<string, mixed>> $filtered
     * @return list<array{id: int, title: string}>
     */
    private static function mapSongsForDelivery(array $filtered): array
    {
        $mapped = [];
        foreach ($filtered as $song) {
            $mapped[] = [
                'id' => (int) $song['song_id'],
                'title' => (string) ($song['title'] ?? ''),
            ];
        }

        return $mapped;
    }

    /**
     * @param list<array<string, mixed>> $filtered
     */
    private static function programRecordingCount(
        MaterialsDeps $materials,
        array $filtered,
        string $partCode,
        bool $allParts,
    ): int {
        $sum = 0;
        foreach ($filtered as $song) {
            $songId = (int) $song['song_id'];
            $rows = $allParts
                ? $materials->materials->listCurrentRecordingsAllParts($songId)
                : $materials->materials->listCurrentRecordings($songId, $partCode);
            $sum += count($rows);
        }

        return $sum;
    }
}