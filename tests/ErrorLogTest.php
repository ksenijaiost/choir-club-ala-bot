<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Support\ErrorLog;
use PHPUnit\Framework\TestCase;

final class ErrorLogTest extends TestCase
{
    private ?string $logDir = null;

    protected function tearDown(): void
    {
        if ($this->logDir !== null && is_dir($this->logDir)) {
            foreach (glob($this->logDir . '/*.log') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testWritesThrowableToDailyLogFile(): void
    {
        $config = $this->testConfig();
        $this->logDir = dirname(__DIR__) . '/storage/logs';
        $day = (new \DateTimeImmutable('now', new \DateTimeZone($config->timezone)))->format('Y-m-d');
        $path = ErrorLog::pathForDate($day);

        if (is_file($path)) {
            unlink($path);
        }

        $e = new \RuntimeException('Test webhook failure for logging');
        ErrorLog::log($config, $e, [
            'source' => 'test',
            'update_id' => 42,
            'update_type' => 'message',
            'chat_id' => -100123,
            'user_id' => 7,
        ]);

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('RuntimeException', $content);
        self::assertStringContainsString('Test webhook failure', $content);
        self::assertStringContainsString('update_id=42', $content);
        self::assertStringContainsString('chat_id=-100123', $content);
    }

    public function testRedactsSecretsInMessageAndTrace(): void
    {
        $config = new Config(
            botToken: '7123456789:AAH-dummy-bot-token-secret',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '',
            dbConnection: 'sqlite',
            dbHost: '',
            dbPort: '',
            dbDatabase: ':memory:',
            dbUsername: '',
            dbPassword: 'db-pass-xy',
            timezone: 'Asia/Almaty',
            webhookSecret: 'whsec-test-value',
            adminPagesKey: 'admin-pages-key-test',
            webhookUrl: '',
            fakeNow: null,
        );

        $e = new \RuntimeException(
            'leak 7123456789:AAH-dummy-bot-token-secret whsec-test-value admin-pages-key-test db-pass-xy'
        );
        ErrorLog::log($config, $e, ['source' => 'test']);

        $day = (new \DateTimeImmutable('now', new \DateTimeZone($config->timezone)))->format('Y-m-d');
        $content = (string) file_get_contents(ErrorLog::pathForDate($day));

        self::assertStringNotContainsString('AAH-dummy-bot-token-secret', $content);
        self::assertStringNotContainsString('whsec-test-value', $content);
        self::assertStringNotContainsString('admin-pages-key-test', $content);
        self::assertStringNotContainsString('db-pass-xy', $content);
        self::assertStringContainsString('[скрыто]', $content);
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