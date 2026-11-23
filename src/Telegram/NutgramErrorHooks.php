<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config;
use App\Services\ErrorReportService;
use App\Support\ErrorLog;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class NutgramErrorHooks
{
    public static function register(
        Nutgram $bot,
        Config $config,
        TextRepository $texts,
        ErrorReportService $errorReports,
    ): void {
        $bot->onException(function (Nutgram $bot, \Throwable $e) use ($config, $errorReports): void {
            SafeTelegram::answerCallbackQuery($bot);
            ErrorLog::log($config, $e, ErrorLog::contextFromNutgram($bot));

            try {
                $reportId = $errorReports->captureException($bot, $e);
                $errorReports->notifyUserAboutError($bot, $reportId);
            } catch (\Throwable) {
            }
        });

        $bot->onApiError(function (Nutgram $bot, TelegramException $e) use ($config): void {
            if (TelegramApiErrors::shouldSkipApiErrorLog($e)) {
                return;
            }

            ErrorLog::log($config, $e, ErrorLog::contextFromNutgram($bot));
        });
    }
}