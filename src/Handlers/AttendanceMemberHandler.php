<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
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
            $bot->sendMessage($deps->texts->get('absence.ask_reason_optional'));

            return true;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $deps->attendance->appendReason($rehearsalId, (int) $user['id'], $reason);

        if ($source === AttendanceSource::DAY_BEFORE) {
            $deps->conductor->forwardDayBeforeReason($user, $rehearsal, $reason, $tz);
        } else {
            $deps->conductor->notifyPlannedAbsence($user, $rehearsal, $reason, $tz);
        }

        $deps->flows->clear($telegramId);
        $bot->sendMessage($deps->texts->get('absence.confirmed'));

        return true;
    }

    public static function finishReasonSkip(
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

        $tz = new \DateTimeZone($deps->config->timezone);
        if ($source === AttendanceSource::DAY_BEFORE) {
            $deps->conductor->forwardDayBeforeReason($user, $rehearsal, '—', $tz);
        } else {
            $deps->conductor->notifyPlannedAbsence($user, $rehearsal, '—', $tz);
            $bot->sendMessage($deps->texts->get('absence.confirmed'));
        }

        $deps->flows->clear($telegramId);
    }
}
