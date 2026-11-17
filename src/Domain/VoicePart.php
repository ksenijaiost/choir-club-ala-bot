<?php

declare(strict_types=1);

namespace App\Domain;

final class VoicePart
{
    /** @var list<string> */
    public const CODES = [
        'soprano_1',
        'soprano_2',
        'alto_1',
        'alto_2',
        'tenor',
        'baritone',
        'bass',
    ];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /**
     * @param list<string> $parts
     */
    public static function isTuttiParts(array $parts): bool
    {
        if (count($parts) !== count(self::CODES)) {
            return false;
        }

        foreach (self::CODES as $code) {
            if (!in_array($code, $parts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    public static function sortCodes(array $parts): array
    {
        $copy = $parts;
        usort($copy, static function (string $a, string $b): int {
            $ia = array_search($a, self::CODES, true);
            $ib = array_search($b, self::CODES, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib;
        });

        return $copy;
    }
}
