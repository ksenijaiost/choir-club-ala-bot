<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Handlers\MaterialsAdminHandler;
use App\Telegram\AdminChatLink;
use App\Text\TextRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminChatLinkTest extends TestCase
{
    public function testSupergroupBuildsTelegramPrivateUrl(): void
    {
        $config = $this->config(adminChatId: '-1001234567890');
        self::assertSame('https://t.me/c/1234567890', AdminChatLink::resolveUrl($config));
    }

    public function testRegularGroupWithoutLinkHasNoUrl(): void
    {
        $config = $this->config(adminChatId: '-12345');
        self::assertNull(AdminChatLink::resolveUrl($config));
    }

    public function testRegularGroupWithEnvLinkUsesLink(): void
    {
        $config = $this->config(adminChatId: '-12345', adminChatLink: 'https://t.me/joinchat/AbCdEf');
        self::assertSame('https://t.me/joinchat/AbCdEf', AdminChatLink::resolveUrl($config));
    }

    /**
     * @return list<array{bool, ?string}>
     */
    public static function keyboardUrlCases(): array
    {
        return [
            'supergroup' => [true, 'https://t.me/c/987654321'],
            'group_no_link' => [false, null],
            'group_with_link' => [true, 'https://t.me/mygroup'],
        ];
    }

    #[DataProvider('keyboardUrlCases')]
    public function testMaterialsHintKeyboardIncludesUrlWhenResolvable(bool $expectsUrl, ?string $expectedUrl): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');

        $config = match ($expectedUrl) {
            'https://t.me/c/987654321' => $this->config(adminChatId: '-100987654321'),
            'https://t.me/mygroup' => $this->config(adminChatId: '-999', adminChatLink: 'https://t.me/mygroup'),
            default => $this->config(adminChatId: '-999'),
        };

        $json = json_encode(
            MaterialsAdminHandler::adminChatHintKeyboard($texts, $config),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        if ($expectsUrl) {
            self::assertStringContainsString($texts->get('materials.open_admin_chat'), $json);
            /** @var array{inline_keyboard: list<list<array{url?: string}>>} $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expectedUrl, $decoded['inline_keyboard'][0][0]['url'] ?? null);
        } else {
            self::assertStringNotContainsString('open_admin_chat', $json);
            self::assertStringNotContainsString('"url"', $json);
        }

        self::assertStringContainsString($texts->get('ui.close'), $json);
    }

    private function config(string $adminChatId, string $adminChatLink = ''): Config
    {
        return new Config(
            botToken: 'test-token',
            ownerTelegramId: '1',
            conductorTelegramId: '',
            adminChatId: $adminChatId,
            adminChatLink: $adminChatLink,
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
