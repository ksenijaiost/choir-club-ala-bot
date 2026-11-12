<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Clock;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class ScheduleMemberHandler
{
    public static function sendUpcoming(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        ScheduleGenerator $generator,
        SchedulePresenter $presenter,
    ): void {
        $tz = new \DateTimeZone($config->timezone);
        $now = Clock::now($config);
        $generator->generate($now, $tz);
        $message = $presenter->buildUpcomingMessage($now, $tz);
        $bot->sendMessage($message);
    }

    public static function handleViewCallback(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        ScheduleGenerator $generator,
        SchedulePresenter $presenter,
    ): void {
        $bot->answerCallbackQuery();
        self::sendUpcoming($bot, $texts, $config, $generator, $presenter);
    }
}
