<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Repositories\ErrorReportRepository;
use App\Repositories\UserRepository;
use App\Support\ErrorLog;
use App\Telegram\SafeTelegram;
use App\Telegram\TelegramHtml;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User;

final class ErrorReportService
{
    public const FLOW_COMMENT = 'error_report_comment';
    public const FLOW_HELP = 'help_report';

    private const ACTION_MAX = 200;
    private const MESSAGE_MAX = 500;
    private const RATE_LIMIT_PER_HOUR = 5;

    public function __construct(
        private readonly ErrorReportRepository $reports,
        private readonly UserRepository $users,
        private readonly TextRepository $texts,
        private readonly Config $config,
        private readonly AdminChatResolver $adminChat,
    ) {
    }

    public function captureException(Nutgram $bot, \Throwable $e): int
    {
        $telegramId = (int) ($bot->userId() ?? 0);
        $chatId = (int) ($bot->chatId() ?? $telegramId);
        $ctx = ErrorLog::contextFromNutgram($bot);

        return $this->reports->create([
            'telegram_id' => $telegramId > 0 ? $telegramId : $chatId,
            'chat_id' => $chatId,
            'update_id' => isset($ctx['update_id']) ? (int) $ctx['update_id'] : null,
            'update_type' => isset($ctx['update_type']) ? (string) $ctx['update_type'] : null,
            'action' => self::extractAction($bot),
            'exception_class' => $e::class,
            'exception_message' => self::truncateExceptionMessage($e, $this->config),
        ]);
    }

    public function createHelpReport(Nutgram $bot): int
    {
        $telegramId = (int) ($bot->userId() ?? 0);
        $chatId = (int) ($bot->chatId() ?? $telegramId);
        $ctx = ErrorLog::contextFromNutgram($bot);

        return $this->reports->create([
            'telegram_id' => $telegramId > 0 ? $telegramId : $chatId,
            'chat_id' => $chatId,
            'update_id' => isset($ctx['update_id']) ? (int) $ctx['update_id'] : null,
            'update_type' => isset($ctx['update_type']) ? (string) $ctx['update_type'] : null,
            'action' => '/help',
            'exception_class' => null,
            'exception_message' => null,
        ]);
    }

    public function notifyUserAboutError(Nutgram $bot, int $reportId): void
    {
        $chatId = $bot->chatId();
        if ($chatId === null) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('errors.report_button'),
                callback_data: 'err:rep:' . $reportId,
            ),
        );

        try {
            $bot->sendMessage(
                text: $this->texts->get('errors.generic_with_report'),
                chat_id: $chatId,
                reply_markup: $keyboard,
            );
        } catch (\Throwable) {
        }
    }

    public function askUserComment(Nutgram $bot, int $reportId): void
    {
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('errors.report_skip'),
                callback_data: 'err:skip:' . $reportId,
            ),
        );
        $bot->sendMessage(
            text: $this->texts->get('errors.report_ask_comment'),
            chat_id: $bot->chatId(),
            reply_markup: $keyboard,
        );
    }

    public function isAlreadyReported(int $reportId): bool
    {
        $report = $this->reports->findById($reportId);

        return $report !== null && ($report['reported_at'] ?? null) !== null;
    }

    public function ownsReport(int $reportId, int $telegramId): bool
    {
        $report = $this->reports->findById($reportId);

        return $report !== null && (int) ($report['telegram_id'] ?? 0) === $telegramId;
    }

    public function submitReport(Nutgram $bot, int $reportId, int $telegramId, ?string $userComment): bool
    {
        $report = $this->reports->findById($reportId);
        if ($report === null) {
            return false;
        }

        if (($report['reported_at'] ?? null) !== null) {
            return false;
        }

        if ((int) ($report['telegram_id'] ?? 0) !== $telegramId) {
            return false;
        }

        if ($this->isRateLimited($telegramId)) {
            $bot->sendMessage($this->texts->get('errors.report_rate_limited'), chat_id: $bot->chatId());

            return false;
        }

        $adminChatId = $this->adminChat->resolvedIdAsInt();
        if ($adminChatId === null) {
            $bot->sendMessage($this->texts->get('errors.generic_with_report'), chat_id: $bot->chatId());

            return false;
        }

        $userRow = $this->users->findByTelegramId($telegramId);
        $tgUser = $bot->user();
        $html = $this->buildAdminHtml($report, $userRow, $tgUser, $userComment);

        try {
            $bot->sendMessage(
                text: $html,
                chat_id: $adminChatId,
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable) {
            return false;
        }

        $this->reports->markReported($reportId);
        $bot->sendMessage($this->texts->get('errors.report_thanks'), chat_id: $bot->chatId());

        return true;
    }

    public function isRateLimited(int $telegramId): bool
    {
        $since = time() - 3600;

        return $this->reports->countReportedSince($telegramId, $since) >= self::RATE_LIMIT_PER_HOUR;
    }

    public static function extractAction(Nutgram $bot): string
    {
        $text = trim((string) ($bot->message()?->text ?? ''));
        if ($text !== '') {
            return mb_substr($text, 0, self::ACTION_MAX);
        }

        $data = (string) ($bot->callbackQuery()?->data ?? '');
        if ($data !== '') {
            return mb_substr($data, 0, self::ACTION_MAX);
        }

        return '—';
    }

    /**
     * @param array<string, mixed>|null $userRow
     */
    private function buildAdminHtml(
        array $report,
        ?array $userRow,
        ?User $tgUser,
        ?string $userComment,
    ): string {
        $telegramId = (int) ($report['telegram_id'] ?? 0);
        $displayName = $userRow !== null
            ? (string) ($userRow['display_name'] ?? '')
            : TelegramHtml::telegramDisplayName($tgUser);
        $username = $userRow !== null
            ? (isset($userRow['telegram_username']) ? (string) $userRow['telegram_username'] : null)
            : ($tgUser?->username !== null ? (string) $tgUser->username : null);

        $nameLine = TelegramHtml::escape($displayName);
        if ($telegramId > 0) {
            $nameLine = TelegramHtml::userLink($telegramId, $displayName);
            if ($username !== null && trim($username) !== '') {
                $nameLine .= ' (' . TelegramHtml::usernameLink($username) . ')';
            }
        }

        $partCode = $userRow !== null ? (string) ($userRow['voice_part'] ?? '') : '';
        $part = '—';
        if ($partCode !== '') {
            try {
                $part = $this->texts->get('parts.' . $partCode);
            } catch (\Throwable) {
                $part = '—';
            }
        }

        $tz = new \DateTimeZone($this->config->timezone);
        $datetime = (new \DateTimeImmutable('now', $tz))->format('d.m.Y H:i');

        $errorBlock = '';
        $exceptionClass = (string) ($report['exception_class'] ?? '');
        if ($exceptionClass !== '') {
            $errorBlock = $this->texts->get('errors.report_admin_error_block', [
                'exception_class' => TelegramHtml::escape($exceptionClass),
                'exception_message' => TelegramHtml::escape((string) ($report['exception_message'] ?? '')),
            ]);
        }

        $commentBlock = '';
        $comment = trim((string) ($userComment ?? ''));
        if ($comment !== '') {
            $commentBlock = $this->texts->get('errors.report_admin_comment_block', [
                'comment' => TelegramHtml::escape($comment),
            ]);
        }

        return $this->texts->get('errors.report_admin_message', [
            'user_line' => $nameLine,
            'telegram_id' => (string) $telegramId,
            'part' => TelegramHtml::escape($part),
            'datetime' => TelegramHtml::escape($datetime),
            'action' => TelegramHtml::escape((string) ($report['action'] ?? '—')),
            'error_block' => $errorBlock,
            'comment_block' => $commentBlock,
            'update_id' => TelegramHtml::escape((string) ($report['update_id'] ?? '—')),
        ]);
    }

    private static function truncateExceptionMessage(\Throwable $e, Config $config): string
    {
        $message = ErrorLog::redact($config, $e->getMessage());

        return mb_substr($message, 0, self::MESSAGE_MAX);
    }
}