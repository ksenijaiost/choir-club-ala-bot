<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\ProfileHandler;
use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class ProfileRenameTest extends TestCase
{
    private const MEMBER_TG = 88_002;

    public function testRenameSavesDisplayNameAndClearsFlow(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->create(self::MEMBER_TG, 'Старое имя', 'tenor', UserRole::MEMBER, UserStatus::ACTIVE);
        $flows = new UserFlowRepository($pdo);
        $flows->set(self::MEMBER_TG, 'profile_rename', []);

        $bot = Nutgram::fake();
        ProfileHandler::handleFlowMessage(
            $bot,
            $texts,
            $users,
            $flows,
            self::MEMBER_TG,
            '  Новое имя  ',
        );

        $user = $users->findByTelegramId(self::MEMBER_TG);
        self::assertNotNull($user);
        self::assertSame('Новое имя', $user['display_name']);
        self::assertNull($flows->get(self::MEMBER_TG));
    }

    public function testOwnerRoleBadgeTextIsTechspec(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        self::assertSame('техспец', $texts->get('admin.role_badge_owner'));
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
