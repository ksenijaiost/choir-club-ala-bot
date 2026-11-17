<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Text\TextRepository;

final class AdminChatSongListPresenter
{
    public function __construct(
        private readonly SongRepository $songs,
        private readonly MaterialRepository $materials,
        private readonly TextRepository $texts,
    ) {
    }

    /**
     * @return list<string>
     */
    public function buildMessageChunks(): array
    {
        $rows = $this->songs->listAllWithCurrentSingletonFlags();
        if ($rows === []) {
            return [$this->texts->get('admin_chat.songs_empty')];
        }

        $recordings = $this->materials->listCurrentRecordingPartSetsBySong();
        $lines = [$this->texts->get('admin_chat.songs_header')];

        foreach ($rows as $row) {
            $songId = (int) $row['id'];
            $notesCount = (int) ($row['notes_count'] ?? 0);
            $hasTranslit = ((int) ($row['has_translit'] ?? 0)) > 0;
            $recordingSets = $recordings[$songId] ?? [];
            $recordingText = $this->formatRecordings($recordingSets);

            $lines[] = $this->texts->get('admin_chat.songs_line', [
                'title' => (string) $row['title'],
                'notes' => $this->formatNotesCount($notesCount),
                'translit' => $hasTranslit
                    ? $this->texts->get('admin_chat.mark_yes')
                    : $this->texts->get('admin_chat.mark_no'),
                'recordings' => $recordingText,
            ]);
        }

        $body = implode("\n", $lines);

        return \App\Telegram\TelegramMessageChunker::splitByLines($body);
    }

    private function formatNotesCount(int $count): string
    {
        if ($count <= 0) {
            return $this->texts->get('admin_chat.mark_no');
        }

        if ($count === 1) {
            return $this->texts->get('admin_chat.mark_yes');
        }

        return $this->texts->get('admin_chat.notes_many', ['count' => (string) $count]);
    }

    /**
     * @param list<list<string>> $recordingSets
     */
    private function formatRecordings(array $recordingSets): string
    {
        if ($recordingSets === []) {
            return $this->texts->get('admin_chat.songs_recordings_none');
        }

        $sorted = $recordingSets;
        usort($sorted, static function (array $a, array $b): int {
            $aTutti = self::isTutti($a);
            $bTutti = self::isTutti($b);
            if ($aTutti !== $bTutti) {
                return $aTutti ? -1 : 1;
            }

            $aIndex = self::primaryPartIndex($a);
            $bIndex = self::primaryPartIndex($b);
            if ($aIndex !== $bIndex) {
                return $aIndex <=> $bIndex;
            }

            return implode(',', $a) <=> implode(',', $b);
        });

        $items = array_map(fn (array $parts): string => $this->formatRecordingItem($parts), $sorted);

        return $this->texts->get('admin_chat.songs_recordings', [
            'items' => implode('; ', $items),
        ]);
    }

    /**
     * @param list<string> $parts
     */
    private function formatRecordingItem(array $parts): string
    {
        if (self::isTutti($parts)) {
            return $this->texts->get('admin_chat.recording_tutti');
        }

        usort($parts, static function (string $a, string $b): int {
            $ia = array_search($a, VoicePart::CODES, true);
            $ib = array_search($b, VoicePart::CODES, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib;
        });

        $labels = array_map(
            fn (string $code): string => $this->texts->get('parts.' . $code),
            $parts,
        );

        return implode(' + ', $labels);
    }

    /**
     * @param list<string> $parts
     */
    private static function isTutti(array $parts): bool
    {
        if (count($parts) !== count(VoicePart::CODES)) {
            return false;
        }

        foreach (VoicePart::CODES as $code) {
            if (!in_array($code, $parts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $parts
     */
    private static function primaryPartIndex(array $parts): int
    {
        $min = 999;
        foreach ($parts as $code) {
            $index = array_search($code, VoicePart::CODES, true);
            if ($index !== false && $index < $min) {
                $min = $index;
            }
        }

        return $min;
    }
}