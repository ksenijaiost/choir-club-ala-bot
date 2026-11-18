<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repositories\UserOpenMenuRepository;
use App\Telegram\InteractiveMenuService;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\MaterialType;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AdminChatCommandHandler;
use App\Handlers\MembersAdminHandler;
use App\Repositories\BlockEventRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserRepository;
use App\Services\MemberBlockService;
use App\Services\AdminChatMemberListPresenter;
use App\Services\AdminChatSongListPresenter;
use App\Services\AccessControl;
use App\Services\UserRolePresenter;
use App\Text\TextRepository;
use App\Telegram\TelegramMessageChunker;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AdminChatCommandsTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;
    private const ADMIN_TG = 91001;
    private const HELPER_TG = 91002;
    private const MEMBER_TG = 91003;
    private const CONDUCTOR_TG = 91004;
    private const FOREIGN_CHAT_ID = -100_9002;

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
        self::assertStringContainsString('<b>Аве Мария</b>', $body);
        self::assertStringContainsString('• ноты: 1', $body);
        self::assertStringContainsString('• заметки: есть', $body);
        self::assertStringContainsString('• записи: Сопрано 1 + Бас; Тенор', $body);
        self::assertStringContainsString('<b>Благослови</b>', $body);
        self::assertStringContainsString('• заметки: нет', $body);
        self::assertStringContainsString('• записи: нет', $body);
        self::assertStringContainsString('Гимн', $body);
    }

    public function testSongsListShowsMultipleNotesCount(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $uploaderId = $users->create(self::ADMIN_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $songId = $songs->create('Две страницы');

        $materials->insert($songId, MaterialType::NOTES, null, 'f1', 'u1', $uploaderId, null, true);
        $materials->insert($songId, MaterialType::NOTES, null, 'f2', 'u2', $uploaderId, null, true);

        $presenter = new AdminChatSongListPresenter($songs, $materials, $texts);
        $body = implode("\n", $presenter->buildMessageChunks());

        self::assertStringContainsString('Две страницы', $body);
        self::assertStringContainsString('• ноты: 2', $body);
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

        self::assertStringContainsString('Дирижёр:', $body);
        self::assertStringContainsString('tg://user?id=91100', $body);
        self::assertStringContainsString('https://t.me/nadima', $body);
        self::assertStringContainsString('Сопрано 1:', $body);
        self::assertStringContainsString('• ', $body);
        self::assertStringContainsString('tg://user?id=91102', $body);
        self::assertStringContainsString('https://t.me/owner_k', $body);
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
        $this->registerAdminChatHandlers($bot, $texts, $access, $users, $songs, $materials, $pdo);
        $this->hearAdminCommand($bot, self::MEMBER_TG, '/songs')
            ->reply()
            ->assertNoReply();
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
        $this->registerAdminChatHandlers($bot, $texts, $access, $users, $songs, $materials, $pdo);
        $this->hearAdminCommand($bot, self::HELPER_TG, '/songs')
            ->reply()
            ->assertRaw(function (Request $request): bool {
                if (!str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                    return false;
                }

                $data = json_decode((string) $request->getBody(), true);

                return str_contains((string) ($data['text'] ?? ''), 'Одна')
                    && (int) ($data['chat_id'] ?? 0) === self::ADMIN_CHAT_ID;
            }, message: 'Expected songs list in admin chat containing song title');
    }

    public function testSongsCommandIgnoredOutsideAdminChat(): void
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
        $this->registerAdminChatHandlers($bot, $texts, $access, $users, $songs, $materials, $pdo);

        $bot->setCommonUser($this->tgUser(self::HELPER_TG));
        $bot->setCommonChat($this->makeChat(self::FOREIGN_CHAT_ID, ChatType::SUPERGROUP));
        $bot->hearText('/songs')
            ->reply()
            ->assertNoReply();

        $bot = Nutgram::fake();
        $this->registerAdminChatHandlers($bot, $texts, $access, $users, $songs, $materials, $pdo);
        $bot->setCommonUser($this->tgUser(self::HELPER_TG));
        $bot->setCommonChat($this->makeChat(self::HELPER_TG, ChatType::PRIVATE));
        $bot->hearText('/songs')
            ->reply()
            ->assertNoReply();
    }

    public function testConductorGetsMembersListInAdminChat(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::CONDUCTOR_TG, 'Дирижёр', null, UserRole::CONDUCTOR, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Хорист', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $access = new AccessControl($this->testConfig(), null);

        $bot = Nutgram::fake();
        $this->registerAdminChatHandlers($bot, $texts, $access, $users, $songs, $materials, $pdo);
        $header = $texts->get('admin_chat.members_header');

        $this->hearAdminCommand($bot, self::CONDUCTOR_TG, '/members')
            ->reply()
            ->assertRaw(function (Request $request) use ($header): bool {
                if (!str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                    return false;
                }

                $data = json_decode((string) $request->getBody(), true);

                return str_contains((string) ($data['text'] ?? ''), $header)
                    && (int) ($data['chat_id'] ?? 0) === self::ADMIN_CHAT_ID;
            }, message: 'Expected members list in admin chat');
    }

    public function testMemberGetsNoMembersListInAdminChat(): void
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
        $this->registerAdminChatHandlers($bot, $texts, $access, $users, $songs, $materials, $pdo);

        $this->hearAdminCommand($bot, self::MEMBER_TG, '/members')
            ->reply()
            ->assertNoReply();
    }

    private function registerAdminChatHandlers(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        UserRepository $users,
        SongRepository $songs,
        MaterialRepository $materials,
        \PDO $pdo,
    ): void {
        $handlerDeps = \App\Tests\Support\MaterialsTestDeps::build(
            $this->testConfig(),
            $users,
            new \App\Repositories\UserFlowRepository($pdo),
            $songs,
            $materials,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
        AdminChatCommandHandler::register($bot, $texts, $access, $users, $songs, $materials, $handlerDeps);
        MembersAdminHandler::register(
            $bot,
            $texts,
            $users,
            $access,
            new MemberBlockService($users, new BlockEventRepository($pdo)),
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
    }

    private function hearAdminCommand(Nutgram $bot, int $telegramId, string $command): mixed
    {
        $bot->setCommonUser($this->tgUser($telegramId));
        $bot->setCommonChat($this->makeChat(self::ADMIN_CHAT_ID, ChatType::SUPERGROUP));

        return $bot->hearText($command);
    }

    private function makeChat(int $id, ChatType $type): Chat
    {
        $chat = new Chat();
        $chat->id = $id;
        $chat->type = $type;

        return $chat;
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