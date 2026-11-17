<?php

declare(strict_types=1);

namespace App\Services;

final class SongTranslitPreview
{
    public const MAX_PREVIEW_CHARS = 1000;

    public const NON_EMPTY_LINE_LIMIT = 5;

    /**
     * @return array{text: string, complete: bool}
     */
    public static function build(string $fullText): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $fullText);
        $segment = self::segmentForPreview($normalized);
        $trimmed = self::trimToWordBoundary($segment, self::MAX_PREVIEW_CHARS);
        $complete = self::isCompletePreview($normalized, $segment, $trimmed);

        return [
            'text' => $trimmed,
            'complete' => $complete,
        ];
    }

    private static function segmentForPreview(string $text): string
    {
        $separator = MaterialsService::TEXT_NOTES_SEPARATOR;
        $pos = strpos($text, $separator);
        if ($pos !== false) {
            return rtrim(substr($text, 0, $pos));
        }

        $lines = explode("\n", $text);
        $nonEmpty = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $nonEmpty[] = $line;
            }
            if (count($nonEmpty) >= self::NON_EMPTY_LINE_LIMIT) {
                break;
            }
        }

        if ($nonEmpty === []) {
            return '';
        }

        return implode("\n", $nonEmpty);
    }

    private static function isCompletePreview(string $fullText, string $segment, string $trimmed): bool
    {
        if ($trimmed !== $segment) {
            return false;
        }

        if (str_contains($fullText, MaterialsService::TEXT_NOTES_SEPARATOR)) {
            return false;
        }

        $nonEmptyCount = 0;
        foreach (explode("\n", $fullText) as $line) {
            if (trim($line) !== '') {
                $nonEmptyCount++;
            }
        }

        return $nonEmptyCount <= self::NON_EMPTY_LINE_LIMIT;
    }

    private static function trimToWordBoundary(string $text, int $maxChars): string
    {
        if ($text === '' || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $slice = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($maxChars * 0.5)) {
            $slice = mb_substr($slice, 0, $lastSpace);
        }

        return rtrim($slice) . '…';
    }
}