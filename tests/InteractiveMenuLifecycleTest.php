<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserOpenMenuRepository;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\InteractiveMenuService;
use App\Text\TextRepository;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Fake;
use SergiX44\Nutgram\RunningMode\RunningMode;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use SergiX44\Nutgram\Testing\FakeNutgram as FakeNutgramClass;

final class InteractiveMenuLifecycleTest extends TestCase
{
    private const USER_A = 80_001;
    private const USER_B = 80_002;
    private const CHAT_PRIVATE = 80_001;

    public function testCloseDeletesMenuMessage(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $menus = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $flows = new UserFlowRepository($pdo);
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        $bot = $this->fakePrivateBot(self::USER_A);
        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);
        InlineMenuHelper::sendInteractiveMenu($bot, $menus, self::USER_A, 'Меню 1', $keyboard);

        $this->hearCloseOnMessage($bot, 100);
        $this->bindHeardUpdate($bot);

        InlineMenuHelper::handleClose($bot, $flows, $menus, self::USER_A);

        $bot->assertCalled('deleteMessage', 1);
        $this->assertNoEditToClosedText($bot);
    }

    public function testCloseFallsBackToClearKeyboardWhenDeleteFails(): void
    {
        $menus = new InteractiveMenuService(new UserOpenMenuRepository(Connection::make($this->testConfig())));
        $bot = $this->fakePrivateBot(self::USER_A);
        $bot->willReceivePartial([], false);

        $menus->removeMessage($bot, self::CHAT_PRIVATE, 200);

        $bot->assertCalled('deleteMessage');
        $bot->assertCalled('editMessageReplyMarkup');
        $this->assertNoEditToClosedText($bot);
    }

    public function testNewMenuDismissesPreviousInSameChat(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $menus = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);

        $bot = $this->fakePrivateBot(self::USER_A);
        InlineMenuHelper::sendInteractiveMenu($bot, $menus, self::USER_A, 'Первое', $keyboard);
        InlineMenuHelper::sendInteractiveMenu($bot, $menus, self::USER_A, 'Второе', $keyboard);

        $bot->assertCalled('deleteMessage', 1);
    }

    public function testNewMenuDoesNotDismissOtherUsersMenu(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $repo = new UserOpenMenuRepository($pdo);
        $menus = new InteractiveMenuService($repo);
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);

        $botA = $this->fakePrivateBot(self::USER_A);
        InlineMenuHelper::sendInteractiveMenu($botA, $menus, self::USER_A, 'Меню A', $keyboard);
        self::assertNotNull($repo->find(self::USER_A, self::CHAT_PRIVATE));

        $botB = $this->fakePrivateBot(self::USER_B);
        InlineMenuHelper::sendInteractiveMenu($botB, $menus, self::USER_B, 'Меню B', $keyboard);

        $botB->assertCalled('deleteMessage', 0);
        self::assertNotNull($repo->find(self::USER_A, self::CHAT_PRIVATE));
        self::assertNotNull($repo->find(self::USER_B, self::USER_B));
    }

    public function testInPlaceEditDoesNotDeleteMenu(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $menus = new InteractiveMenuService(new UserOpenMenuRepository($pdo));
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $keyboard = InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $texts);

        $bot = $this->fakePrivateBot(self::USER_A);
        InlineMenuHelper::sendInteractiveMenu($bot, $menus, self::USER_A, 'Экран 1', $keyboard);

        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'from' => ['id' => self::USER_A, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 42,
                'date' => time(),
                'chat' => ['id' => self::CHAT_PRIVATE, 'type' => 'private'],
                'text' => 'Экран 1',
            ],
            'data' => 'ab:sec:member',
        ]);
        $this->bindHeardUpdate($bot);

        CallbackUi::editCurrentMessage($bot, 'Экран 2', $keyboard, $menus, self::USER_A);

        $bot->assertCalled('deleteMessage', 0);
        $bot->assertCalled('editMessageText', 1);
    }

    public function testMenuWithoutCloseButtonIsNotTracked(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $repo = new UserOpenMenuRepository($pdo);
        $menus = new InteractiveMenuService($repo);
        $plainKeyboard = InlineKeyboardMarkup::make()->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Принять', callback_data: 'rr:a:1'),
        );

        $bot = $this->fakePrivateBot(self::USER_A);
        InlineMenuHelper::sendInteractiveMenu($bot, $menus, self::USER_A, 'Заявка', $plainKeyboard);

        $bot->assertCalled('deleteMessage', 0);
        self::assertNull($repo->find(self::USER_A, self::CHAT_PRIVATE));
    }

    private function bindHeardUpdate(Nutgram $bot): void
    {
        $mode = $bot->getContainer()->get(RunningMode::class);
        if (!$mode instanceof Fake) {
            return;
        }
        $updateProperty = new \ReflectionProperty(Fake::class, 'update');
        $updateProperty->setAccessible(true);
        $update = $updateProperty->getValue($mode);
        if (!$update instanceof Update) {
            return;
        }

        $botUpdate = new \ReflectionProperty(Nutgram::class, 'update');
        $botUpdate->setAccessible(true);
        $botUpdate->setValue($bot, $update);
    }

    private function hearCloseOnMessage(Nutgram $bot, int $messageId): void
    {
        $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => '1',
            'from' => ['id' => self::USER_A, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => self::CHAT_PRIVATE, 'type' => 'private'],
                'text' => 'Меню',
            ],
            'data' => InlineMenuHelper::CLOSE_CALLBACK,
        ]);
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

    private function assertNoEditToClosedText(Nutgram $bot): void
    {
        $limit = $this->testingHistoryCount($bot);
        for ($i = 0; $i < $limit; $i++) {
            try {
                $bot->assertRaw(function (Request $request): bool {
                    if ($request->getUri()->getPath() !== 'editMessageText') {
                        return false;
                    }
                    $body = FakeNutgram::getActualData($request);
                    $text = (string) ($body['text'] ?? '');

                    return str_contains(mb_strtolower($text), 'закрыто');
                }, $i);

                self::fail('Menu close must not edit text to "Закрыто".');
            } catch (\PHPUnit\Framework\ExpectationFailedException) {
                continue;
            } catch (\TypeError) {
                continue;
            }
        }
    }

    private function testingHistoryCount(Nutgram $bot): int
    {
        if (!$bot instanceof FakeNutgramClass) {
            return 0;
        }

        $history = new \ReflectionProperty(FakeNutgramClass::class, 'testingHistory');
        $history->setAccessible(true);

        return count($history->getValue($bot));
    }

    private function testConfig(): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
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