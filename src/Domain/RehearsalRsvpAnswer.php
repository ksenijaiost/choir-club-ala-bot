<?php

declare(strict_types=1);

namespace App\Domain;

final class RehearsalRsvpAnswer
{
    public const YES = 'yes';
    public const NO = 'no';

    public static function isValid(string $value): bool
    {
        return $value === self::YES || $value === self::NO;
    }
}