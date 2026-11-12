<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\AttendanceMark;
use App\Domain\AttendanceSource;
use App\Services\ScheduleTime;
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

        $reason = trim($text);
        if ($reason === '') {
            $bot->sendMessage(
                $source === AttendanceSource::ABSENCE_BUTTON
                    ? $deps->texts->get('absence.ask_reason_required')
                    : $deps->texts->get('absence.ask_reason_optional'),
            );

            return true;
        }

        self::completeAbsenceWithReason($bot, $deps, $telegramId, $user, $rehearsal, $source, $rehearsalId, $reason);

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
            $deps->conductor->forwardDayBeforeReason($user, $rehearsal, $reason, $tz);
        } elseif ($source === AttendanceSource::ABSENCE_BUTTON) {
            $deps->conductor->notifyPlannedAbsence($user, $rehearsal, $reason, $tz);
        }

        $deps->flows->clear($telegramId);
        $bot->sendMessage($deps->texts->get('absence.confirmed'));
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

        $deps->attendance->recordUnexcused($user, $rehearsalId, $source);
        $deps->flows->clear($telegramId);

        if ($source === AttendanceSource::CHECKIN) {
            $bot->sendMessage($deps->texts->get('checkin.thanks_no'));
        }
    }
}
