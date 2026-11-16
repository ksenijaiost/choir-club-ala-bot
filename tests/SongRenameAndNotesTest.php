<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\MaterialsCallbackHandler;
use App\Handlers\MaterialsDeps;
use App\Handlers\MaterialsFlowHandler;
use App\Handlers\SongsCallbackHandler;
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
use SergiX44\Nutgram\Telegram\Types\User\User;

final class SongRenameAndNotesTest extends TestCase
{
    private const ADMIN_TG = 95_001;
    private const MEMBER_TG = 95_002;

    public function testSongRenameSuccessKeepsMaterials(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $uploaderId = (int) $users->findByTelegramId(self::ADMIN_TG)['id'];

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Старое имя');
        $materials = new MaterialRepository($pdo);
        $materials->insert($songId, MaterialType::NOTES, null, 'f1', 'u1', $uploaderId, null, true);

        $deps = $this->materialsDeps($pdo, $texts, $users);
        $deps->flows->set(self::ADMIN_TG, 'material_rename', ['song_id' => $songId]);

        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::ADMIN_TG));
        MaterialsFlowHandler::handleFlowMessage($bot, $deps, self::ADMIN_TG, 'Новое имя');

        $updated = $songs->findById($songId);
        self::assertSame('Новое имя', $updated['title']);
        self::assertCount(1, $materials->listCurrentNotes($songId));
        $bot->assertReplyText($texts->get('materials.rename_success', [
            'old' => 'Старое имя',
            'new' => 'Новое имя',
        ]));
    }

    public function testSongRenameRejectsShortTitle(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Нормальное');

        $deps = $this->materialsDeps($pdo, $texts, $users);
        $deps->flows->set(self::ADMIN_TG, 'material_rename', ['song_id' => $songId]);

        $bot = Nutgram::fake();
        MaterialsFlowHandler::handleFlowMessage($bot, $deps, self::ADMIN_TG, 'А');

        $bot->assertReplyText($texts->get('materials.invalid_song_title'));
        self::assertSame('Нормальное', $songs->findById($songId)['title']);
    }

    public function testSongRenameRejectsDuplicateTitle(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songs->create('Уже есть');
        $songId = $songs->create('Другое');

        $deps = $this->materialsDeps($pdo, $texts, $users);
        $deps->flows->set(self::ADMIN_TG, 'material_rename', ['song_id' => $songId]);

        $bot = Nutgram::fake();
        MaterialsFlowHandler::handleFlowMessage($bot, $deps, self::ADMIN_TG, '  уже есть ');

        $bot->assertReplyText($texts->get('materials.rename_duplicate'));
        self::assertSame('Другое', $songs->findById($songId)['title']);
    }

    public function testRenameForbiddenForMember(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Хорист', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $actor = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($actor);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Песня');

        $deps = $this->materialsDeps($pdo, $texts, $users);
        $access = new AccessControl($config);
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));

        $handled = MaterialsCallbackHandler::handle(
            $bot,
            $deps,
            $access,
            $actor,
            'mat:mg:ren:' . $songId,
        );
        self::assertTrue($handled);
        $bot->assertCalled('answerCallbackQuery');
    }

    public function testNotesDeliveryAlbumForThreeFiles(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Альбом');
        $materials = new MaterialRepository($pdo);
        foreach (['n1', 'n2', 'n3'] as $i => $uid) {
            $materials->insert($songId, MaterialType::NOTES, null, 'f' . $i, $uid, $uploaderId, null, true);
        }

        $delivery = new SongDeliveryService(
            $materials,
            $texts,
            new MaterialsService($materials, $songs, $texts),
        );

        $bot = Nutgram::fake();
        $delivery->sendNotes($bot, $songId);
        $bot->assertCalled('sendMediaGroup');
        $bot->assertCalled('sendDocument', 0);
    }

    public function testNotesDeliverySingleFileUsesDocument(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Одна');
        $materials = new MaterialRepository($pdo);
        $materials->insert($songId, MaterialType::NOTES, null, 'f1', 'u1', $uploaderId, null, true);

        $delivery = new SongDeliveryService(
            $materials,
            $texts,
            new MaterialsService($materials, $songs, $texts),
        );

        $bot = Nutgram::fake();
        $delivery->sendNotes($bot, $songId);
        $bot->assertCalled('sendDocument');
        $bot->assertCalled('sendMediaGroup', 0);
    }

    public function testNotesDeliveryTwelveFilesUsesTwoAlbums(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Много');
        $materials = new MaterialRepository($pdo);
        for ($i = 0; $i < 12; $i++) {
            $materials->insert($songId, MaterialType::NOTES, null, 'f' . $i, 'u' . $i, $uploaderId, null, true);
        }

        $delivery = new SongDeliveryService(
            $materials,
            $texts,
            new MaterialsService($materials, $songs, $texts),
        );

        $bot = Nutgram::fake();
        $delivery->sendNotes($bot, $songId);
        $bot->assertCalled('sendMediaGroup', 2);
        $bot->assertCalled('sendDocument', 0);
    }

    public function testDeleteOneOfMultipleNotes(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $actor = $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $actorRow = $users->findByTelegramId(self::ADMIN_TG);
        self::assertNotNull($actorRow);
        $uploaderId = (int) $actorRow['id'];

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Три файла');
        $materials = new MaterialRepository($pdo);
        $id1 = $materials->insert($songId, MaterialType::NOTES, null, 'f1', 'u1', $uploaderId, null, true);
        $materials->insert($songId, MaterialType::NOTES, null, 'f2', 'u2', $uploaderId, null, true);

        $deps = $this->materialsDeps($pdo, $texts, $users);
        $access = new AccessControl($config);
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->tgUser(self::ADMIN_TG));

        MaterialsCallbackHandler::handle($bot, $deps, $access, $actorRow, 'mat:mg:del:' . $id1);

        self::assertCount(1, $materials->listCurrentNotes($songId));
        self::assertSame('u2', $materials->listCurrentNotes($songId)[0]['telegram_file_unique_id']);
    }

    public function testMemberNotesButtonTriggersDelivery(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Помощник', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $songId = $songs->create('Для хориста');
        $materials = new MaterialRepository($pdo);
        $materials->insert($songId, MaterialType::NOTES, null, 'fa', 'ua', $uploaderId, null, true);
        $materials->insert($songId, MaterialType::NOTES, null, 'fb', 'ub', $uploaderId, null, true);

        $deps = $this->materialsDeps($pdo, $texts, $users);
        $bot = Nutgram::fake();

        SongsCallbackHandler::handle(
            $bot,
            $deps,
            $deps->delivery,
            'snd:nt:' . $songId,
        );

        $bot->assertCalled('sendMediaGroup');
    }

    private function materialsDeps(\PDO $pdo, TextRepository $texts, UserRepository $users): MaterialsDeps
    {
        $config = $this->testConfig();
        $flows = new UserFlowRepository($pdo);
        $songRepo = new SongRepository($pdo);
        $materialRepo = new MaterialRepository($pdo);
        $service = new MaterialsService($materialRepo, $songRepo, $texts);

        return new MaterialsDeps(
            $config,
            $users,
            $flows,
            $songRepo,
            $materialRepo,
            $service,
            new SongDeliveryService($materialRepo, $texts, $service),
            $texts,
        );
    }

    private function tgUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'Тест';

        return $user;
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: '-1001',
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