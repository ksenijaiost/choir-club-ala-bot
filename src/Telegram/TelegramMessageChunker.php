<?php

declare(strict_types=1);

namespace App\Telegram;

final class TelegramMessageChunker
{
    public const MAX_MESSAGE_LENGTH = 4096;

    /**
     * @return list<string>
     */
    public static function splitByLines(string $text, int $maxLength = self::MAX_MESSAGE_LENGTH): array
    {
        if ($maxLength < 1) {
            $maxLength = self::MAX_MESSAGE_LENGTH;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        if ($normalized === '') {
            return [];
        }

        if (mb_strlen($normalized) <= $maxLength) {
            return [$normalized];
        }

        $chunks = [];
        $current = '';
        $lines = explode("\n", $normalized);

        foreach ($lines as $index => $line) {
            $lineWithBreak = ($index < count($lines) - 1) ? $line . "\n" : $line;

            if (mb_strlen($lineWithBreak) > $maxLength) {
                if ($current !== '') {
                    $chunks[] = rtrim($current, "\n");
                    $current = '';
                }
                foreach (mb_str_split($lineWithBreak, $maxLength) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($current !== '' && mb_strlen($current . $lineWithBreak) > $maxLength) {
                $chunks[] = rtrim($current, "\n");
                $current = '';
            }

            $current .= $lineWithBreak;
        }

        if ($current !== '') {
            $chunks[] = rtrim($current, "\n");
        }

        return $chunks !== [] ? $chunks : [$normalized];
    }

    /**
     * Pack blocks without splitting a single block across chunks.
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    public static function splitByBlocks(
        array $blocks,
        int $maxLength = self::MAX_MESSAGE_LENGTH,
        string $blockSeparator = "\n\n",
    ): array {
        if ($blocks === []) {
            return [];
        }

        $chunks = [];
        $current = '';
        foreach ($blocks as $block) {
            if ($block === '') {
                continue;
            }

            if ($current === '') {
                $current = $block;

                continue;
            }

            $candidate = $current . $blockSeparator . $block;
            if (mb_strlen($candidate) > $maxLength) {
                $chunks[] = $current;
                $current = $block;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}