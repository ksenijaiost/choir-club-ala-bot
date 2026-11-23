<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\RecordingSourceUrl;
use PHPUnit\Framework\TestCase;

final class RecordingSourceUrlTest extends TestCase
{
    public function testAcceptsLegacyPrivateAndPublicLinks(): void
    {
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/choir_club/120'));
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/c/1234567890/99'));
    }

    public function testAcceptsForumTopicLinks(): void
    {
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/c/1171755209/11402/11525'));
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/choir_club/11402/11525'));
    }

    public function testAcceptsQueryParameters(): void
    {
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/c/1234567890/99?thread=1'));
        self::assertTrue(RecordingSourceUrl::isValid('https://t.me/c/1171755209/11402/11525?comment=1'));
    }

    public function testNormalizesHttpAndTelegramMe(): void
    {
        self::assertSame(
            'https://t.me/c/1234567890/99',
            RecordingSourceUrl::normalize('http://t.me/c/1234567890/99'),
        );
        self::assertSame(
            'https://t.me/choir_club/120',
            RecordingSourceUrl::normalize('https://telegram.me/choir_club/120'),
        );
        self::assertTrue(RecordingSourceUrl::isValid('http://t.me/c/1171755209/11402/11525'));
        self::assertTrue(RecordingSourceUrl::isValid('http://telegram.me/choir_club/5/10'));
    }

    public function testTrimsWhitespaceBeforeValidation(): void
    {
        self::assertTrue(RecordingSourceUrl::isValid("  https://t.me/c/1171755209/11402/11525  \n"));
        self::assertSame(
            'https://t.me/c/1171755209/11402/11525',
            RecordingSourceUrl::normalize("  https://t.me/c/1171755209/11402/11525  "),
        );
    }

    public function testRejectsGarbageAndIncompleteLinks(): void
    {
        self::assertFalse(RecordingSourceUrl::isValid('https://example.com/x'));
        self::assertFalse(RecordingSourceUrl::isValid('not a url'));
        self::assertFalse(RecordingSourceUrl::isValid('https://t.me/c/abc/1'));
        self::assertFalse(RecordingSourceUrl::isValid('https://t.me/c/1234567890'));
        self::assertFalse(RecordingSourceUrl::isValid('https://t.me/choir_club'));
    }
}