<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserFlowRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\ErrorReportService;
use App\Services\UserIdentitySync;
use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class ErrorReportHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        UserFlowRepository $flows,
        ErrorReportService $errorReports,
    ): void {
        $bot->onCommand('help', function (Nutgram $bot) use ($texts, $users, $access, $flows, $errorReports): void {
            $chat = $bot->chat();
            if ($chat === null || $chat->type !== ChatType::PRIVATE) {
                return;
            }

            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $displayName = trim($bot->user()?->first_name ?? '');
            UserIdentitySync::syncPrivilegedTelegramUser($telegramId, $displayName, $access, $users);

            if ($errorReports->isRateLimited($telegramId)) {
                $bot->sendMessage($texts->get('errors.report_rate_limited'));

                return;
            }

            $reportId = $errorReports->createHelpReport($bot);
            $flows->set($telegramId, ErrorReportService::FLOW_HELP, ['report_id' => $reportId]);
            $errorReports->askUserComment($bot, $reportId);
        });
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserFlowRepository $flows,
        ErrorReportService $errorReports,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (preg_match('/^err:rep:(\d+)$/', $data, $m) === 1) {
            $reportId = (int) $m[1];

            if (!$errorReports->ownsReport($reportId, $actorTelegramId)) {
                SafeTelegram::answerCallbackQuery($bot);

                return true;
            }

            if ($errorReports->isAlreadyReported($reportId)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $texts->get('errors.report_already_sent'),
                    'show_alert' => true,
                ]);

                return true;
            }

            SafeTelegram::answerCallbackQuery($bot);

            if ($errorReports->isRateLimited($actorTelegramId)) {
                $bot->sendMessage($texts->get('errors.report_rate_limited'), chat_id: $bot->chatId());

                return true;
            }

            $flows->set($actorTelegramId, ErrorReportService::FLOW_COMMENT, ['report_id' => $reportId]);
            $errorReports->askUserComment($bot, $reportId);

            return true;
        }

        if (preg_match('/^err:skip:(\d+)$/', $data, $m) === 1) {
            $reportId = (int) $m[1];
            $flows->clear($actorTelegramId);

            if ($errorReports->isAlreadyReported($reportId)) {
                SafeTelegram::answerCallbackQuery($bot, [
                    'text' => $texts->get('errors.report_already_sent'),
                    'show_alert' => true,
                ]);

                return true;
            }

            SafeTelegram::answerCallbackQuery($bot);
            $errorReports->submitReport($bot, $reportId, $actorTelegramId, null);

            return true;
        }

        return false;
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        UserFlowRepository $flows,
        ErrorReportService $errorReports,
        int $telegramId,
        string $text,
    ): bool {
        $flow = $flows->get($telegramId);
        if ($flow === null) {
            return false;
        }

        $flowName = $flow['flow'] ?? '';
        if (!in_array($flowName, [ErrorReportService::FLOW_COMMENT, ErrorReportService::FLOW_HELP], true)) {
            return false;
        }

        $reportId = (int) ($flow['context']['report_id'] ?? 0);
        if ($reportId <= 0) {
            $flows->clear($telegramId);

            return false;
        }

        $flows->clear($telegramId);
        $errorReports->submitReport($bot, $reportId, $telegramId, $text);

        return true;
    }
}