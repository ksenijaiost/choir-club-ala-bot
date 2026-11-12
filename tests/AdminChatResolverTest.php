<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Repositories\BotSettingsRepository;
use App\Services\AdminChatResolver;
use App\Services\AccessControl;
use PHPUnit\Framework\TestCase;

final class AdminChatResolverTest extends TestCase
{
    public function testSettingOverridesEnv(): void
    {
        $config = $this->config(envChatId: '-100111');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $settings->set(AdminChatResolver::SETTING_KEY, '-100222');

        $resolver = new AdminChatResolver($settings, $config);
        self::assertSame('-100222', $resolver->resolvedId());
        self::assertTrue($resolver->isAdminChat(-100222));
        self::assertFalse($resolver->isAdminChat(-100111));
    }

    public function testMaterialsHandlerIgnoresOldChatAfterChange(): void
    {
        $config = $this->config(envChatId: '-100777');
        $pdo = Connection::make($config);
        (new Migrator($pdo, 'sqlite'))->migrate();

        $settings = new BotSettingsRepository($pdo);
        $resolver = new AdminChatResolver($settings, $config);
        $access = new AccessControl($config, $resolver);

        self::assertTrue($access->isAdminChatId(-100777));

        $settings->set(AdminChatResolver::SETTING_KEY, '-100888');
        self::assertFalse($access->isAdminChatId(-100777));
        self::assertTrue($access->isAdminChatId(-100888));
    }

    private function config(string $envChatId): Config
    {
        return new Config(
            botToken: 'test-token',
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
