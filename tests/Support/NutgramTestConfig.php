<?php

declare(strict_types=1);

namespace App\Tests\Support;

use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;

final class NutgramTestConfig
{
    public const BOT_USERNAME = 'CHOIR_CLUB_ALA_bot';

    public static function configuration(): Configuration
    {
        return new Configuration(botName: self::BOT_USERNAME);
    }

    public static function fakeBot(?Configuration $config = null): FakeNutgram
    {
        return Nutgram::fake(config: $config ?? self::configuration());
    }
}