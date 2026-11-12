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
}
