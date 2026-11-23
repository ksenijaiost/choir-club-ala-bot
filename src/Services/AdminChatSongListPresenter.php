<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Telegram\TelegramHtml;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;

final class AdminChatSongListPresenter
{
    private readonly SongRecordingAvailability $availability;

    public function __construct(
        private readonly SongRepository $songs,
        private readonly MaterialRepository $materials,
        private readonly TextRepository $texts,
    ) {
        $this->availability = new SongRecordingAvailability($materials, $texts);
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
        $blocks = [];
        foreach ($rows as $row) {
            $songId = (int) $row['id'];
            $notesCount = (int) ($row['notes_count'] ?? 0);
            $hasTranslit = ((int) ($row['has_translit'] ?? 0)) > 0;
            $recordingSets = $recordings[$songId] ?? [];
            $recordingDetail = $this->formatRecordingsDetail($recordingSets);
            $buckets = $this->availability->partBuckets($songId);
            $hasRecordings = $recordingSets !== [];
            $title = (string) $row['title'];
            if ($hasRecordings && $buckets['missing'] !== []) {
                $title = '⚠️ ' . $title;
            }

            $lines = [
                '<b>' . TelegramHtml::escape($title) . '</b>',
                $this->texts->get('admin_chat.songs_block_notes', ['count' => (string) $notesCount]),
                $hasTranslit
                    ? $this->texts->get('admin_chat.songs_block_translit_yes')
                    : $this->texts->get('admin_chat.songs_block_translit_no'),
                $this->texts->get('admin_chat.songs_block_recordings', ['detail' => $recordingDetail]),
            ];

            if ($hasRecordings && $buckets['missing'] !== []) {
                $lines[] = $this->availability->formatMissingLine($songId);
            }
            $blocks[] = implode("\n", $lines);
        }

        $header = $this->texts->get('admin_chat.songs_header');
        $songChunks = TelegramMessageChunker::splitByBlocks($blocks);
        if ($songChunks === []) {
            return [$header];
        }

        $chunks = [];
        $first = $header . "\n\n" . $songChunks[0];
        $chunks[] = $first;
        for ($i = 1, $n = count($songChunks); $i < $n; $i++) {
            $chunks[] = $songChunks[$i];
        }

        return $chunks;
    }

    /**
     * @param list<array{parts: list<string>, has_link: bool}> $recordingSets
     */
    private function formatRecordingsDetail(array $recordingSets): string
    {
        if ($recordingSets === []) {
            return $this->texts->get('admin_chat.word_no');
        }

        return $this->formatRecordingItems($recordingSets);
    }

    /**
     * @param list<array{parts: list<string>, has_link: bool}> $recordingSets
     */
    private function formatRecordingItems(array $recordingSets): string
    {
        $sorted = $recordingSets;
        usort($sorted, static function (array $a, array $b): int {
            $aTutti = self::isTutti($a['parts']);
            $bTutti = self::isTutti($b['parts']);
            if ($aTutti !== $bTutti) {
                return $aTutti ? -1 : 1;
            }

            $aIndex = self::primaryPartIndex($a['parts']);
            $bIndex = self::primaryPartIndex($b['parts']);
            if ($aIndex !== $bIndex) {
                return $aIndex <=> $bIndex;
            }

            return implode(',', $a['parts']) <=> implode(',', $b['parts']);
        });

        $items = array_map(fn (array $recording): string => $this->formatRecordingItem($recording), $sorted);

        return implode('; ', $items);
    }

    /**
     * @param array{parts: list<string>, has_link: bool} $recording
     */
    private function formatRecordingItem(array $recording): string
    {
        $parts = $recording['parts'];
        if (self::isTutti($parts)) {
            $label = $this->texts->get('admin_chat.recording_tutti');
        } else {
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

            $label = implode(' + ', $labels);
        }

        if ($recording['has_link'] ?? false) {
            $label .= TelegramHtml::escape($this->texts->get('admin_chat.recording_link_suffix'));
        }

        return $label;
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