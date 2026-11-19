<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\RecordingSourceUrl;
use PHPUnit\Framework\TestCase;

final class RecordingSourceUrlTest extends TestCase
{
    public function testAcceptsPublicAndPrivateChatLinks(): void
    {
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/choir_club/120'));
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/c/1234567890/99'));
    }

    public function testRejectsNonTelegramLinks(): void
    {
        self::assertFalse(RecordingSourceUrl::isValid('https://example.com/x'));
        self::assertFalse(RecordingSourceUrl::isValid('not a url'));
    }
}