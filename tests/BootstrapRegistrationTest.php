<?php

declare(strict_types=1);

namespace App\Tests;

use App\Bootstrap;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Handlers\HandlerRegistry;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class BootstrapRegistrationTest extends TestCase
{
    public function testHandlerRegistryAutoloadsAdminHistoryContext(): void
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();

        HandlerRegistry::register($bot, $texts, $config, $pdo);

        self::assertTrue(class_exists(\App\Handlers\AdminHistoryContext::class));
    }

    public function testBootstrapRegisterHandlersWithFakeNutgram(): void
    {
        putenv('BOT_TOKEN=test-token-for-autoload');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_CONNECTION=sqlite');

        Bootstrap::loadEnv();

        $bot = Nutgram::fake();
        Bootstrap::registerHandlers($bot);

        self::assertTrue(class_exists(\App\Handlers\AdminHistoryContext::class));

        putenv('BOT_TOKEN');
        putenv('DB_DATABASE');
        putenv('DB_CONNECTION');
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
