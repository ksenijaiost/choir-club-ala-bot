<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Handlers\AdminChatDeps;
use App\Handlers\AdminChatOwnerHandler;
use App\Repositories\BotSettingsRepository;
use App\Repositories\UserFlowRepository;
use App\Services\AdminChatBinder;
use App\Services\AdminChatResolver;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class AdminChatBindTest extends TestCase
{
    private const NEW_CHAT = -100_555_0001;

    public function testValidChatSavesSetting(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $flows = new UserFlowRepository($pdo);
        $resolver = new AdminChatResolver($settings, $config);
        $deps = new AdminChatDeps($config, $settings, $resolver, $flows, $texts);

        $bot = Nutgram::fake();
        $bot->willReceivePartial([
            'id' => self::NEW_CHAT,
            'title' => 'Новый админ-чат',
            'type' => 'supergroup',
        ]);
        $bot->willReceivePartial(['id' => 123456789, 'is_bot' => true]);
        $bot->willReceivePartial([
            'user' => ['id' => 123456789, 'is_bot' => true],
            'status' => 'administrator',
        ]);

        $flows->set(1, 'admin_chat_change', []);
        AdminChatOwnerHandler::handleFlowMessage($bot, $deps, 1, (string) self::NEW_CHAT);

        self::assertSame((string) self::NEW_CHAT, $settings->get(AdminChatResolver::SETTING_KEY));
        self::assertNull($flows->get(1));
    }

    public function testBotNotAdminDoesNotChangeSetting(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config(envChatId: '-100777');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $resolver = new AdminChatResolver($settings, $config);

        $bot = Nutgram::fake();
        $bot->willReceivePartial([
            'id' => self::NEW_CHAT,
            'title' => 'Чужой чат',
            'type' => 'supergroup',
        ]);
        $bot->willReceivePartial(['id' => 123456789, 'is_bot' => true]);
        $bot->willReceivePartial([
            'user' => ['id' => 123456789, 'is_bot' => true],
            'status' => 'member',
        ]);

        $result = AdminChatBinder::tryBind($bot, $settings, $texts, (string) self::NEW_CHAT);
        self::assertFalse($result['ok']);
        self::assertSame('admin_chat.bot_not_admin', $result['error_key']);
        self::assertNull($settings->get(AdminChatResolver::SETTING_KEY));
        self::assertSame('-100777', $resolver->resolvedId());
    }

    public function testBotNotInChatDoesNotChangeSetting(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $config = $this->config();
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $bot = Nutgram::fake();
        $bot->willReceivePartial(['ok' => false], ok: false);

        $result = AdminChatBinder::tryBind($bot, $settings, $texts, '-100404000');
        self::assertFalse($result['ok']);
        self::assertSame('admin_chat.bot_not_in_chat', $result['error_key']);
        self::assertNull($settings->get(AdminChatResolver::SETTING_KEY));
    }

    private function config(string $envChatId = ''): Config
    {
        return new Config(
            botToken: '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: $envChatId,
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
