<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class SongRecordingPartPicker
{
    public const VIEW_HOME = 'home';

    public const VIEW_OTHERS = 'others';

    public const VIEW_ALL = 'all';

    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly TextRepository $texts,
    ) {
    }

    /**
     * @param array<string, mixed>|null $viewer
     *
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    public function build(int $songId, string $songTitle, ?array $viewer, string $view): ?array
    {
        if ($view === self::VIEW_ALL) {
            return $this->buildFullList($songId, $songTitle, false);
        }

        if ($view === self::VIEW_OTHERS) {
            if ($viewer !== null
                && VoicePartRules::hasAssignedVoicePart($viewer)
                && !VoicePartRules::seesAnyRecordingsOnSongMenu($viewer)) {
                return $this->buildOthersList($songId, $songTitle, $viewer);
            }

            return $this->buildFullList($songId, $songTitle, true);
        }

        if ($viewer !== null && VoicePartRules::seesAnyRecordingsOnSongMenu($viewer)) {
            return $this->buildFullList($songId, $songTitle, false);
        }

        if ($viewer === null || !VoicePartRules::hasAssignedVoicePart($viewer)) {
            return $this->buildFullList($songId, $songTitle, false);
        }

        return $this->buildMemberHome($songId, $songTitle, $viewer);
    }

    /**
     * @param array<string, mixed> $viewer
     *
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    private function buildMemberHome(int $songId, string $songTitle, array $viewer): ?array
    {
        $viewerPart = (string) ($viewer['voice_part'] ?? '');
        if (!VoicePart::isValid($viewerPart)) {
            return $this->buildFullList($songId, $songTitle, false);
        }

        $hasOwn = $this->materials->countRecordingsForPart($songId, $viewerPart) > 0;
        $tuttiCount = $this->materials->countTuttiRecordings($songId);
        $otherParts = $this->otherPartsForViewer($songId, $viewerPart);

        if (!$hasOwn && $tuttiCount === 0 && $otherParts === []) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $text = $this->texts->get('songs.pick_part_for_recordings', ['title' => $songTitle]);
        if (!$hasOwn) {
            $text .= "\n\n" . $this->texts->get('songs.recordings_no_for_your_part');
        }

        if ($hasOwn) {
            $count = $this->materials->countRecordingsForPart($songId, $viewerPart);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('songs.recordings_your_part_button', [
                        'part' => $this->texts->get('parts.' . $viewerPart),
                        'count' => (string) $count,
                    ]),
                    callback_data: 'snd:rp:' . $songId . ':' . $viewerPart,
                ),
            );
        }

        if ($tuttiCount > 0) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('songs.recordings_tutti_button', ['count' => (string) $tuttiCount]),
                    callback_data: 'snd:rp:' . $songId . ':tutti',
                ),
            );
        }

        if ($otherParts !== []) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('songs.recordings_other_parts_button'),
                    callback_data: 'snd:pr:oth:' . $songId,
                ),
            );
        }

        return [
            'text' => $text,
            'keyboard' => $this->withFooter($keyboard, $songId),
        ];
    }

    /**
     * @param array<string, mixed> $viewer
     *
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    private function buildOthersList(int $songId, string $songTitle, array $viewer): ?array
    {
        $viewerPart = (string) ($viewer['voice_part'] ?? '');
        $parts = $this->otherPartsForViewer($songId, $viewerPart);
        if ($parts === []) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($parts as $code) {
            $count = $this->materials->countRecordingsForPart($songId, $code);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('parts.' . $code) . ' (' . $count . ')',
                    callback_data: 'snd:rp:' . $songId . ':' . $code,
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('ui.back'),
                callback_data: 'snd:pr:hom:' . $songId,
            ),
        );

        return [
            'text' => $this->texts->get('songs.pick_part_for_recordings_others', ['title' => $songTitle]),
            'keyboard' => $this->withFooter($keyboard, $songId),
        ];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}|null
     */
    private function buildFullList(int $songId, string $songTitle, bool $isOthersHeader): ?array
    {
        $parts = $this->materials->listPartsWithRecordingsForSongOrdered($songId);
        $tuttiCount = $this->materials->countTuttiRecordings($songId);
        if ($parts === [] && $tuttiCount === 0) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($parts as $code) {
            $count = $this->materials->countRecordingsForPart($songId, $code);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('parts.' . $code) . ' (' . $count . ')',
                    callback_data: 'snd:rp:' . $songId . ':' . $code,
                ),
            );
        }

        if ($tuttiCount > 0) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('songs.recordings_tutti_button', ['count' => (string) $tuttiCount]),
                    callback_data: 'snd:rp:' . $songId . ':tutti',
                ),
            );
        }

        $textKey = $isOthersHeader
            ? 'songs.pick_part_for_recordings_others'
            : 'songs.pick_part_for_recordings';

        return [
            'text' => $this->texts->get($textKey, ['title' => $songTitle]),
            'keyboard' => $this->withFooter($keyboard, $songId),
        ];
    }

    private function withFooter(InlineKeyboardMarkup $keyboard, int $songId): InlineKeyboardMarkup
    {
        return \App\Telegram\InlineMenuHelper::appendFooter($keyboard, $this->texts, 'snd:pk:' . $songId);
    }

    /**
     * @return list<string>
     */
    private function otherPartsForViewer(int $songId, string $viewerPart): array
    {
        $parts = $this->materials->listPartsWithRecordingsForSongOrdered($songId);
        $other = [];
        foreach ($parts as $code) {
            if ($code !== $viewerPart) {
                $other[] = $code;
            }
        }

        return $other;
    }
}