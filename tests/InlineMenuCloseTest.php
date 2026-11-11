<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Repositories\UserFlowRepository;
use App\Telegram\InlineMenuHelper;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class InlineMenuCloseTest extends TestCase
{
    private const USER_TG = 77_701;

    public function testCloseClearsUserFlow(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $flows = new UserFlowRepository($pdo);
        $flows->set(self::USER_TG, 'song_search', ['query' => 'алла']);

        $bot = Nutgram::fake();
        InlineMenuHelper::handleClose($bot, $flows, $texts, self::USER_TG);

        self::assertNull($flows->get(self::USER_TG));
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
