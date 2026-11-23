<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\UsageSectionResolver;
use App\Text\TextRepository;
use PHPUnit\Framework\TestCase;

final class UsageSectionResolverTest extends TestCase
{
    public function testResolvesMenuLabelCommandAndCallback(): void
    {
        $texts = TextRepository::fromFile(dirname(__DIR__) . '/texts/ru.json');
        $resolver = new UsageSectionResolver($texts);

        $resolveCommand = new \ReflectionMethod(UsageSectionResolver::class, 'resolveCommand');
        $resolveCommand->setAccessible(true);
        self::assertSame('find_song', $resolveCommand->invoke($resolver, 'song'));

        $resolveMenu = new \ReflectionMethod(UsageSectionResolver::class, 'resolveMenuLabel');
        $resolveMenu->setAccessible(true);
        self::assertSame('find_song', $resolveMenu->invoke($resolver, $texts->get('menu.find_song')));

        $resolveCallback = new \ReflectionMethod(UsageSectionResolver::class, 'resolveCallbackPrefix');
        $resolveCallback->setAccessible(true);
        self::assertSame('my_recordings', $resolveCallback->invoke($resolver, 'snd:my:all:soprano_1'));
        self::assertSame('programs', $resolveCallback->invoke($resolver, 'prg:mo:1'));
    }
}