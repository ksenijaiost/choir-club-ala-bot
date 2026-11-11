<?php

declare(strict_types=1);

namespace App\Domain;

final class UserRole
{
    public const MEMBER = 'member';
    public const ADMIN = 'admin';
    public const CONDUCTOR = 'conductor';
    public const OWNER = 'owner';
}
