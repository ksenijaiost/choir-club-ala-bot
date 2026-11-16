<?php

declare(strict_types=1);

namespace App\Support;

final class SqlPagination
{
    public static function clampPage(int $page): int
    {
        return max(0, $page);
    }

    public static function clampOffset(int $offset): int
    {
        return max(0, $offset);
    }

    public static function clampLimit(int $limit): int
    {
        return max(1, $limit);
    }

    public static function offsetForPage(int $page, int $pageSize): int
    {
        return self::clampOffset(self::clampPage($page) * self::clampLimit($pageSize));
    }
}