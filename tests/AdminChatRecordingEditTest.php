<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Handlers\AdminChatCommandHandler;
use App\Handlers\MaterialsCallbackHandler;
use App\Handlers\MaterialsRecordingEditHandler;
use App\Repositories\BlockEventRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\MemberBlockService;
use App\Telegram\InteractiveMenuService;
use App\Tests\Support\MaterialsTestDeps;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AdminChatRecordingEditTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;
    private const UPLOADER_TG = 92001;
    private const MEMBER_TG = 92002;
    private const OTHER_TG = 92003;

    public function testEditCommandOpensSongPickerInAdminChat(): void
    {
        $deps = $this->bootstrap();
        $texts = $deps['texts'];
        $deps['songs']->create('Аллилуия');

        $bot = $this->adminBot($deps, self::UPLOADER_TG);
        $bot->hearText('/edit')->reply();

        $body = $this->lastSendMessageText($bot);
        self::assertStringContainsString($texts->get('materials.admin_edit_pick_song'), $body);
        self::assertStringContainsString($texts->get('materials.admin_edit_search_hint'), $body);
        $state = $deps['flows']->get(self::UPLOADER_TG);
        self::assertNotNull($state);
        self::assertSame(MaterialsRecordingEditHandler::FLOW, $state['flow']);
        self::assertTrue((bool) ($state['context']['admin_chat_edit'] ?? false));
    }

    public function testMemberWithoutRightsGetsForbiddenOnEdit(): void
    {
        $deps = $this->bootstrap();
        $texts = $deps['texts'];

        $bot = $this->adminBot($deps, self::MEMBER_TG);
        $bot->hearText('/edit')->reply();

        $bot->assertReplyText($texts->get('materials.upload_forbidden'));
        self::assertNull($deps['flows']->get(self::MEMBER_TG));
    }

    public function testAdminChatFullPartChangeFlow(): void
    {
        $deps = $this->bootstrap();
        $uploader = $deps['users']->findByTelegramId(self::UPLOADER_TG);
        self::assertNotNull($uploader);
        $songId = $deps['songs']->create('Тест');
        $materialId = $deps['materials']->insertRecording(
            $songId,
            ['tenor'],
            'r1',
            'u1',
            (int) $uploader['id'],
            null,
        );

        $bot = $this->adminBot($deps, self::UPLOADER_TG);
        MaterialsRecordingEditHandler::startAdminChat($bot, $deps['handlerDeps'], self::UPLOADER_TG, self::ADMIN_CHAT_ID);

        $this->handleAdminChatCallback(
            $bot,
            $deps,
            $uploader,
            MaterialsRecordingEditHandler::ADMIN_SONG_PICK_PREFIX . $songId,
        );

        $this->handleAdminChatCallback($bot, $deps, $uploader, 'mat:mg:erec:p:' . $materialId);

        $this->handleAdminChatCallback($bot, $deps, $uploader, 'mat:mg:erec:tk:' . $materialId . ':tenor');
        $this->handleAdminChatCallback($bot, $deps, $uploader, 'mat:mg:erec:tk:' . $materialId . ':baritone');
        $this->handleAdminChatCallback($bot, $deps, $uploader, 'mat:mg:erec:sv:' . $materialId);

        self::assertSame(['baritone'], $deps['materials']->listVoiceParts($materialId));
        self::assertGreaterThanOrEqual(1, $this->countMethodCalls($bot, 'editMessageText'));
    }

    public function testForeignUserCannotUseAnotherUsersAdminMenu(): void
    {
        $deps = $this->bootstrap();
        $uploader = $deps['users']->findByTelegramId(self::UPLOADER_TG);
        self::assertNotNull($uploader);
        $other = $deps['users']->findByTelegramId(self::OTHER_TG);
        self::assertNotNull($other);
        $songId = $deps['songs']->create('Чужое меню');

        $bot = $this->adminBot($deps, self::UPLOADER_TG);
        MaterialsRecordingEditHandler::startAdminChat($bot, $deps['handlerDeps'], self::UPLOADER_TG, self::ADMIN_CHAT_ID);

        $bot = $this->adminBot($deps, self::OTHER_TG);
        $this->handleAdminChatCallback(
            $bot,
            $deps,
            $other,
            MaterialsRecordingEditHandler::ADMIN_SONG_PICK_PREFIX . $songId,
        );

        self::assertTrue($this->hasAnswerCallbackAlert($bot, $deps['texts']->get('admin_chat.menu_other_user')));
    }

    public function testProfileShowsBellIconsBySetting(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $memberId = $users->create(93001, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $users->setMaterialNotificationsEnabled($memberId, true);
        $on = $users->findById($memberId);
        self::assertNotNull($on);
        self::assertStringContainsString('🔔', $texts->get('profile.material_notify_on'));
        self::assertStringContainsString('🔔', $texts->get('profile.notify_line_on'));

        $users->setMaterialNotificationsEnabled($memberId, false);
        $off = $users->findById($memberId);
        self::assertNotNull($off);
        self::assertStringContainsString('🔕', $texts->get('profile.material_notify_off'));
        self::assertStringContainsString('🔕', $texts->get('profile.notify_line_off'));
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrap(): array
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $users = new UserRepository($pdo);
        $users->create(self::UPLOADER_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $users->create(self::MEMBER_TG, 'Участник', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $users->create(self::OTHER_TG, 'Другой', 'alto_1', UserRole::ADMIN, UserStatus::ACTIVE);
        $flows = new UserFlowRepository($pdo);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $handlerDeps = MaterialsTestDeps::build(
            $config,
            $users,
            $flows,
            $songs,
            $materials,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );
        $access = new AccessControl($config);

        return [
            'texts' => $texts,
            'users' => $users,
            'flows' => $flows,
            'songs' => $songs,
            'materials' => $materials,
            'handlerDeps' => $handlerDeps,
            'access' => $access,
        ];
    }

    /**
     * @param array<string, mixed> $deps
     */
    private function adminBot(array $deps, int $telegramId): Nutgram
    {
        $config = $this->testConfig();
        $texts = $deps['texts'];
        $users = $deps['users'];
        $songs = $deps['songs'];
        $materials = $deps['materials'];
        $handlerDeps = $deps['handlerDeps'];
        $access = $deps['access'];

        $bot = Nutgram::fake();
        AdminChatCommandHandler::register($bot, $texts, $access, $users, $songs, $materials, $handlerDeps);
        $bot->setCommonUser($this->tgUser($telegramId));
        $bot->setCommonChat($this->makeChat(self::ADMIN_CHAT_ID, ChatType::SUPERGROUP));

        return $bot;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function handleAdminChatCallback(Nutgram $bot, array $deps, array $actor, string $data): void
    {
        $telegramId = (int) $actor['telegram_id'];
        $bot->setCommonUser($this->tgUser($telegramId));
        $this->attachAdminChatCallbackUpdate($bot, $telegramId, $data);

        MaterialsCallbackHandler::handle($bot, $deps['handlerDeps'], $deps['access'], $actor, $data);
    }

    private function attachAdminChatCallbackUpdate(Nutgram $bot, int $telegramId, string $data): void
    {
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'data' => $data,
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'T'],
            'message' => [
                'message_id' => 99,
                'date' => 1_703_892_479,
                'chat' => ['id' => self::ADMIN_CHAT_ID, 'type' => ChatType::SUPERGROUP->value],
            ],
        ]);

        $mode = $bot->getContainer()->get(RunningMode::class);
        $modeRef = new \ReflectionObject($mode);
        $updateProp = $modeRef->getProperty('update');
        $updateProp->setAccessible(true);
        $update = $updateProp->getValue($mode);
        self::assertInstanceOf(Update::class, $update);

        $botRef = new \ReflectionObject($bot);
        $botUpdate = $botRef->getProperty('update');
        $botUpdate->setAccessible(true);
        $botUpdate->setValue($bot, $update);
    }

    private function countMethodCalls(Nutgram $bot, string $method): int
    {
        $count = 0;
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_contains((string) $entry['request']->getUri(), $method)) {
                $count++;
            }
        }

        return $count;
    }

    private function lastSendMessageText(Nutgram $bot): string
    {
        foreach (array_reverse($bot->getRequestHistory()) as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $body = json_decode((string) $entry['request']->getBody(), true);

            return (string) ($body['text'] ?? '');
        }

        self::fail('Expected sendMessage');

        return '';
    }

    private function hasAnswerCallbackAlert(Nutgram $bot, string $text): bool
    {
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'answerCallbackQuery')) {
                continue;
            }
            $data = json_decode((string) $entry['request']->getBody(), true);

            return ($data['text'] ?? '') === $text;
        }

        return false;
    }

    private function tgUser(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->first_name = 'T';

        return $user;
    }

    private function makeChat(int $id, ChatType $type): Chat
    {
        $chat = new Chat();
        $chat->id = $id;
        $chat->type = $type;

        return $chat;
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