<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\AttendanceSource;
use App\Domain\UserStatus;
use App\Services\ScheduleTime;
use SergiX44\Nutgram\Nutgram;

final class AttendanceCallbackHandler
{
    public static function handle(
        Nutgram $bot,
        AttendanceDeps $deps,
        string $data,
        int $actorTelegramId,
    ): bool {
        if ($data === 'att:sk') {
            return self::handleSkipReason($bot, $deps, $actorTelegramId);
        }

        if (preg_match('/^att:db:(\d+):(y|n)$/', $data, $m) === 1) {
            self::handleDayBefore($bot, $deps, $actorTelegramId, (int) $m[1], $m[2] === 'y');

            return true;
        }

        if (preg_match('/^att:ci:(\d+):(y|n)$/', $data, $m) === 1) {
            self::handleCheckin($bot, $deps, $actorTelegramId, (int) $m[1], $m[2] === 'y');

            return true;
        }

        if (preg_match('/^att:wx:(\d+)$/', $data, $m) === 1) {
            self::handleWontComePick($bot, $deps, $actorTelegramId, (int) $m[1]);

            return true;
        }

        return false;
    }

    private static function handleDayBefore(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
        bool $yes,
    ): void {
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::ACTIVE) {
            $bot->answerCallbackQuery();

            return;
        }

        $bot->answerCallbackQuery();

        if ($yes) {
            $bot->sendMessage($deps->texts->get('reminder.will_attend'));

            return;
        }

        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null || ($rehearsal['status'] ?? '') !== 'scheduled') {
            return;
        }

        $deps->attendance->recordWarned($user, $rehearsalId, AttendanceSource::DAY_BEFORE, null);
        $deps->flows->set($telegramId, 'absence_reason', [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::DAY_BEFORE,
        ]);

        $bot->sendMessage(
            $deps->texts->get('reminder.will_not_attend'),
            reply_markup: $deps->messages->reasonPromptKeyboard(),
        );
    }

    private static function handleCheckin(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
        bool $yes,
    ): void {
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::ACTIVE) {
            $bot->answerCallbackQuery();

            return;
        }

        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null) {
            $bot->answerCallbackQuery();

            return;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config)->setTimezone($tz);
        $ends = new \DateTimeImmutable((string) $rehearsal['ends_at'], $tz);

        if ($now >= $ends) {
            $bot->answerCallbackQuery(text: $deps->texts->get('checkin.expired'), show_alert: true);

            return;
        }

        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        if ($now < $starts) {
            $bot->answerCallbackQuery();

            return;
        }

        $existing = $deps->marks->find($rehearsalId, (int) $user['id']);
        if ($existing !== null) {
            $bot->answerCallbackQuery();

            return;
        }

        $bot->answerCallbackQuery();

        if ($yes) {
            $deps->attendance->recordAttended($user, $rehearsalId);
            $bot->sendMessage($deps->texts->get('checkin.thanks_yes'));

            return;
        }

        $deps->attendance->recordUnexcused($user, $rehearsalId, AttendanceSource::CHECKIN);
        $bot->sendMessage($deps->texts->get('checkin.thanks_no'));
    }

    private static function handleWontComePick(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
    ): void {
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::ACTIVE) {
            $bot->answerCallbackQuery();

            return;
        }

        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null || ($rehearsal['status'] ?? '') !== 'scheduled') {
            $bot->answerCallbackQuery();

            return;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config)->setTimezone($tz);
        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        if ($now >= $starts) {
            $bot->answerCallbackQuery(text: $deps->texts->get('absence.too_late'), show_alert: true);

            return;
        }

        $existing = $deps->marks->find($rehearsalId, (int) $user['id']);
        if ($existing !== null) {
            $bot->answerCallbackQuery(text: $deps->texts->get('absence.already_marked'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();
        $deps->attendance->recordWarned($user, $rehearsalId, AttendanceSource::ABSENCE_BUTTON, null);
        $deps->flows->set($telegramId, 'absence_reason', [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::ABSENCE_BUTTON,
        ]);

        $bot->sendMessage(
            $deps->texts->get('absence.ask_reason_optional'),
            reply_markup: $deps->messages->reasonPromptKeyboard(),
        );
    }

    private static function handleSkipReason(Nutgram $bot, AttendanceDeps $deps, int $telegramId): bool
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'absence_reason') {
            $bot->answerCallbackQuery();

            return true;
        }

        $ctx = $state['context'];
        $rehearsalId = (int) ($ctx['rehearsal_id'] ?? 0);
        $source = (string) ($ctx['source'] ?? AttendanceSource::ABSENCE_BUTTON);

        $bot->answerCallbackQuery();
        AttendanceMemberHandler::finishReasonSkip($bot, $deps, $telegramId, $source, $rehearsalId);

        return true;
    }
}
