<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Repositories\ErrorReportRepository;
use App\Repositories\UserActionRepository;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\ErrorReportService;
use App\Services\UserActionRecorder;
use App\Services\AdminChatResolver;
use App\Repositories\BotSettingsRepository;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class UserActionTest extends TestCase
{
    private const MEMBER_TG = 94_001;
    private const ADMIN_CHAT_ID = -100_9401;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Connection::make($this->testConfig());
        (new Migrator($this->pdo, 'sqlite'))->migrate();
    }

    public function testRepositoryKeepsLastTenActions(): void
    {
        $repo = new UserActionRepository($this->pdo);

        for ($i = 1; $i <= 12; $i++) {
            $repo->add(self::MEMBER_TG, 'message', 'действие ' . $i);
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_actions WHERE telegram_id = ?');
        $stmt->execute([self::MEMBER_TG]);
        self::assertSame(10, (int) $stmt->fetchColumn());

        $recent = $repo->recent(self::MEMBER_TG);
        self::assertCount(10, $recent);
        self::assertSame('действие 3', $recent[0]['action']);
        self::assertSame('действие 12', $recent[9]['action']);
    }

    public function testFreeTextStoredAsLengthOnly(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $bot = $this->privateBot();
        HandlerRegistry::register($bot, $texts, $config, $this->pdo);
        $bot->hearText('Очень длинная тайна пользователя')->reply();

        $rows = (new UserActionRepository($this->pdo))->recent(self::MEMBER_TG);
        $masked = array_values(array_filter(
            $rows,
            static fn (array $row): bool => preg_match('/^текст \(\d+ симв\.\)$/', (string) ($row['action'] ?? '')) === 1,
        ));
        self::assertNotEmpty($masked);
    }

    public function testMenuButtonAndCallbackStoredReadable(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $repo = new UserActionRepository($this->pdo);
        $bot = $this->privateBot();
        HandlerRegistry::register($bot, $texts, $config, $this->pdo);

        $bot->hearText($texts->get('menu.find_song'))->reply();

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'from' => ['id' => self::MEMBER_TG, 'is_bot' => false, 'first_name' => 'T'],
            'message' => [
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => self::MEMBER_TG, 'type' => 'private'],
                'text' => 'x',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Кнопка X', 'callback_data' => 'demo:cb'],
                        ],
                    ],
                ],
            ],
            'data' => 'demo:cb',
        ])->reply();

        $recent = $repo->recent(self::MEMBER_TG);
        $labels = array_map(static fn (array $row): string => (string) ($row['action'] ?? ''), $recent);
        self::assertContains($texts->get('menu.find_song'), $labels);
        self::assertContains('Кнопка X', $labels);
    }

    public function testHelpReportContainsActionsAndRecentErrors(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $actions = new UserActionRepository($this->pdo);
        $actions->add(self::MEMBER_TG, 'message', '/start');
        $actions->add(self::MEMBER_TG, 'message', $texts->get('menu.schedule'));

        $reports = new ErrorReportRepository($this->pdo);
        $oldId = $reports->create([
            'telegram_id' => self::MEMBER_TG,
            'chat_id' => self::MEMBER_TG,
            'update_id' => 1,
            'update_type' => 'message',
            'action' => 'qa:err',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'boom',
        ]);

        $bot = $this->privateBot();
        HandlerRegistry::register($bot, $texts, $config, $this->pdo);

        $bot->hearText('/help')->reply();
        $bot->hearText('секретный комментарий')->reply();

        self::assertTrue($this->adminMessageContains($bot, 'Последние действия:'));
        self::assertTrue($this->adminMessageContains($bot, '/start'));
        self::assertTrue($this->adminMessageContains($bot, 'Ошибки за 24 ч:'));
        self::assertTrue($this->adminMessageContains($bot, 'RuntimeException'));
        $adminText = $this->adminMessageText($bot);
        self::assertNotNull($adminText);
        self::assertStringContainsString('Действие: /help', $adminText);
        self::assertDoesNotMatchRegularExpression('/Последние действия:\n(?:.*\n)*?.*\/help/u', $adminText);

        $rows = $actions->recent(self::MEMBER_TG);
        self::assertLessThanOrEqual(10, count($rows));
        self::assertNotNull($reports->findById($oldId));
    }

    public function testReportWithoutContextOmitsOptionalBlocks(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();

        $reports = new ErrorReportRepository($this->pdo);
        $reportId = $reports->create([
            'telegram_id' => self::MEMBER_TG,
            'chat_id' => self::MEMBER_TG,
            'update_id' => 99,
            'update_type' => 'message',
            'action' => '/start',
            'exception_class' => null,
            'exception_message' => null,
        ]);

        $service = new ErrorReportService(
            $reports,
            new UserActionRepository($this->pdo),
            new UserRepository($this->pdo),
            $texts,
            $config,
            new AdminChatResolver(new BotSettingsRepository($this->pdo), $config),
        );

        $report = $reports->findById($reportId);
        self::assertNotNull($report);
        $ref = new \ReflectionMethod($service, 'buildAdminHtml');
        $html = $ref->invoke($service, $report, null, null, null);

        self::assertStringNotContainsString('Последние действия:', $html);
        self::assertStringNotContainsString('Ошибки за 24 ч:', $html);
    }

    public function testLongReportStaysWithinTelegramLimit(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $actions = new UserActionRepository($this->pdo);
        for ($i = 0; $i < 10; $i++) {
            $actions->add(self::MEMBER_TG, 'message', str_repeat('×', 180));
        }

        $reports = new ErrorReportRepository($this->pdo);
        for ($i = 0; $i < 3; $i++) {
            $reports->create([
                'telegram_id' => self::MEMBER_TG,
                'chat_id' => self::MEMBER_TG,
                'update_id' => $i,
                'update_type' => 'message',
                'action' => 'x',
                'exception_class' => 'RuntimeException',
                'exception_message' => str_repeat('!', 200),
            ]);
        }

        $reportId = $reports->create([
            'telegram_id' => self::MEMBER_TG,
            'chat_id' => self::MEMBER_TG,
            'update_id' => 100,
            'update_type' => 'message',
            'action' => '/help',
            'exception_class' => null,
            'exception_message' => null,
        ]);

        $users = new UserRepository($this->pdo);
        $users->create(self::MEMBER_TG, str_repeat('Имя', 30), 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $service = new ErrorReportService(
            $reports,
            $actions,
            $users,
            $texts,
            $config,
            new AdminChatResolver(new BotSettingsRepository($this->pdo), $config),
        );

        $report = $reports->findById($reportId);
        self::assertNotNull($report);
        $ref = new \ReflectionMethod($service, 'buildAdminHtml');
        $html = $ref->invoke($service, $report, $users->findByTelegramId(self::MEMBER_TG), null, str_repeat('комментарий ', 200));

        self::assertLessThanOrEqual(4096, mb_strlen($html));
    }

    private function adminMessageText(Nutgram $bot): ?string
    {
        foreach ($this->historyEntries($bot) as $request) {
            if ($request->getUri()->getPath() !== 'sendMessage') {
                continue;
            }
            $body = json_decode((string) $request->getBody(), true);
            if ((int) ($body['chat_id'] ?? 0) !== self::ADMIN_CHAT_ID) {
                continue;
            }

            return (string) ($body['text'] ?? '');
        }

        return null;
    }

    private function adminMessageContains(Nutgram $bot, string $needle): bool
    {
        foreach ($this->historyEntries($bot) as $request) {
            if ($request->getUri()->getPath() !== 'sendMessage') {
                continue;
            }
            $body = json_decode((string) $request->getBody(), true);
            if ((int) ($body['chat_id'] ?? 0) !== self::ADMIN_CHAT_ID) {
                continue;
            }
            if (str_contains((string) ($body['text'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<\Psr\Http\Message\RequestInterface>
     */
    private function historyEntries(Nutgram $bot): array
    {
        if (!$bot instanceof \SergiX44\Nutgram\Testing\FakeNutgram) {
            return [];
        }

        $ref = new \ReflectionProperty(\SergiX44\Nutgram\Testing\FakeNutgram::class, 'testingHistory');
        $ref->setAccessible(true);
        $history = $ref->getValue($bot);
        $requests = [];
        foreach ($history as $item) {
            [$request] = array_values($item);
            if ($request instanceof \Psr\Http\Message\RequestInterface) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    private function privateBot(): Nutgram
    {
        $bot = Nutgram::fake();
        $user = new User();
        $user->id = self::MEMBER_TG;
        $user->is_bot = false;
        $user->first_name = 'Тест';
        $bot->setCommonUser($user);

        $chat = new Chat();
        $chat->id = self::MEMBER_TG;
        $chat->type = ChatType::PRIVATE;
        $bot->setCommonChat($chat);

        return $bot;
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