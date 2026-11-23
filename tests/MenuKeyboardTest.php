<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Telegram\MenuKeyboard;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class MenuKeyboardTest extends TestCase
{
    public function testReplyKeyboardIsPersistent(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $markup = MenuKeyboard::forUser($texts, [
            'role' => UserRole::MEMBER,
            'status' => UserStatus::ACTIVE,
        ]);

        self::assertTrue($markup->is_persistent ?? false);
    }
}