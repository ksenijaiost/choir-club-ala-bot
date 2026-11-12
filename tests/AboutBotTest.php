<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Handlers\AboutBotHandler;
use App\Services\AccessControl;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class AboutBotTest extends TestCase
{
    public function testMemberAboutTextDoesNotMentionAdminMenuButtons(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $body = AboutBotHandler::sectionText($texts, 'member');

        foreach ([
            'menu.admin_schedule',
            'menu.admin_attendance',
            'menu.admin_payment',
            'menu.admin_history',
            'menu.admin_helpers',
            'menu.admin_members',
            'menu.admin_materials',
            'menu.assign_conductor',
        ] as $key) {
            self::assertStringNotContainsString(
                $texts->get($key),
                $body,
                'Member help must not mention ' . $key,
            );
        }
    }

    public function testOwnerHasConductorAndOwnerSectionsWithAdminLabels(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $access = new AccessControl($this->testConfig());

        $user = [
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ];

        $sections = AboutBotHandler::visibleSectionIds($access, $user);
        self::assertContains('conductor', $sections);
        self::assertContains('owner', $sections);

        $conductorText = AboutBotHandler::sectionText($texts, 'conductor');
        self::assertStringContainsString($texts->get('menu.admin_attendance'), $conductorText);
        self::assertStringContainsString($texts->get('menu.admin_history'), $conductorText);

        $ownerText = AboutBotHandler::sectionText($texts, 'owner');
        self::assertStringContainsString($texts->get('menu.assign_conductor'), $ownerText);
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
