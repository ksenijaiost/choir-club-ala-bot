<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Domain\UserRole;
use App\Services\ScheduleTime;
use App\Telegram\CallbackUi;
use App\Telegram\MenuReplyHelper;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AttendanceMemberHandler
{
    public static function openWontComePicker(Nutgram $bot, AttendanceDeps $deps): void
    {
        $tz = new \DateTimeZone($deps->config->timezone);
        $now = ScheduleTime::toStorage(Clock::now($deps->config)->setTimezone($tz));
        $rows = $deps->rehearsals->listUpcomingScheduled($now, 8);

        if ($rows === []) {
            $bot->sendMessage($deps->texts->get('absence.no_upcoming'));

            return;
        }

        $lines = [$deps->texts->get('absence.choose_rehearsal')];
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $starts = new \DateTimeImmutable((string) $row['starts_at'], $tz);
            $label = ScheduleTime::formatLocalDate($starts, $tz) . ' '
                . ScheduleTime::formatRehearsalTimeRange($row, $tz);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'att:wx:' . $id),
            );
        }

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== 'absence_reason') {
            return false;
        }

        $ctx = $state['context'];
        $rehearsalId = (int) ($ctx['rehearsal_id'] ?? 0);
        $source = (string) ($ctx['source'] ?? AttendanceSource::ABSENCE_BUTTON);
        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        $user = $deps->users->findByTelegramId($telegramId);
        if ($rehearsal === null || $user === null) {
            $deps->flows->clear($telegramId);

            return true;
        }

        if (!UserRole::participatesInAttendance((string) ($user['role'] ?? ''))) {
            $deps->flows->clear($telegramId);

            return true;
        }

        $reason = trim($text);
        if ($reason === '') {
            $bot->sendMessage(
                $source === AttendanceSource::ABSENCE_BUTTON
                    ? $deps->texts->get('absence.ask_reason_required')
                    : $deps->texts->get('absence.ask_reason_optional'),
            );

            return true;
        }

        self::completeAbsenceWithReason($bot, $deps, $telegramId, $user, $rehearsal, $source, $rehearsalId, $reason, $ctx);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $rehearsal
     */
    public static function completeAbsenceWithReason(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        array $user,
        array $rehearsal,
        string $source,
        int $rehearsalId,
        string $reason,
        array $flowContext = [],
    ): void {
        $tz = new \DateTimeZone($deps->config->timezone);
        $userId = (int) $user['id'];
        $existing = $deps->marks->find($rehearsalId, $userId);
        if ($existing !== null && ($existing['mark'] ?? '') !== AttendanceMark::UNEXCUSED) {
            $deps->marks->appendReason($rehearsalId, $userId, $reason);
        } else {
            $deps->attendance->recordWarned($user, $rehearsalId, $source, $reason);
        }

        if ($source === AttendanceSource::DAY_BEFORE) {
            $deps->rsvp->upsert($rehearsalId, $userId, \App\Domain\RehearsalRsvpAnswer::NO);
            $deps->conductor->forwardDayBeforeReason($user, $rehearsal, $reason, $tz);
        } elseif ($source === AttendanceSource::ABSENCE_BUTTON) {
            $deps->conductor->notifyPlannedAbsence($user, $rehearsal, $reason, $tz);
        } elseif ($source === AttendanceSource::CHECKIN && self::flowReplacedAttended($flowContext)) {
            $deps->conductor->notifyCheckinChangedToAbsent($user, $reason);
        }

        if ($source === AttendanceSource::CHECKIN) {
            self::finalizeCheckinPromptAfterAbsence($bot, $deps, $flowContext, $rehearsalId, $deps->texts->get('common.inline_answer_no'));
        }

        $deps->flows->clear($telegramId);
        MenuReplyHelper::sendWithMenu($bot, $deps->texts, $user, $deps->texts->get('absence.confirmed'));
    }

    public static function finishReasonWithoutReason(
        Nutgram $bot,
        AttendanceDeps $deps,
        int $telegramId,
        string $source,
        int $rehearsalId,
    ): void {
        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        $user = $deps->users->findByTelegramId($telegramId);
        if ($rehearsal === null || $user === null) {
            $deps->flows->clear($telegramId);

            return;
        }

        $state = $deps->flows->get($telegramId);
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];

        $deps->attendance->recordUnexcused($user, $rehearsalId, $source);

        if ($source === AttendanceSource::DAY_BEFORE) {
            $deps->rsvp->upsert($rehearsalId, (int) $user['id'], \App\Domain\RehearsalRsvpAnswer::NO);
        }

        if ($source === AttendanceSource::CHECKIN && self::flowReplacedAttended($ctx)) {
            $deps->conductor->notifyCheckinChangedToAbsent($user, '');
        }

        if ($source === AttendanceSource::CHECKIN) {
            self::finalizeCheckinPromptAfterAbsence(
                $bot,
                $deps,
                $ctx,
                $rehearsalId,
                $deps->texts->get('common.inline_answer_no_reason'),
            );
        }

        $deps->flows->clear($telegramId);

        if ($source === AttendanceSource::CHECKIN) {
            MenuReplyHelper::sendWithMenu($bot, $deps->texts, $user, $deps->texts->get('checkin.thanks_no'));
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function finalizeCheckinPromptAfterAbsence(
        Nutgram $bot,
        AttendanceDeps $deps,
        array $ctx,
        int $rehearsalId,
        string $answerLine,
    ): void {
        $chatId = (int) ($ctx['checkin_chat_id'] ?? 0);
        $messageId = (int) ($ctx['checkin_message_id'] ?? 0);
        if ($chatId <= 0 || $messageId <= 0) {
            return;
        }

        CallbackUi::finalizeCheckinAt(
            $bot,
            $chatId,
            $messageId,
            $deps->messages->checkinQuestionText(),
            $answerLine,
            $deps->messages->checkinEditKeyboard($rehearsalId),
        );
    }

    /**
     * @param array<string, mixed> $flowContext
     */
    private static function flowReplacedAttended(array $flowContext): bool
    {
        return (bool) ($flowContext['replaced_attended'] ?? false);
    }
}
