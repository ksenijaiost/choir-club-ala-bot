<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Repositories\BotSettingsRepository;
use App\Repositories\ErrorReportRepository;
use App\Repositories\UserRepository;
use App\Services\AdminChatResolver;
use App\Services\ErrorReportService;
use App\Telegram\NutgramErrorHooks;
use App\Text\TextRepository;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

final class ErrorReportTest extends TestCase
{
    private const MEMBER_TG = 91_001;
    private const ADMIN_CHAT_ID = -100_9901;

    public function testExceptionCreatesReportAndShowsButton(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $config = $this->testConfig();
        $errorReports = $this->errorReports($pdo, $texts, $config);

        $bot = $this->privateBot(self::MEMBER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo, $errorReports);
        NutgramErrorHooks::register($bot, $config, $texts, $errorReports);

        $bot->onCallbackQuery(function (Nutgram $bot): void {
            if (($bot->callbackQuery()?->data ?? '') === 'qa:err') {
                throw new \RuntimeException('test failure');
            }
        });

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'from' => ['id' => self::MEMBER_TG, 'is_bot' => false, 'first_name' => 'Участник'],
            'message' => [
                'message_id' => 10,
                'date' => time(),
                'chat' => ['id' => self::MEMBER_TG, 'type' => 'private'],
                'text' => 'menu',
            ],
            'data' => 'qa:err',
        ])->reply();

        $bot->assertCalled('sendMessage');
        $this->assertLastSendContains($bot, $texts->get('errors.report_button'));

        $repo = new ErrorReportRepository($pdo);
        $stmt = $pdo->query('SELECT * FROM error_reports ORDER BY id DESC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        self::assertIsArray($row);
        self::assertSame('RuntimeException', $row['exception_class']);
        self::assertNull($row['reported_at']);
    }

    public function testReportButtonSendsAdminMessage(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Ксюша', 'soprano_1', UserRole::MEMBER, UserStatus::ACTIVE);
        $member = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($member);
        $users->updateTelegramUsername((int) $member['id'], 'ksyu');

        $reports = new ErrorReportRepository($pdo);
        $reportId = $reports->create([
            'telegram_id' => self::MEMBER_TG,
            'chat_id' => self::MEMBER_TG,
            'update_id' => 42,
            'update_type' => 'callback_query',
            'action' => 'qa:err',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'test failure',
        ]);

        $bot = $this->privateBot(self::MEMBER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '2',
            'from' => ['id' => self::MEMBER_TG, 'is_bot' => false, 'first_name' => 'Ксюша', 'username' => 'ksyu'],
            'message' => [
                'message_id' => 11,
                'date' => time(),
                'chat' => ['id' => self::MEMBER_TG, 'type' => 'private'],
                'text' => 'menu',
            ],
            'data' => 'err:rep:' . $reportId,
        ])->reply();

        $bot->hearText('Нажала на кнопку расписания')
            ->reply();

        $this->assertAdminSendContains($bot, 'update_id: 42');
        $this->assertAdminSendContains($bot, 'tg://user?id=' . self::MEMBER_TG);
        $this->assertAdminSendContains($bot, 'RuntimeException');

        $row = $reports->findById($reportId);
        self::assertNotNull($row['reported_at'] ?? null);
    }

    public function testSecondReportClickShowsAlreadySent(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $reports = new ErrorReportRepository($pdo);
        $reportId = $reports->create([
            'telegram_id' => self::MEMBER_TG,
            'chat_id' => self::MEMBER_TG,
            'update_id' => 1,
            'update_type' => 'message',
            'action' => '/start',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'x',
        ]);
        $reports->markReported($reportId);

        $bot = $this->privateBot(self::MEMBER_TG);
        HandlerRegistry::register($bot, $texts, $this->testConfig(), $pdo);

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '3',
            'from' => ['id' => self::MEMBER_TG, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 12,
                'date' => time(),
                'chat' => ['id' => self::MEMBER_TG, 'type' => 'private'],
                'text' => 'x',
            ],
            'data' => 'err:rep:' . $reportId,
        ])->reply();

        $bot->assertCalled('answerCallbackQuery');
        $this->assertAnswerAlertContains($bot, $texts->get('errors.report_already_sent'));
    }

    public function testHelpCommandSendsReportWithoutErrorBlock(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $bot = $this->privateBot(self::MEMBER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->hearText('/help')->reply();
        $bot->hearText('Бот не отвечает на кнопку')->reply();

        $this->assertAdminSendContains($bot, '/help');
        $this->assertAdminSendNotContains($bot, 'Ошибка:');
    }

    public function testRateLimitBlocksAdditionalReports(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $reports = new ErrorReportRepository($pdo);
        for ($i = 0; $i < 5; $i++) {
            $id = $reports->create([
                'telegram_id' => self::MEMBER_TG,
                'chat_id' => self::MEMBER_TG,
                'update_id' => $i,
                'update_type' => 'message',
                'action' => '/help',
                'exception_class' => null,
                'exception_message' => null,
            ]);
            $reports->markReported($id);
        }

        $bot = $this->privateBot(self::MEMBER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo);
        $bot->hearText('/help')->reply();

        $this->assertLastSendContains($bot, $texts->get('errors.report_rate_limited'));
    }

    private function privateBot(int $telegramId): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = $telegramId;
        $user->is_bot = false;
        $user->first_name = 'Тест';
        $bot->setCommonUser($user);

        $chat = new Chat();
        $chat->id = $telegramId;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        return $bot;
    }

    private function assertLastSendContains(Nutgram $bot, string $needle): void
    {
        self::assertTrue($this->historyContains($bot, 'sendMessage', self::MEMBER_TG, $needle));
    }

    private function assertAdminSendContains(Nutgram $bot, string $needle): void
    {
        self::assertTrue($this->historyContains($bot, 'sendMessage', self::ADMIN_CHAT_ID, $needle));
    }

    private function assertAdminSendNotContains(Nutgram $bot, string $needle): void
    {
        foreach ($this->historyEntries($bot) as $request) {
            if ($request->getUri()->getPath() !== 'sendMessage') {
                continue;
            }
            $body = FakeNutgram::getActualData($request);
            if ((int) ($body['chat_id'] ?? 0) !== self::ADMIN_CHAT_ID) {
                continue;
            }
            self::assertStringNotContainsString($needle, (string) ($body['text'] ?? ''));
        }
    }

    private function assertAnswerAlertContains(Nutgram $bot, string $needle): void
    {
        foreach ($this->historyEntries($bot) as $request) {
            if ($request->getUri()->getPath() !== 'answerCallbackQuery') {
                continue;
            }
            $body = FakeNutgram::getActualData($request);
            if (str_contains((string) ($body['text'] ?? ''), $needle)) {
                return;
            }
        }

        self::fail('Expected alert containing: ' . $needle);
    }

    private function historyContains(Nutgram $bot, string $method, int $chatId, string $needle): bool
    {
        foreach ($this->historyEntries($bot) as $request) {
            if ($request->getUri()->getPath() !== $method) {
                continue;
            }
            $body = FakeNutgram::getActualData($request);
            if ((int) ($body['chat_id'] ?? 0) !== $chatId) {
                continue;
            }
            if (str_contains((string) ($body['text'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Request>
     */
    private function historyEntries(Nutgram $bot): array
    {
        if (!$bot instanceof FakeNutgram) {
            return [];
        }

        $ref = new \ReflectionProperty(FakeNutgram::class, 'testingHistory');
        $ref->setAccessible(true);
        $history = $ref->getValue($bot);
        $requests = [];
        foreach ($history as $item) {
            [$request] = array_values($item);
            if ($request instanceof Request) {
                $requests[] = $request;
            }
        }

        return $requests;
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