<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Services\AccessControl;
use App\Telegram\MenuButtonAccess;
use App\Telegram\MenuKeyboard;
use App\Text\TextRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoleMenuKeyboardTest extends TestCase
{
    /**
     * @return list<array{string, list<string>}>
     */
    public static function roleMenus(): array
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        return [
            'member' => [
                UserRole::MEMBER,
                [
                    $texts->get('menu.schedule'),
                    $texts->get('menu.wont_come'),
                    $texts->get('menu.find_song'),
                    $texts->get('menu.my_recordings'),
                    $texts->get('menu.my_profile'),
                    $texts->get('menu.about_bot'),
                ],
            ],
            'admin' => [
                UserRole::ADMIN,
                [
                    $texts->get('menu.schedule'),
                    $texts->get('menu.wont_come'),
                    $texts->get('menu.find_song'),
                    $texts->get('menu.my_recordings'),
                    $texts->get('menu.my_profile'),
                    $texts->get('menu.about_bot'),
                    $texts->get('menu.admin_schedule'),
                    $texts->get('menu.admin_materials'),
                ],
            ],
            'conductor' => [
                UserRole::CONDUCTOR,
                [
                    $texts->get('menu.schedule'),
                    $texts->get('menu.wont_come'),
                    $texts->get('menu.find_song'),
                    $texts->get('menu.my_recordings'),
                    $texts->get('menu.my_profile'),
                    $texts->get('menu.about_bot'),
                    $texts->get('menu.admin_schedule'),
                    $texts->get('menu.admin_materials'),
                    $texts->get('menu.admin_attendance'),
                    $texts->get('menu.admin_history'),
                    $texts->get('menu.admin_payment'),
                    $texts->get('menu.admin_helpers'),
                    $texts->get('menu.admin_members'),
                ],
            ],
            'owner' => [
                UserRole::OWNER,
                [
                    $texts->get('menu.schedule'),
                    $texts->get('menu.wont_come'),
                    $texts->get('menu.find_song'),
                    $texts->get('menu.my_recordings'),
                    $texts->get('menu.my_profile'),
                    $texts->get('menu.about_bot'),
                    $texts->get('menu.admin_schedule'),
                    $texts->get('menu.admin_materials'),
                    $texts->get('menu.admin_attendance'),
                    $texts->get('menu.admin_history'),
                    $texts->get('menu.admin_payment'),
                    $texts->get('menu.admin_helpers'),
                    $texts->get('menu.admin_members'),
                    $texts->get('menu.assign_conductor'),
                ],
            ],
        ];
    }

    /**
     * @param list<string> $expectedLabels
     */
    #[DataProvider('roleMenus')]
    public function testMenuKeyboardMatchesRoleAndAccess(string $role, array $expectedLabels): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $access = new AccessControl($this->testConfig());

        $user = [
            'id' => 1,
            'telegram_id' => 10,
            'role' => $role,
            'status' => UserStatus::ACTIVE,
            'display_name' => 'Тест',
        ];

        $labels = MenuKeyboard::buttonLabelsForUser($texts, $user);
        sort($expectedLabels);
        sort($labels);
        self::assertSame($expectedLabels, $labels);

        foreach ($labels as $label) {
            self::assertTrue(
                MenuButtonAccess::canUseReplyMenuButton($access, $texts, $user, $label),
                'Button should be allowed for role ' . $role . ': ' . $label,
            );
        }

        if ($role === UserRole::ADMIN) {
            self::assertNotContains($texts->get('menu.admin_attendance'), $labels);
        }
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
