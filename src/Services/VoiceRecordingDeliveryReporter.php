<?php

declare(strict_types=1);

namespace App\Services;

use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class VoiceRecordingDeliveryReporter
{
    /**
     * @var list<array{title: string, part: string, url: ?string}>
     */
    private array $failures = [];

    /**
     * @param array<string, mixed> $material
     */
    public function track(string $songTitle, array $material, MaterialsService $materialsService): void
    {
        $parts = is_array($material['voice_parts'] ?? null)
            ? $material['voice_parts']
            : [];
        $partLabel = $materialsService->formatRecordingPartsLabel($parts);
        $url = trim((string) ($material['source_message_url'] ?? ''));

        $this->failures[] = [
            'title' => $songTitle,
            'part' => $partLabel,
            'url' => $url !== '' ? $url : null,
        ];
    }

    public function count(): int
    {
        return count($this->failures);
    }

    public function flush(Nutgram $bot, TextRepository $texts, ?int $chatId = null): void
    {
        if ($this->failures === []) {
            return;
        }

        $lines = [
            $texts->get('materials.voice_forbidden_warning', [
                'count' => (string) $this->count(),
            ]),
        ];

        $withLinks = array_values(array_filter(
            $this->failures,
            static fn (array $row): bool => ($row['url'] ?? null) !== null,
        ));

        if ($withLinks !== []) {
            foreach ($withLinks as $row) {
                $lines[] = $texts->get('materials.voice_forbidden_link_line', [
                    'title' => $row['title'],
                    'part' => $row['part'],
                    'url' => (string) $row['url'],
                ]);
            }
        }

        SafeTelegram::sendMessage($bot, implode("\n", $lines), $chatId, null, null, true);
    }
}