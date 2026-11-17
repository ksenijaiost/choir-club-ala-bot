<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\MaterialType;
use App\Domain\VoicePart;
use App\Services\VoicePartRules;
use App\Services\SongDeliveryService;
use App\Services\SongRecordingPartPicker;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use SergiX44\Nutgram\Nutgram;
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

        if (preg_match('/^snd:cat:pg:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            SongMemberHandler::editMaterialCatalog($bot, $deps, (int) $m[1]);

            return true;
        }

        if (preg_match('/^snd:sr:pg:(\d+)$/', $data, $m) === 1) {
            $telegramId = $bot->userId();
            $query = '';
            if ($telegramId !== null) {
                $state = $deps->flows->get($telegramId);
                if (($state['flow'] ?? '') === 'song_search') {
                    $query = trim((string) (($state['context']['query'] ?? '')));
                }
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            if ($query !== '') {
                SongMemberHandler::sendSearchResults($bot, $deps, $query, (int) $m[1]);
            }

            return true;
        }

        if (preg_match('/^snd:my:pg:([a-z0-9_]+):(\d+)$/', $data, $m) === 1) {
            $part = $m[1];
            if ($part !== VoicePartRules::RECORDINGS_ALL_PARTS && !VoicePart::isValid($part)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $bot->answerCallbackQuery();
            SongMemberHandler::editMyRecordingsPage($bot, $deps, $part, (int) $m[2]);

            return true;
        }

        if (preg_match('/^snd:pk:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $song = $deps->songs->findById((int) $m[1]);
            if ($song === null) {
                $bot->sendMessage($deps->texts->get('songs.not_found'));

                return true;
            }

            $viewer = null;
            $telegramId = $bot->userId();
            if ($telegramId !== null) {
                $viewer = $deps->users->findByTelegramId($telegramId);
            }
            $delivery->sendSongCard($bot, $song, $viewer);

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
            self::sendPartPicker($bot, $deps, (int) $m[1], SongRecordingPartPicker::VIEW_HOME);

            return true;
        }

        if (preg_match('/^snd:pr:oth:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            self::editPartPicker($bot, $deps, (int) $m[1], SongRecordingPartPicker::VIEW_OTHERS);

            return true;
        }

        if (preg_match('/^snd:pr:hom:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            self::editPartPicker($bot, $deps, (int) $m[1], SongRecordingPartPicker::VIEW_HOME);

            return true;
        }

        if (preg_match('/^snd:rp:(\d+):([a-z0-9_]+)$/', $data, $m) === 1) {
            $part = $m[2];
            if ($part !== 'tutti' && !VoicePart::isValid($part)) {
                $bot->answerCallbackQuery();

                return true;
            }

            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            $songId = (int) $m[1];
            $rows = $part === 'tutti'
                ? $deps->materials->listTuttiRecordings($songId)
                : $deps->materials->listCurrentRecordings($songId, $part);
            $delivery->sendRecordingFiles($bot, $rows);

            return true;
        }

        if (preg_match('/^snd:my:(\d+):([a-z0-9_]+)$/', $data, $m) === 1) {
            $part = $m[2];
            if ($part === VoicePartRules::RECORDINGS_ALL_PARTS) {
                $bot->answerCallbackQuery();
                CallbackUi::clearKeyboard($bot);
                $rows = $deps->materials->listCurrentRecordingsAllParts((int) $m[1]);
                $delivery->sendRecordingFiles($bot, $rows);

                return true;
            }

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

    private static function sendPartPicker(Nutgram $bot, MaterialsDeps $deps, int $songId, string $view): void
    {
        $built = self::buildPartPicker($bot, $deps, $songId, $view);
        if ($built === null) {
            $bot->sendMessage($deps->texts->get('songs.no_material'));

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

    private static function editPartPicker(Nutgram $bot, MaterialsDeps $deps, int $songId, string $view): void
    {
        $built = self::buildPartPicker($bot, $deps, $songId, $view);
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
    private static function buildPartPicker(Nutgram $bot, MaterialsDeps $deps, int $songId, string $view): ?array
    {
        $song = $deps->songs->findById($songId);
        if ($song === null) {
            return null;
        }

        $viewer = null;
        $telegramId = $bot->userId();
        if ($telegramId !== null) {
            $viewer = $deps->users->findByTelegramId($telegramId);
        }

        $picker = new SongRecordingPartPicker($deps->materials, $deps->texts);

        return $picker->build(
            $songId,
            (string) ($song['title'] ?? ''),
            $viewer,
            $view,
        );
    }
}
