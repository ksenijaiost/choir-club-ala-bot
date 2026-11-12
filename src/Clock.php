<?php

declare(strict_types=1);

namespace App;

final class Clock
{
    public static function now(Config $config): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($config->timezone);
        $fake = $config->fakeNow;

        if ($fake !== null && $fake !== '') {
            return new \DateTimeImmutable($fake, $tz);
        }

        return new \DateTimeImmutable('now', $tz);
    }
}
