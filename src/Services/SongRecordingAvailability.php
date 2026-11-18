<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\VoicePart;
use App\Repositories\MaterialRepository;
use App\Text\TextRepository;

final class SongRecordingAvailability
{
    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly TextRepository $texts,
    ) {
    }

    /**
     * @return array{have: list<string>, missing: list<string>, has_tutti: bool}
     */
    public function partBuckets(int $songId): array
    {
        $recordings = $this->materials->listCurrentRecordingsAllParts($songId);
        /** @var array<string, true> $haveCodes */
        $haveCodes = [];
        $hasTutti = false;

        foreach ($recordings as $row) {
            $parts = is_array($row['voice_parts'] ?? null) ? $row['voice_parts'] : [];
            if (VoicePart::isTuttiParts($parts)) {
                $hasTutti = true;

                continue;
            }

            foreach ($parts as $code) {
                $code = (string) $code;
                if (VoicePart::isValid($code)) {
                    $haveCodes[$code] = true;
                }
            }
        }

        $have = [];
        $missing = [];
        foreach (VoicePart::CODES as $code) {
            $label = $this->texts->get('parts.' . $code);
            if (isset($haveCodes[$code])) {
                $have[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        return [
            'have' => $have,
            'missing' => $missing,
            'has_tutti' => $hasTutti,
        ];
    }

  /**
     * @return list<string>
     */
    public function presentHaveLabels(int $songId): array
    {
        $buckets = $this->partBuckets($songId);
        $labels = $buckets['have'];
        if ($buckets['has_tutti']) {
            $labels[] = $this->texts->get('admin_chat.recording_tutti');
        }

        return $labels;
    }

    public function formatHaveLine(int $songId): string
    {
        $labels = $this->presentHaveLabels($songId);
        $joined = $labels === [] ? '—' : implode(', ', $labels);

        return $this->texts->get('materials.recordings_have_line', ['parts' => $joined]);
    }

    public function formatMissingLine(int $songId): string
    {
        $missing = $this->partBuckets($songId)['missing'];
        $joined = $missing === [] ? '—' : implode(', ', $missing);

        return $this->texts->get('materials.recordings_missing_line', ['parts' => $joined]);
    }

    public function formatUploadHint(int $songId): string
    {
        $labels = $this->presentHaveLabels($songId);
        if ($labels === []) {
            return $this->texts->get('materials.recordings_upload_none');
        }

        return $this->texts->get('materials.recordings_upload_hint', [
            'parts' => implode(', ', $labels),
        ]);
    }
}