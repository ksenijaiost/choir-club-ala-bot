<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repositories\BotSettingsRepository;
use App\Services\AdminChatKeyboardRemoval;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class AdminChatKeyboardRemovalTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;

    public function testRemoveReplyKeyboardOnceSendsRemovalAndSetsFlag(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE bot_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)',
        );
        $settings = new BotSettingsRepository($pdo);

        AdminChatKeyboardRemoval::removeReplyKeyboardOnce($bot, $texts, $settings, self::ADMIN_CHAT_ID);

        $bot->assertCalled('sendMessage', 1);
        $data = $this->firstSendMessagePayload($bot);
        self::assertSame(self::ADMIN_CHAT_ID, $data['chat_id'] ?? null);
        self::assertSame($texts->get('admin_chat.commands_updated'), $data['text'] ?? null);
        self::assertTrue($data['reply_markup']['remove_keyboard'] ?? false);
        self::assertFalse($data['reply_markup']['selective'] ?? true);
        self::assertSame('1', $settings->get(AdminChatKeyboardRemoval::SETTING_KEY));

        AdminChatKeyboardRemoval::removeReplyKeyboardOnce($bot, $texts, $settings, self::ADMIN_CHAT_ID);
        $bot->assertCalled('sendMessage', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstSendMessagePayload(Nutgram $bot): array
    {
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                return json_decode((string) $entry['request']->getBody(), true) ?: [];
            }
        }

        self::fail('sendMessage was not called');

        return [];
    }
}