<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Handlers\AboutBotHandler;
use App\Handlers\HandlerRegistry;
use App\Repositories\UserRepository;
use App\Telegram\InlineMenuHelper;
use App\Text\TextRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AboutBotCallbackTest extends TestCase
{
    private const OWNER_TG = 93_001;

    /**
     * @return list<array{string}>
     */
    public static function ownerSectionIds(): array
    {
        return [
            ['member'],
            ['staff'],
            ['conductor'],
            ['owner'],
        ];
    }

    #[DataProvider('ownerSectionIds')]
    public function testAboutSectionCallbackEditsMessage(string $sectionId): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Техспец');

        $bot = $this->fakePrivateBot(self::OWNER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $expected = AboutBotHandler::sectionText($texts, $sectionId);

        $bot->hearCallbackQueryData('ab:sec:' . $sectionId)
            ->willStartConversation()
            ->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertReplyText($expected, index: 1);
    }

    public function testAboutBackCallbackReturnsToSectionPicker(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Техспец');

        $bot = $this->fakePrivateBot(self::OWNER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $pickSection = $texts->get('about.pick_section');

        $bot->hearCallbackQueryData('ab:home')
            ->willStartConversation()
            ->reply()
            ->assertReplyText($pickSection, index: 1);
    }

    public function testAboutCloseCallbackShowsMenuClosed(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(self::OWNER_TG, 'Техспец');

        $bot = $this->fakePrivateBot(self::OWNER_TG);
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $closed = $texts->get('ui.menu_closed');

        $bot->hearCallbackQueryData(InlineMenuHelper::CLOSE_CALLBACK)
            ->willStartConversation()
            ->reply()
            ->assertReplyText($closed, index: 1);
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
            ownerTelegramId: (string) self::OWNER_TG,
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
