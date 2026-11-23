<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\HandlerRegistry;
use App\Repositories\UserRepository;
use App\Telegram\InlineMenuHelper;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class CallbackQueryHandlerRoutingTest extends TestCase
{
    private const CONDUCTOR_TG = 97_001;

    private const MEMBER_TG = 97_002;

    /**
     * @var list<string>
     */
    private const ROUTED_PREFIXES = [
        'err:',
        'ab:',
        'ach:',
        'snd:',
        'mat:',
        'bc:',
        'pay:',
        'pa:',
        'atd:',
        'att:',
        'chk:',
        'sch:',
        'hst:',
        'prf:',
        'bl:',
        'oc:',
        'hp:',
        'rp:',
        'rr:',
        'prg:',
    ];

    /**
     * @var list<string>
     */
    private const ROUTED_EXACT = [
        InlineMenuHelper::CLOSE_CALLBACK,
    ];

    public function testPrgHomeOpensViaCallbackQueryHandler(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');

        $bot = $this->fakePrivateBot(self::CONDUCTOR_TG);
        HandlerRegistry::register($bot, $texts, $this->testConfig(), $pdo);

        $bot->hearCallbackQueryData('prg:home')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('editMessageText');
    }

    public function testPaPickerOpensViaCallbackQueryHandler(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        for ($i = 0; $i < 3; $i++) {
            $users->create(97100 + $i, 'Участник ' . $i, 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        }

        $bot = $this->fakePrivateBot(self::CONDUCTOR_TG);
        HandlerRegistry::register($bot, $texts, $this->testConfig(), $pdo);

        $bot->hearCallbackQueryData('pa:p:0')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('editMessageText');
    }

    public function testPaMemberCardOpensViaCallbackQueryHandler(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->upsertConductor(self::CONDUCTOR_TG, 'Дирижёр');
        $uid = $users->create(self::MEMBER_TG, 'Аня', 'alto_1', UserRole::MEMBER, UserStatus::ACTIVE);

        $bot = $this->fakePrivateBot(self::CONDUCTOR_TG);
        HandlerRegistry::register($bot, $texts, $this->testConfig(), $pdo);

        $bot->hearCallbackQueryData('pa:u:' . $uid)
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('editMessageText');
    }

    public function testPaDeniedForMember(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = $this->pdo();
        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Участник', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);

        $bot = $this->fakePrivateBot(self::MEMBER_TG);
        HandlerRegistry::register($bot, $texts, $this->testConfig(), $pdo);

        $alert = $texts->get('admin.only_conductor_or_owner');

        $bot->hearCallbackQueryData('pa:p:0')
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery');
        $foundAlert = false;
        foreach ($bot->getRequestHistory() as $entry) {
            $body = (string) $entry['request']->getBody();
            if (str_contains($body, $alert)) {
                $foundAlert = true;
            }
        }
        self::assertTrue($foundAlert);
    }

    public function testStaticCallbackDataInSourceHasHandlerRoute(): void
    {
        $callbacks = $this->collectStaticCallbackDataFromSource();
        self::assertNotEmpty($callbacks);

        $unrouted = [];
        foreach ($callbacks as $data) {
            if (!$this->isCallbackRouted($data)) {
                $unrouted[] = $data;
            }
        }

        self::assertSame(
            [],
            $unrouted,
            'Callbacks without route in CallbackQueryHandler: ' . implode(', ', $unrouted),
        );
    }

    /**
     * @return list<string>
     */
    private function collectStaticCallbackDataFromSource(): array
    {
        $srcDir = dirname(__DIR__) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
        $phpFiles = new RegexIterator($iterator, '/\.php$/');

        $found = [];
        foreach ($phpFiles as $file) {
            $content = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/callback_data:\s*'([^'$][^']*)'/", $content, $matches)) {
                foreach ($matches[1] as $data) {
                    $found[$data] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function isCallbackRouted(string $data): bool
    {
        if (in_array($data, self::ROUTED_EXACT, true)) {
            return true;
        }

        foreach (self::ROUTED_PREFIXES as $prefix) {
            if (str_starts_with($data, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function pdo(): \PDO
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        return $pdo;
    }

    private function fakePrivateBot(int $telegramId): Nutgram
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

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: (string) self::CONDUCTOR_TG,
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