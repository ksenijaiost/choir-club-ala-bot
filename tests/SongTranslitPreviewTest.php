<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\MaterialsService;
use App\Services\SongTranslitPreview;
use PHPUnit\Framework\TestCase;

final class SongTranslitPreviewTest extends TestCase
{
    public function testStopsAtSeparator(): void
    {
        $full = 'Первая' . MaterialsService::TEXT_NOTES_SEPARATOR . 'Вторая';
        $result = SongTranslitPreview::build($full);

        self::assertSame('Первая', $result['text']);
        self::assertFalse($result['complete']);
    }

    public function testShortTextIsComplete(): void
    {
        $full = "Строка 1\n\nСтрока 2";
        $result = SongTranslitPreview::build($full);

        self::assertSame("Строка 1\nСтрока 2", $result['text']);
        self::assertTrue($result['complete']);
    }

    public function testTruncatesLongText(): void
    {
        $full = str_repeat('слово ', 300);
        $result = SongTranslitPreview::build($full);

        self::assertLessThanOrEqual(SongTranslitPreview::MAX_PREVIEW_CHARS + 3, mb_strlen($result['text']));
        self::assertStringEndsWith('…', $result['text']);
        self::assertFalse($result['complete']);
    }
}