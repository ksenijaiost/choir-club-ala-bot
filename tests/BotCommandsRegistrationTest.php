<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\AdminChatBotCommands;
use App\Services\PrivateChatBotCommands;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;

final class BotCommandsRegistrationTest extends TestCase
{
    private const ADMIN_CHAT_ID = -1009001;

    public function testPrivateChatCommandsRegisteredForDefaultAndRussian(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();

        PrivateChatBotCommands::register($bot, $texts);

        $bot->assertCalled('setMyCommands', 2);
        self::assertSame([null, 'ru'], $this->languageCodesFromSetMyCommands($bot));
    }

    public function testAdminChatCommandsRegisteredForDefaultAndRussian(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();

        AdminChatBotCommands::registerForAdminChat($bot, $texts, self::ADMIN_CHAT_ID);

        $bot->assertCalled('setMyCommands', 2);
        self::assertSame([null, 'ru'], $this->languageCodesFromSetMyCommands($bot));

        $commandsJson = $this->firstSetMyCommandsPayload($bot)['commands'] ?? '[]';
        self::assertStringContainsString('"command":"notes"', $commandsJson);
        self::assertStringNotContainsString('"command":"translit"', $commandsJson);
    }

    /**
     * @return list<string|null>
     */
    private function languageCodesFromSetMyCommands(Nutgram $bot): array
    {
        $codes = [];
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'setMyCommands')) {
                continue;
            }
            $data = json_decode((string) $entry['request']->getBody(), true);
            $codes[] = array_key_exists('language_code', $data)
                ? ($data['language_code'] !== '' ? $data['language_code'] : null)
                : null;
        }

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstSetMyCommandsPayload(Nutgram $bot): array
    {
        foreach ($bot->getRequestHistory() as $entry) {
            if (str_contains($entry['request']->getUri()->getPath(), 'setMyCommands')) {
                return json_decode((string) $entry['request']->getBody(), true) ?: [];
            }
        }

        self::fail('setMyCommands was not called');

        return [];
    }
}