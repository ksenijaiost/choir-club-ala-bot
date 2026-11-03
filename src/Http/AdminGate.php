<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;

final class AdminGate
{
    public static function requireKey(Config $config): void
    {
        if ($config->adminPagesKey === '') {
            self::deny('ADMIN_PAGES_KEY is not configured');
        }

        $provided = $_GET['key'] ?? $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        if (!is_string($provided)) {
            $provided = '';
        }

        if (!hash_equals($config->adminPagesKey, $provided)) {
            self::deny('Forbidden');
        }
    }

    private static function deny(string $message): never
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
