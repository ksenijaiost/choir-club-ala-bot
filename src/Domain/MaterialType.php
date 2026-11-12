<?php

declare(strict_types=1);

namespace App\Domain;

final class MaterialType
{
    public const NOTES = 'notes';
    public const TRANSLIT = 'translit';
    public const RECORDING = 'recording';

  /** @return list<string> */
    public static function codes(): array
    {
        return [self::NOTES, self::TRANSLIT, self::RECORDING];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::codes(), true);
    }

    public static function isSingleton(string $type): bool
    {
        return $type === self::NOTES || $type === self::TRANSLIT;
    }
}
