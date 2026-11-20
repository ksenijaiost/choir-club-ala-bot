<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AdminChatCommandHandler;
use App\Handlers\HandlerRegistry;
use App\Handlers\MaterialsRecordingEditHandler;
use App\Repositories\BotSettingsRepository;
use App\Repositories\ErrorReportRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\SongRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Services\ErrorReportService;
use App\Telegram\InteractiveMenuService;
use App\Telegram\NutgramFactory;
use App\Tests\Support\MaterialsTestDeps;
use App\Tests\Support\NutgramTestConfig;
use App\Text\TextRepository;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class BotCommandAtMentionTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;
    private const UPLOADER_TG = 95001;
    private const MEMBER_TG = 95002;

    public function testNutgramFactorySetsBotNameFromConfig(): void
    {
        $config = $this->testConfig(botUsername: 'My_Test_Bot');
        $nutgramConfig = NutgramFactory::configuration($config);

        self::assertSame('My_Test_Bot', $nutgramConfig->botName);
        self::assertFalse($nutgramConfig->enableHttp2);
    }

    public function testSongsWithBotMentionInAdminChat(): void
    {
        $deps = $this->adminDeps();
        $deps['songs']->create('Упоминание');

        $bot = NutgramTestConfig::fakeBot();
        $this->registerAdminCommands($bot, $deps);
        $this->hearInAdminChat($bot, self::UPLOADER_TG, '/songs@' . NutgramTestConfig::BOT_USERNAME)
            ->reply()
            ->assertRaw(function (Request $request): bool {
                if (!str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                    return false;
                }
                $data = json_decode((string) $request->getBody(), true);

                return str_contains((string) ($data['text'] ?? ''), 'Упоминание');
            }, message: 'Expected songs list when command includes @bot');
    }

    public function testSongsWithOtherBotMentionIgnored(): void
    {
        $deps = $this->adminDeps();
        $deps['songs']->create('Скрытая');

        $bot = NutgramTestConfig::fakeBot();
        $this->registerAdminCommands($bot, $deps);
        $this->hearInAdminChat($bot, self::UPLOADER_TG, '/songs@other_bot')
            ->reply()
            ->assertNoReply();
    }

    public function testEditWithLowercaseBotMention(): void
    {
        $deps = $this->adminDeps();

        $bot = NutgramTestConfig::fakeBot();
        $this->registerAdminCommands($bot, $deps);
        $this->hearInAdminChat($bot, self::UPLOADER_TG, '/edit@choir_club_ala_bot')
            ->reply();

        $state = $deps['flows']->get(self::UPLOADER_TG);
        self::assertNotNull($state);
        self::assertSame(MaterialsRecordingEditHandler::FLOW, $state['flow']);
        self::assertTrue((bool) ($state['context']['admin_chat_edit'] ?? false));
    }

    public function testPrivateHelpWithBotMention(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $errorReports = $this->errorReports($pdo, $texts, $config);

        $bot = NutgramTestConfig::fakeBot();
        HandlerRegistry::register($bot, $texts, $config, $pdo, $errorReports);

        $bot->setCommonUser($this->tgUser(self::MEMBER_TG));
        $bot->setCommonChat($this->makeChat(self::MEMBER_TG, ChatType::PRIVATE));
        $bot->hearText('/help@CHOIR_CLUB_ALA_bot')->reply();

        $bot->assertCalled('sendMessage');
        $state = (new UserFlowRepository($pdo))->get(self::MEMBER_TG);
        self::assertNotNull($state);
        self::assertSame(ErrorReportService::FLOW_HELP, $state['flow']);
    }

    /**
     * @return array{texts: TextRepository, users: UserRepository, songs: SongRepository, materials: MaterialRepository, flows: UserFlowRepository, access: AccessControl, handlerDeps: \App\Handlers\MaterialsDeps}
     */
    private function adminDeps(): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();
        $users = new UserRepository($pdo);
        $users->create(self::UPLOADER_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);
        $flows = new UserFlowRepository($pdo);
        $songs = new SongRepository($pdo);
        $materials = new MaterialRepository($pdo);
        $handlerDeps = MaterialsTestDeps::build(
            $this->testConfig(),
            $users,
            $flows,
            $songs,
            $materials,
            $texts,
            new InteractiveMenuService(new UserOpenMenuRepository($pdo)),
        );

        return [
            'texts' => $texts,
            'users' => $users,
            'songs' => $songs,
            'materials' => $materials,
            'flows' => $flows,
            'access' => new AccessControl($this->testConfig(), null),
            'handlerDeps' => $handlerDeps,
        ];
    }

    /**
     * @param array<string, mixed> $deps
     */
    private function registerAdminCommands(Nutgram $bot, array $deps): void
    {
        AdminChatCommandHandler::register(
            $bot,
            $deps['texts'],
            $deps['access'],
            $deps['users'],
            $deps['songs'],
            $deps['materials'],
            $deps['handlerDeps'],
        );
    }

    private function hearInAdminChat(Nutgram $bot, int $telegramId, string $command): mixed
    {
        $bot->setCommonUser($this->tgUser($telegramId));
        $bot->setCommonChat($this->makeChat(self::ADMIN_CHAT_ID, ChatType::SUPERGROUP));

        return $bot->hearText($command);
    }

    private function errorReports(\PDO $pdo, TextRepository $texts, Config $config): ErrorReportService
    {
        return new ErrorReportService(
            new ErrorReportRepository($pdo),
            new UserRepository($pdo),
            $texts,
            $config,
            new AdminChatResolver(new BotSettingsRepository($pdo), $config),
        );
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

    private function testConfig(string $botUsername = NutgramTestConfig::BOT_USERNAME): Config
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
            botUsername: $botUsername,
        );
    }
}