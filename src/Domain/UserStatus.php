<?php

declare(strict_types=1);

namespace App\Domain;

final class UserStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const BLOCKED = 'blocked';
    public const REJECTED = 'rejected';
}
