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

        $recordings = $this->materials->listCurrentRecordingSummariesBySong();
        $lines = [$this->texts->get('admin_chat.songs_header')];

        foreach ($rows as $row) {
            $songId = (int) $row['id'];
            $notesCount = (int) ($row['notes_count'] ?? 0);
            $hasTranslit = ((int) ($row['has_translit'] ?? 0)) > 0;
            $recording = $recordings[$songId] ?? ['count' => 0, 'parts' => []];
            $recordingText = $this->formatRecordings($recording['count'], $recording['parts']);

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
     * @param list<string> $partCodes
     */
    private function formatRecordings(int $count, array $partCodes): string
    {
        if ($count <= 0 || $partCodes === []) {
            return $this->texts->get('admin_chat.songs_recordings_none');
        }

        usort($partCodes, static function (string $a, string $b): int {
            $ia = array_search($a, VoicePart::CODES, true);
            $ib = array_search($b, VoicePart::CODES, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib;
        });

        $labels = array_map(
            fn (string $code): string => $this->texts->get('parts.' . $code),
            $partCodes,
        );

        return $this->texts->get('admin_chat.songs_recordings', [
            'count' => (string) $count,
            'parts' => implode(', ', $labels),
        ]);
    }
}