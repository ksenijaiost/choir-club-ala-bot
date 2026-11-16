<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AdminChatCommandHandler;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use App\Services\AdminChatMemberListPresenter;
use App\Services\AdminChatSongListPresenter;
use App\Services\AccessControl;
use App\Services\UserRolePresenter;
use App\Text\TextRepository;
use App\Telegram\TelegramMessageChunker;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AdminChatCommandsTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;
    private const ADMIN_TG = 91001;
    private const HELPER_TG = 91002;
    private const MEMBER_TG = 91003;

    public function testSongsListMarksMaterials(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);

        $idA = $songs->create('Аве Мария');
        $idB = $songs->create('Благослови');
        $idC = $songs->create('Гимн');

        $materials->insert($idA, MaterialType::NOTES, null, 'f1', 'u1', $uploaderId, null, true, null);
        $materials->insert($idA, MaterialType::TRANSLIT, null, null, null, $uploaderId, null, true, "Текст\nТранслита");
        $materials->insertRecording($idA, ['soprano_1', 'bass'], 'r1', 'ru1', $uploaderId, null);
        $materials->insertRecording($idA, ['tenor'], 'r2', 'ru2', $uploaderId, null);

        $presenter = new AdminChatSongListPresenter($songs, $materials, $texts);
        $chunks = $presenter->buildMessageChunks();
        $body = implode("\n", $chunks);

        self::assertStringContainsString('Аве Мария', $body);
        self::assertStringContainsString('ноты ✅', $body);
        self::assertStringContainsString('транслит ✅', $body);
        self::assertStringContainsString('записи: 2 шт.', $body);
        self::assertStringContainsString('Сопрано 1', $body);
        self::assertStringContainsString('Бас', $body);
        self::assertStringContainsString('Благослови', $body);
        self::assertStringContainsString('транслит ❌', $body);
        self::assertStringContainsString('записей нет', $body);
        self::assertStringContainsString('Гимн', $body);
    }

    public function testSongsEmptyList(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $presenter = new AdminChatSongListPresenter(
            new SongRepository($pdo),
            new MaterialRepository($pdo),
            $texts,
        );
        $chunks = $presenter->buildMessageChunks();
        self::assertSame([$texts->get('admin_chat.songs_empty')], $chunks);
    }

    public function testLongSongsListSplitsIntoChunks(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $songs = new SongRepository($pdo);
        for ($i = 0; $i < 200; $i++) {
            $songs->create('Песня ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $presenter = new AdminChatSongListPresenter($songs, new MaterialRepository($pdo), $texts);
        $chunks = $presenter->buildMessageChunks();
        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(TelegramMessageChunker::MAX_MESSAGE_LENGTH, mb_strlen($chunk));
        }
    }

    public function testMembersGroupingConductorOwnerAndUsername(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $conductorId = $users->create(91100, 'Надима', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $users->updateTelegramUsername($conductorId, 'nadima');
        $ownerId = $users->create(91101, 'Техспец', null, UserRole::OWNER, UserStatus::ACTIVE);
        $users->setVoicePart($ownerId, 'alto_1');
        $users->updateTelegramUsername($ownerId, 'owner_k');
        $users->create(91102, 'Сопран', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(91103, 'Без', null, UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(91104, 'Ждёт', 'tenor', UserRole::MEMBER, UserStatus::PENDING);
        $users->create(91105, 'Блок', 'tenor', UserRole::MEMBER, UserStatus::BLOCKED);

        $presenter = new AdminChatMemberListPresenter($users, $texts, new UserRolePresenter($texts));
        $body = implode("\n", $presenter->buildMessageChunks());

        self::assertStringContainsString('Дирижёр: Надима @nadima', $body);
        self::assertStringContainsString('Сопрано 1:', $body);
        self::assertStringContainsString('• Сопран', $body);
        self::assertStringContainsString('техспец', mb_strtolower($body));
        self::assertStringContainsString('@owner_k', $body);
        self::assertStringContainsString('Без партии:', $body);
        self::assertStringNotContainsString('Ждёт', $body);
        self::assertStringContainsString('ожидают регистрации — 1', $body);
        self::assertStringContainsString('заблокированы — 1', $body);
    }

    public function testUnauthorizedUserGetsNoSongsReply(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $access = new AccessControl($this->testConfig(), null);

        $bot = Nutgram::fake();
        AdminChatCommandHandler::register($bot, $texts, $access, $users, $songs, $materials);
        $bot->willReceivePartial(['id' => self::ADMIN_CHAT_ID, 'type' => 'supergroup']);
        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        $bot->hearText('/songs')
            ->reply();
        $bot->assertCalled('sendMessage', 0);
    }

    public function testHelperCanRequestSongsInAdminChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::HELPER_TG, 'Помощник', 'alto_1', UserRole::ADMIN, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $songs->create('Одна');
        $materials = new MaterialRepository($pdo);
        $access = new AccessControl($this->testConfig(), null);

        $bot = Nutgram::fake();
        AdminChatCommandHandler::register($bot, $texts, $access, $users, $songs, $materials);
        $bot->willReceivePartial(['id' => self::ADMIN_CHAT_ID, 'type' => 'supergroup']);
        $bot->setCommonUser($this->tgUser(self::HELPER_TG));
        $bot->hearCommand('songs')->reply();
        $bot->assertCalled('sendMessage');
    }

    private function tgUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'Test';

        return $user;
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