<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\MaterialsTestDeps;
use App\Repositories\UserOpenMenuRepository;
use App\Telegram\InteractiveMenuService;
use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Handlers\MaterialsDeps;
use App\Handlers\MaterialsFlowHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class MaterialsAdminChatSongPickerTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1008035505540528;

    public function testSendSongPickerInAdminChatUsesFirstPage(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $songs = new SongRepository($pdo);
        $songs->create('Аллилуия');
        $songs->create('Благодарение');

        $deps = $this->materialsDeps($config, $pdo, $texts);
        $bot = Nutgram::fake();

        MaterialsFlowHandler::sendSongPicker($bot, $deps, page: 0, chatId: self::ADMIN_CHAT_ID);

        $negativeOffsetRows = $songs->listAlphabetical(self::ADMIN_CHAT_ID, 10);
        self::assertCount(2, $negativeOffsetRows);

        $bot->assertReplyMessage([
            'chat_id' => self::ADMIN_CHAT_ID,
            'text' => $texts->get('materials.ask_song'),
        ]);
    }

    public function testListAlphabeticalClampsNegativeOffset(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $songs = new SongRepository($pdo);
        $songs->create('Песня A');

        $rows = $songs->listAlphabetical(-8035505540528, 5);
        self::assertCount(1, $rows);
        self::assertSame('Песня A', $rows[0]['title']);
    }

    private function materialsDeps(Config $config, \PDO $pdo, TextRepository $texts): MaterialsDeps
    {
        $users = new UserRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        return MaterialsTestDeps::build(
            $config,
                        $pdo,
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: (string) self::ADMIN_CHAT_ID,
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