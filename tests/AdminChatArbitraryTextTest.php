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
use App\Repositories\UserRepository;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class AdminChatArbitraryTextTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;
    private const UPLOADER_TG = 88_001;

    public function testArbitraryTextInAdminChatGetsNoReply(): void
    {
        $config = $this->testConfig();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $settings->set('admin_chat_id', (string) self::ADMIN_CHAT_ID);

        $users = new UserRepository($pdo);
        $users->create(self::UPLOADER_TG, 'Админ', 'tenor', UserRole::ADMIN, UserStatus::ACTIVE);

        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();
        HandlerRegistry::register($bot, $texts, $config, $pdo);

        $bot->setCommonUser($this->makeUser());
        $bot->setCommonChat($this->makeChat(self::ADMIN_CHAT_ID, ChatType::SUPERGROUP));

        $bot->hearText('просто болтовня в админ-чате')
            ->willStartConversation()
            ->reply()
            ->assertNoReply();
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->id = self::UPLOADER_TG;
        $user->is_bot = false;
        $user->first_name = 'Админ';

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