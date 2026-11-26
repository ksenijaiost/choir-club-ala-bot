<?php

declare(strict_types=1);

namespace App\Services;

final class AttendanceMarkResult
{
    public function __construct(
        public readonly ?string $appendLine = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}