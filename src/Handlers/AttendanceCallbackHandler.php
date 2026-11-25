<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\RehearsalRsvpAnswer;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Services\ScheduleTime;
use App\Telegram\CallbackUi;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

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

        if (preg_match('/^chk:late:(\d+)$/', $data, $m) === 1) {
            self::handleCheckinLate($bot, $deps, $actorTelegramId, (int) $m[1]);

            return true;
        }

        if (preg_match('/^chk:absent:(\d+)$/', $data, $m) === 1) {
            self::handleCheckinAbsent($bot, $deps, $actorTelegramId, (int) $m[1]);

            return true;
        }

        if (preg_match('/^chk:edit:(\d+)$/', $data, $m) === 1) {
            self::handleCheckinEdit($bot, $deps, $actorTelegramId, (int) $m[1]);

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

        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

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
            $deps->rsvp->upsert($rehearsalId, $userId, RehearsalRsvpAnswer::YES);
            if ($existing !== null) {
                $mark = (string) ($existing['mark'] ?? '');
                if ($mark === AttendanceMark::WARNED) {
                    $notifiedConductor = trim((string) ($existing['reason_text'] ?? '')) !== '';
                    $deps->attendance->clearPlannedAbsence($user, $rehearsalId);
                    if ($notifiedConductor) {
                        $deps->conductor->notifyChangedMind($user, $rehearsal, $tz);
                    }
                } elseif ($mark === AttendanceMark::UNEXCUSED
                    && ($existing['source'] ?? '') === AttendanceSource::DAY_BEFORE) {
                    $deps->attendance->clearPlannedAbsence($user, $rehearsalId);
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

        if ($existing !== null && ($existing['mark'] ?? '') !== AttendanceMark::UNEXCUSED) {
            $bot->answerCallbackQuery(text: $deps->texts->get('absence.already_marked'), show_alert: true);

            return;
        }

        $deps->rsvp->upsert($rehearsalId, $userId, RehearsalRsvpAnswer::NO);

        $bot->answerCallbackQuery();
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_no'));

        $deps->flows->set($telegramId, 'absence_reason', [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::DAY_BEFORE,
        ]);

        $bot->sendMessage(
            $deps->messages->pollReasonPromptText(),
            parse_mode: ParseMode::HTML,
            reply_markup: $deps->messages->reasonOptionalKeyboard(),
        );
    }

    private static function handleCheckin(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
        bool $yes,
    ): void {
        $ctx = self::loadCheckinContext($bot, $deps, $telegramId, $rehearsalId);
        if ($ctx === null) {
            return;
        }

        ['user' => $user, 'rehearsal' => $rehearsal] = $ctx;
        $userId = (int) $user['id'];
        $existing = $deps->marks->find($rehearsalId, $userId);
        if ($existing !== null) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            return;
        }

        $bot->answerCallbackQuery();
        $question = $deps->messages->checkinQuestionText();

        if ($yes) {
            $deps->flows->clear($telegramId);
            $deps->attendance->recordAttended($user, $rehearsalId);
            CallbackUi::finalizeCheckinAnswer(
                $bot,
                $question,
                $deps->texts->get('common.inline_answer_yes'),
                $deps->messages->checkinEditKeyboard($rehearsalId),
            );

            return;
        }

        CallbackUi::editCheckinPrompt(
            $bot,
            $deps->texts->get('checkin.late_or_absent'),
            $deps->messages->checkinLateAbsentKeyboard($rehearsalId),
        );
    }

    private static function handleCheckinLate(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
    ): void {
        $ctx = self::loadCheckinContext($bot, $deps, $telegramId, $rehearsalId);
        if ($ctx === null) {
            return;
        }

        $user = $ctx['user'];
        $userId = (int) $user['id'];
        $existing = $deps->marks->find($rehearsalId, $userId);
        if ($existing !== null) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            return;
        }

        $bot->answerCallbackQuery();
        $deps->flows->clear($telegramId);
        $deps->attendance->recordAttended($user, $rehearsalId);
        CallbackUi::finalizeCheckinAnswer(
            $bot,
            $deps->messages->checkinQuestionText(),
            $deps->texts->get('checkin.thanks_late'),
            $deps->messages->checkinEditKeyboard($rehearsalId),
        );
    }

    private static function handleCheckinAbsent(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
    ): void {
        $ctx = self::loadCheckinContext($bot, $deps, $telegramId, $rehearsalId);
        if ($ctx === null) {
            return;
        }

        $user = $ctx['user'];
        $userId = (int) $user['id'];
        $existing = $deps->marks->find($rehearsalId, $userId);
        if ($existing !== null) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            return;
        }

        $bot->answerCallbackQuery();
        $question = $deps->messages->checkinQuestionText();
        CallbackUi::finalizeCheckinAnswer(
            $bot,
            $question,
            $deps->texts->get('common.inline_answer_no'),
            InlineKeyboardMarkup::make(),
        );

        $flowContext = [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::CHECKIN,
        ];
        $message = CallbackUi::callbackMessage($bot);
        if ($message !== null) {
            $flowContext['checkin_chat_id'] = (int) $message->chat->id;
            $flowContext['checkin_message_id'] = (int) $message->message_id;
        }

        $editState = $deps->flows->get($telegramId);
        if ($editState !== null && ($editState['flow'] ?? '') === 'checkin_edit') {
            $flowContext['replaced_attended'] = (bool) ($editState['context']['replaced_attended'] ?? false);
        }

        $deps->flows->set($telegramId, 'absence_reason', $flowContext);

        $bot->sendMessage(
            $deps->messages->pollReasonPromptText(),
            parse_mode: ParseMode::HTML,
            reply_markup: $deps->messages->reasonOptionalKeyboard(),
        );
    }

    private static function handleCheckinEdit(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
    ): void {
        $ctx = self::loadCheckinContext($bot, $deps, $telegramId, $rehearsalId);
        if ($ctx === null) {
            return;
        }

        $user = $ctx['user'];
        $userId = (int) $user['id'];
        $existing = $deps->marks->find($rehearsalId, $userId);

        $replacedAttended = $existing !== null
            && ($existing['mark'] ?? '') === AttendanceMark::ATTENDED
            && ($existing['source'] ?? '') === AttendanceSource::CHECKIN;

        if ($existing !== null) {
            $source = (string) ($existing['source'] ?? '');
            if ($source === AttendanceSource::MANUAL) {
                $bot->answerCallbackQuery(text: $deps->texts->get('checkin.cannot_edit_manual'), show_alert: true);

                return;
            }

            if (!$deps->attendance->clearCheckinMark($user, $rehearsalId)) {
                $bot->answerCallbackQuery(text: $deps->texts->get('checkin.cannot_edit_manual'), show_alert: true);

                return;
            }
        }

        $deps->flows->clear($telegramId);
        $bot->answerCallbackQuery();

        if ($replacedAttended) {
            $deps->flows->set($telegramId, 'checkin_edit', [
                'rehearsal_id' => $rehearsalId,
                'replaced_attended' => true,
            ]);
        }

        CallbackUi::editCheckinPrompt(
            $bot,
            $deps->messages->checkinQuestionText(),
            $deps->messages->checkinKeyboard($rehearsalId),
        );
    }

    /**
     * @return array{user: array<string, mixed>, rehearsal: array<string, mixed>}|null
     */
    private static function loadCheckinContext(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        int $rehearsalId,
    ): ?array {
        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::ACTIVE) {
            $bot->answerCallbackQuery();

            return null;
        }

        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            return null;
        }

        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null) {
            $bot->answerCallbackQuery();

            return null;
        }

        if ($deps->checkInSessions->isClosed($rehearsalId)) {
            $bot->answerCallbackQuery(text: $deps->texts->get('checkin.already_closed'), show_alert: true);
            CallbackUi::clearKeyboard($bot);

            return null;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config)->setTimezone($tz);
        $ends = new \DateTimeImmutable((string) $rehearsal['ends_at'], $tz);

        if ($now >= $ends) {
            $bot->answerCallbackQuery(text: $deps->texts->get('checkin.already_closed'), show_alert: true);
            CallbackUi::clearKeyboard($bot);

            return null;
        }

        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        if ($now < $starts) {
            $bot->answerCallbackQuery();

            return null;
        }

        return ['user' => $user, 'rehearsal' => $rehearsal];
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

        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

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

        $deps->flows->set($telegramId, 'absence_reason', [
            'rehearsal_id' => $rehearsalId,
            'source' => AttendanceSource::ABSENCE_BUTTON,
        ]);

        $startsLabel = ScheduleTime::formatLocalDate($starts, $tz) . ' '
            . ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz);
        $bot->sendMessage(
            $deps->texts->get('absence.rehearsal_picked', ['rehearsal' => $startsLabel])
                . "\n\n"
                . $deps->texts->get('absence.ask_reason_required'),
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

        if ($source === AttendanceSource::ABSENCE_BUTTON) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);

            return true;
        }

        $bot->answerCallbackQuery();
        CallbackUi::finalizeWithAnswer($bot, $deps->texts->get('common.inline_answer_no_reason'));
        AttendanceMemberHandler::finishReasonWithoutReason($bot, $deps, $telegramId, $source, $rehearsalId);

        return true;
    }
}