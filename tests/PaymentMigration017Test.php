<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\BotSettingsRepository;
use App\Repositories\PaymentRecordRepository;
use App\Repositories\UserRepository;
use PHPUnit\Framework\TestCase;

final class PaymentMigration017Test extends TestCase
{
    private const MIGRATION_017 = '017_payment_defer_and_reminder_days.sql';

    public function testMovesDecemberRecordWhenNovemberMissing(): void
    {
        $pdo = Connection::make($this->testConfig());
        $this->applyMigrationsUpTo($pdo, self::MIGRATION_017);

        $users = new UserRepository($pdo);
        $uid = $users->create(8801, 'Миграция', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $pdo->prepare(
            'INSERT INTO payment_records (user_id, period_year, period_month) VALUES (?, 2026, 12)'
        )->execute([$uid]);

        $this->applyMigrationFile($pdo, self::MIGRATION_017);

        $payments = new PaymentRecordRepository($pdo);
        self::assertNotNull($payments->find($uid, 2026, 11));
        self::assertNull($payments->find($uid, 2026, 12));
    }

    public function testDoesNotMoveWhenNovemberExists(): void
    {
        $pdo = Connection::make($this->testConfig());
        $this->applyMigrationsUpTo($pdo, self::MIGRATION_017);

        $users = new UserRepository($pdo);
        $uid = $users->create(8802, 'Два месяца', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $pdo->prepare(
            'INSERT INTO payment_records (user_id, period_year, period_month) VALUES (?, 2026, 11)'
        )->execute([$uid]);
        $pdo->prepare(
            'INSERT INTO payment_records (user_id, period_year, period_month) VALUES (?, 2026, 12)'
        )->execute([$uid]);

        $this->applyMigrationFile($pdo, self::MIGRATION_017);

        $payments = new PaymentRecordRepository($pdo);
        self::assertNotNull($payments->find($uid, 2026, 11));
        self::assertNotNull($payments->find($uid, 2026, 12));
    }

    public function testMigrationSetsReminderDays(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        self::assertSame(15, $settings->getInt('payment_reminder_day_1', 0));
        self::assertSame(20, $settings->getInt('payment_reminder_day_2', 0));
    }

    private function applyMigrationsUpTo(\PDO $pdo, string $stopBefore): void
    {
        $directory = dirname(__DIR__) . '/migrations/sqlite';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        foreach ($files as $file) {
            $name = basename($file);
            if ($name === $stopBefore) {
                break;
            }

            $sql = (string) file_get_contents($file);
            foreach (Migrator::splitSql($sql) as $statement) {
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$name]);
        }
    }

    private function applyMigrationFile(\PDO $pdo, string $name): void
    {
        $path = dirname(__DIR__) . '/migrations/sqlite/' . $name;
        $sql = (string) file_get_contents($path);
        foreach (Migrator::splitSql($sql) as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$name]);
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '',
            dbConnection: 'sqlite',
            dbHost: '',
            dbPort: '',
            dbDatabase: ':memory:',
            dbUsername: '',
            dbPassword: '',
            timezone: 'Asia/Almaty',
            webhookSecret: 'secret',
            adminPagesKey: 'admin-key',
            webhookUrl: '',
            fakeNow: null,
        );
    }
}