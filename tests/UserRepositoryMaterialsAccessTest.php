<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use PHPUnit\Framework\TestCase;

final class UserRepositoryMaterialsAccessTest extends TestCase
{
    public function testOwnerInParticipantAndPayerLists(): void
    {
        $pdo = Connection::make($this->testConfig());
        (new Migrator($pdo, 'sqlite'))->migrate();

        $users = new UserRepository($pdo);
        $users->upsertOwner(1, 'Владелица');

        self::assertNotEmpty($users->listActiveParticipants());
        self::assertNotEmpty($users->listActivePayers());
    }

    public function testCanUploadMaterialsOnlyAdminAndConductor(): void
    {
        $access = new AccessControl($this->testConfig());

        $admin = ['role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE];
        $conductor = ['role' => UserRole::CONDUCTOR, 'status' => UserStatus::ACTIVE];
        $owner = ['role' => UserRole::OWNER, 'status' => UserStatus::ACTIVE];
        $member = ['role' => UserRole::MEMBER, 'status' => UserStatus::ACTIVE];

        self::assertTrue($access->canUploadMaterials($admin));
        self::assertTrue($access->canUploadMaterials($conductor));
        self::assertFalse($access->canUploadMaterials($owner));
        self::assertFalse($access->canUploadMaterials($member));
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
