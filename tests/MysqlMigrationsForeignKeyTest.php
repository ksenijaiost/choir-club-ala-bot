<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class MysqlMigrationsForeignKeyTest extends TestCase
{
    public function testUserForeignKeyColumnsAreBigIntUnsigned(): void
    {
        $dir = dirname(__DIR__) . '/migrations/mysql';
        $files = glob($dir . '/*.sql');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $violations = [];

        foreach ($files as $path) {
            $sql = (string) file_get_contents($path);
            if (!preg_match_all(
                '/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE/is',
                $sql,
                $tables,
                PREG_SET_ORDER,
            )) {
                continue;
            }

            foreach ($tables as $table) {
                $tableName = $table[1];
                $body = $table[2];
                if (!preg_match_all(
                    '/FOREIGN KEY\s*\(\s*(\w+)\s*\)\s*REFERENCES\s+users\s*\(\s*id\s*\)/i',
                    $body,
                    $fks,
                    PREG_SET_ORDER,
                )) {
                    continue;
                }

                foreach ($fks as $fk) {
                    $column = $fk[1];
                    if (!preg_match(
                        '/\b' . preg_quote($column, '/') . '\s+(BIGINT\s+UNSIGNED)/i',
                        $body,
                        $type,
                    )) {
                        $violations[] = basename($path) . ": table {$tableName}, FK column {$column} must be BIGINT UNSIGNED";
                    }
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}