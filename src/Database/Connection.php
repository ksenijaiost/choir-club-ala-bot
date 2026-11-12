<?php

declare(strict_types=1);

namespace App\Database;

use App\Bootstrap;
use App\Config;

final class Connection
{
    public static function make(Config $config): \PDO
    {
        if ($config->isSqlite()) {
            $path = $config->dbDatabase;
            if ($path !== ':memory:' && !str_starts_with($path, '/')) {
                $path = Bootstrap::basePath() . '/' . ltrim($path, '/');
            }

            $dir = dirname($path);
            if ($path !== ':memory:' && !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');

            return self::configure($pdo);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbDatabase
        );

        $pdo = new \PDO($dsn, $config->dbUsername, $config->dbPassword);

        return self::configure($pdo);
    }

    private static function configure(\PDO $pdo): \PDO
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }
}
