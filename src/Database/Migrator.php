<?php

declare(strict_types=1);

namespace App\Database;

use App\Bootstrap;
use App\Config;

final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $driver,
    ) {
    }

    public static function forConfig(Config $config): self
    {
        return new self(Connection::make($config), $config->migrationDriver());
    }

    /**
     * @return list<string> newly applied migration basenames
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $directory = Bootstrap::basePath() . '/migrations/' . $this->driver;
        if (!is_dir($directory)) {
            throw new \RuntimeException('Migrations directory not found: ' . $directory);
        }

        $files = glob($directory . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($this->isApplied($name)) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            $this->runStatements($sql);
            $this->markApplied($name);
            $applied[] = $name;
        }

        return $applied;
    }

    private function ensureMigrationsTable(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL UNIQUE,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );

            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_schema_migrations_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function isApplied(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = ? LIMIT 1');
        $stmt->execute([$name]);

        return (bool) $stmt->fetchColumn();
    }

    private function markApplied(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $stmt->execute([$name]);
    }

    private function runStatements(string $sql): void
    {
        $statements = self::splitSql($sql);
        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            $this->pdo->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $buffer = '';
        $statements = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $statements[] = trim($buffer);
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}
