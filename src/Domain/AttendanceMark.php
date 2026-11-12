<?php

declare(strict_types=1);

namespace App\Domain;

final class AttendanceMark
{
    public const ATTENDED = 'attended';
    public const WARNED = 'warned';
    public const UNEXCUSED = 'unexcused';
    public const EXCUSED_MANUAL = 'excused_manual';
}
