<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\UserStatus;
use App\Services\ScheduleTime;
use App\Telegram\CallbackUi;
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

        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null || ($rehearsal['status'] ?? '') !== 'scheduled') {
            $bot->answerCallbackQuery();

            return;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        $now = Clock::now($deps->config)->setTimezone($tz);
        if ($now >= $starts) {
            $bot->answerCallbackQuery(text: $deps->texts->get('absence.too_late'), show_alert: true);

            return;
        }

        $userId = (int) $user['id'];
        $existing = $deps->marks->find($rehearsalId, $userId);
        if ($yes) {
            $hadWarned = $existing !== null && ($existing['mark'] ?? '') === AttendanceMark::WARNED;

            if ($hadWarned) {
                $notifiedConductor = trim((string) ($existing['reason_text'] ?? '')) !== '';
                $deps->attendance->clearPlannedAbsence($user, $rehearsalId);
                if ($notifiedConductor) {
                    $deps->conductor->notifyChangedMind($user, $rehearsal, $tz);
                }
            }

            $deps->flows->clear($telegramId);
            $bot->answerCallbackQuery();
            CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_yes'));

            return;
        }

        if ($existing !== null && ($existing['mark'] ?? '') === AttendanceMark::WARNED) {
            $bot->answerCallbackQuery(text: $deps->texts->get('absence.use_day_before_to_change'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_no'));

        $deps->attendance->recordWarned($user, $rehearsalId, AttendanceSource::DAY_BEFORE, null);
        $deps->flows->set($telegramId, 'absence_reason', [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::DAY_BEFORE,
            'conductor_notified' => false,
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

        if ($deps->checkInSessions->isClosed($rehearsalId)) {
            $bot->answerCallbackQuery(text: $deps->texts->get('checkin.already_closed'), show_alert: true);
            CallbackUi::clearKeyboard($bot);

            return;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config)->setTimezone($tz);
        $ends = new \DateTimeImmutable((string) $rehearsal['ends_at'], $tz);

        if ($now >= $ends) {
            $bot->answerCallbackQuery(text: $deps->texts->get('checkin.already_closed'), show_alert: true);
            CallbackUi::clearKeyboard($bot);

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
            CallbackUi::clearKeyboard($bot);

            return;
        }

        $bot->answerCallbackQuery();

        if ($yes) {
            $deps->attendance->recordAttended($user, $rehearsalId);
            CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_yes'));

            return;
        }

        $deps->attendance->recordUnexcused($user, $rehearsalId, AttendanceSource::CHECKIN);
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_no'));
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
            if (($existing['mark'] ?? '') === AttendanceMark::WARNED) {
                $bot->answerCallbackQuery();
                CallbackUi::clearKeyboard($bot);

                return;
            }

            $bot->answerCallbackQuery(text: $deps->texts->get('absence.already_marked'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();
        CallbackUi::clearKeyboard($bot);

        $deps->attendance->recordWarned($user, $rehearsalId, AttendanceSource::ABSENCE_BUTTON, null);
        $deps->flows->set($telegramId, 'absence_reason', [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::ABSENCE_BUTTON,
            'conductor_notified' => false,
        ]);

        $startsLabel = ScheduleTime::formatLocalDate($starts, $tz) . ' '
            . ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz);
        $bot->sendMessage(
            $deps->texts->get('absence.rehearsal_picked', ['rehearsal' => $startsLabel])
            . "\n\n"
            . $deps->texts->get('absence.ask_reason_optional'),
            reply_markup: $deps->messages->reasonPromptKeyboard(),
        );
    }

    private static function handleSkipReason(Nutgram $bot, AttendanceDeps $deps, int $telegramId): bool
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'absence_reason') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            return true;
        }

        $ctx = $state['context'];
        $rehearsalId = (int) ($ctx['rehearsal_id'] ?? 0);
        $source = (string) ($ctx['source'] ?? AttendanceSource::ABSENCE_BUTTON);

        $bot->answerCallbackQuery();
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_skip'));
        AttendanceMemberHandler::finishReasonSkip($bot, $deps, $telegramId, $source, $rehearsalId);

        return true;
    }
}
