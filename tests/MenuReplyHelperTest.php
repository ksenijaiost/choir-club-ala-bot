<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Telegram\MenuReplyHelper;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class MenuReplyHelperTest extends TestCase
{
    private const USER_TG = 77_001;

    public function testSendWithMenuInPrivateIncludesReplyKeyboard(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->makeUser());
        $bot->setCommonChat($this->makeChat(self::USER_TG, ChatType::PRIVATE));
        $bot->hearText('контекст')->willStartConversation()->reply();

        MenuReplyHelper::sendWithMenu($bot, $texts, $this->activeUser(), 'Тест');

        $data = $this->sendMessagePayloadWithText($bot, 'Тест');
        $keyboard = $data['reply_markup']['keyboard'] ?? null;
        self::assertIsArray($keyboard);
        self::assertSame($texts->get('menu.schedule'), $keyboard[0][0]['text'] ?? null);
    }

    public function testSendWithMenuInGroupOmitsReplyKeyboard(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $bot = Nutgram::fake();
        $bot->setCommonUser($this->makeUser());
        $bot->setCommonChat($this->makeChat(-100_777, ChatType::SUPERGROUP));
        $bot->hearText('контекст')->willStartConversation()->reply();

        MenuReplyHelper::sendWithMenu($bot, $texts, $this->activeUser(), 'Тест');

        $data = $this->sendMessagePayloadWithText($bot, 'Тест');
        self::assertArrayNotHasKey('reply_markup', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMessagePayloadWithText(Nutgram $bot, string $text): array
    {
        foreach ($bot->getRequestHistory() as $entry) {
            if (!str_contains($entry['request']->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $data = json_decode((string) $entry['request']->getBody(), true) ?: [];
            if (($data['text'] ?? '') === $text) {
                return $data;
            }
        }

        self::fail('sendMessage with text was not called: ' . $text);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function activeUser(): array
    {
        return [
            'role' => UserRole::MEMBER,
            'status' => UserStatus::ACTIVE,
            'telegram_id' => self::USER_TG,
        ];
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->id = self::USER_TG;
        $user->is_bot = false;
        $user->first_name = 'Участник';

        return $user;
    }

    private function makeChat(int $id, ChatType $type): Chat
    {
        $chat = new Chat();
        $chat->id = $id;
        $chat->type = $type;

        return $chat;
    }
}