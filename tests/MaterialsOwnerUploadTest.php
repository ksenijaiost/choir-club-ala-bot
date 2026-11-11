<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Handlers\MaterialsAdminHandler;
use App\Handlers\MaterialsDeps;
use App\Handlers\MaterialsFlowHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\MaterialsService;
use App\Services\SongDeliveryService;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class MaterialsOwnerUploadTest extends TestCase
{
    private const OWNER_TG = 99_001;
    private const ADMIN_CHAT_ID = -100_777;

    public function testOwnerCanUploadNotesAfterAdminChatFileFlow(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Владелица');
        $owner = $users->findByTelegramId(self::OWNER_TG);
        self::assertNotNull($owner);

        $access = new AccessControl($config);
        self::assertTrue($access->canUploadMaterials($owner));

        $deps = $this->materialsDeps($config, $pdo, $texts, $users);
        $songId = $deps->songs->create('Тестовая песня');

        $deps->flows->set(self::OWNER_TG, 'material_upload', [
            'file_id' => 'AgACAgIAAxkBAAI',
            'file_unique_id' => 'owner-notes-uniq-1',
            'admin_chat_id' => self::ADMIN_CHAT_ID,
            'admin_chat_message_id' => 42,
            'song_id' => $songId,
            'type' => MaterialType::NOTES,
        ]);

        $bot = Nutgram::fake();
        MaterialsFlowHandler::completeUpload($bot, $deps, $owner);

        $material = $deps->materials->findCurrentSingleton($songId, MaterialType::NOTES);
        self::assertNotNull($material);
        self::assertSame('owner-notes-uniq-1', $material['telegram_file_unique_id']);
        self::assertSame((int) $owner['id'], (int) $material['uploaded_by_user_id']);
        self::assertNull($deps->flows->get(self::OWNER_TG));
    }

    public function testOwnerCanOpenMaterialsAdminMenu(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Владелица');
        $owner = $users->findByTelegramId(self::OWNER_TG);
        self::assertNotNull($owner);

        $access = new AccessControl($config);
        $deps = $this->materialsDeps($config, $pdo, $texts, $users);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->makeTelegramUser(self::OWNER_TG));

        MaterialsAdminHandler::openHome($bot, $texts, $access, $deps, $owner);

        $bot->assertReplyText($texts->get('materials.admin_chat_hint'));
    }

    private function materialsDeps(
        Config $config,
        \PDO $pdo,
        TextRepository $texts,
        UserRepository $users,
    ): MaterialsDeps {
        $flows = new UserFlowRepository($pdo);
        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);

        return new MaterialsDeps(
            $config,
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            new MaterialsService($materialRepo, $songRepo, $texts),
            new SongDeliveryService($materialRepo, $texts),
            $texts,
        );
    }

    private function makeTelegramUser(int $id): \SergiX44\Nutgram\Telegram\Types\User\User
    {
        $user = new \SergiX44\Nutgram\Telegram\Types\User\User();
        $user->id = $id;
        $user->first_name = 'Владелица';

        return $user;
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: (string) self::OWNER_TG,
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
