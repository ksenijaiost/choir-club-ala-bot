<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Domain\AttendanceMark;
use App\Services\AccessControl;
use App\Services\AttendanceHistoryPresenter;
use App\Services\ScheduleTime;
use App\Telegram\CallbackUi;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AttendanceAdminHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function openHome(Nutgram $bot, TextRepository $texts, AccessControl $access, array $actor): void
    {
        if (!$access->hasConductorPrivileges($actor)) {
            $bot->sendMessage($texts->get('admin.only_conductor_or_owner'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($texts->get('attendance.by_rehearsal'), callback_data: 'atd:rh'),
                InlineKeyboardButton::make($texts->get('attendance.by_member'), callback_data: 'atd:ms'),
            );

        $bot->sendMessage($texts->get('attendance.admin_home'), reply_markup: $keyboard);
    }

    /**
     * @param array<string, mixed> $actor
     */
    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        AttendanceDeps $deps,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'atd:')) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            $bot->answerCallbackQuery(text: $texts->get('admin.only_conductor_or_owner'), show_alert: true);

            return true;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config)->setTimezone($tz);
        $from = ScheduleTime::toStorage($now->modify('-90 days'));

        if ($data === 'atd:rh') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendRehearsalPicker($bot, $texts, $deps, $from);

            return true;
        }

        if ($data === 'atd:ms') {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMemberPicker($bot, $texts, $deps);

            return true;
        }

        if (preg_match('/^atd:rh:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendRehearsalAttendance($bot, $texts, $deps, $deps->history, $tz, (int) $m[1]);

            return true;
        }

        if (preg_match('/^atd:mb:(\d+):(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMarkPicker(
                $bot,
                $texts,
                $deps,
                (int) $m[1],
                (int) $m[2],
            );

            return true;
        }

        if (preg_match('/^atd:mk:(\d+):(\d+):(a|w|u)$/', $data, $m) === 1) {
            self::applyManualMark($bot, $texts, $deps, (int) $m[1], (int) $m[2], $m[3]);
            $bot->answerCallbackQuery(text: $texts->get('attendance.manual_saved'));
            $answer = match ($m[3]) {
                'a' => $texts->get('attendance.inline_mark_attended'),
                'w' => $texts->get('attendance.inline_mark_warned'),
                default => $texts->get('attendance.inline_mark_unexcused'),
            };
            CallbackUi::finalizeWithAnswer($bot, $answer);

            return true;
        }

        if (preg_match('/^atd:ms:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            CallbackUi::clearKeyboard($bot);
            self::sendMemberHistory($bot, $texts, $deps, $deps->history, $tz, (int) $m[1]);

            return true;
        }

        return false;
    }

    private static function sendRehearsalPicker(
        Nutgram $bot,
        TextRepository $texts,
        AttendanceDeps $deps,
        string $fromLocal,
    ): void {
        $rows = $deps->rehearsals->listFrom($fromLocal, 25);
        if ($rows === []) {
            $bot->sendMessage($texts->get('attendance.no_rehearsals'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $tz = new \DateTimeZone($deps->config->timezone);
            $starts = new \DateTimeImmutable((string) $row['starts_at'], $tz);
            $label = ScheduleTime::formatLocalDate($starts, $tz) . ' '
                . ScheduleTime::formatRehearsalTimeRange($row, $tz);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'atd:rh:' . $id),
            );
        }

        $bot->sendMessage($texts->get('attendance.rehearsal_pick'), reply_markup: $keyboard);
    }

    private static function sendRehearsalAttendance(
        Nutgram $bot,
        TextRepository $texts,
        AttendanceDeps $deps,
        AttendanceHistoryPresenter $presenter,
        \DateTimeZone $tz,
        int $rehearsalId,
    ): void {
        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null) {
            $bot->sendMessage($texts->get('errors.generic'));

            return;
        }

        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);
        $lines = [
            $texts->get('attendance.rehearsal_card', [
                'date' => ScheduleTime::formatLocalDate($starts, $tz),
                'time' => ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz),
                'address' => (string) $rehearsal['address'],
            ]),
        ];

        $marksByUser = [];
        foreach ($deps->marks->listForRehearsal($rehearsalId) as $markRow) {
            $marksByUser[(int) $markRow['user_id']] = (string) $markRow['mark'];
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($deps->users->listActiveParticipants() as $user) {
            $uid = (int) $user['id'];
            $name = (string) ($user['display_name'] ?? '');
            $mark = $marksByUser[$uid] ?? null;
            $lines[] = '• ' . $name . ' — ' . $presenter->markLabel($mark);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $name,
                    callback_data: 'atd:mb:' . $rehearsalId . ':' . $uid,
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make($texts->get('attendance.back'), callback_data: 'atd:rh'),
        );

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }

    private static function sendMarkPicker(
        Nutgram $bot,
        TextRepository $texts,
        AttendanceDeps $deps,
        int $rehearsalId,
        int $userId,
    ): void {
        $user = $deps->users->findById($userId);
        if ($user === null) {
            $bot->sendMessage($texts->get('errors.generic'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $texts->get('attendance.mark_attended'),
                    callback_data: 'atd:mk:' . $rehearsalId . ':' . $userId . ':a',
                ),
                InlineKeyboardButton::make(
                    $texts->get('attendance.mark_warned'),
                    callback_data: 'atd:mk:' . $rehearsalId . ':' . $userId . ':w',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    $texts->get('attendance.mark_unexcused'),
                    callback_data: 'atd:mk:' . $rehearsalId . ':' . $userId . ':u',
                ),
            )
            ->addRow(
                InlineKeyboardButton::make($texts->get('attendance.back'), callback_data: 'atd:rh:' . $rehearsalId),
            );

        $bot->sendMessage(
            $texts->get('attendance.manual_pick', ['name' => (string) ($user['display_name'] ?? '')]),
            reply_markup: $keyboard,
        );
    }

    private static function applyManualMark(
        Nutgram $bot,
        TextRepository $texts,
        AttendanceDeps $deps,
        int $rehearsalId,
        int $userId,
        string $code,
    ): void {
        $mark = match ($code) {
            'a' => AttendanceMark::ATTENDED,
            'w' => AttendanceMark::WARNED,
            'u' => AttendanceMark::UNEXCUSED,
            default => AttendanceMark::ATTENDED,
        };

        $streak = $deps->attendance->recordManual($userId, $rehearsalId, $mark);
        $user = $deps->users->findById($userId);
        if ($user === null) {
            return;
        }

        $bot->sendMessage($texts->get('attendance.manual_result', [
            'name' => (string) ($user['display_name'] ?? ''),
            'streak' => (string) $streak,
        ]));
    }

    private static function sendMemberPicker(Nutgram $bot, TextRepository $texts, AttendanceDeps $deps): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($deps->users->listActiveParticipants() as $user) {
            $uid = (int) $user['id'];
            $name = (string) ($user['display_name'] ?? '');
            $streak = (int) ($user['consecutive_unexcused'] ?? 0);
            $label = $name . ($streak > 0 ? ' (' . $streak . ')' : '');
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'atd:ms:' . $uid),
            );
        }

        $bot->sendMessage($texts->get('attendance.member_pick'), reply_markup: $keyboard);
    }

    private static function sendMemberHistory(
        Nutgram $bot,
        TextRepository $texts,
        AttendanceDeps $deps,
        AttendanceHistoryPresenter $presenter,
        \DateTimeZone $tz,
        int $userId,
    ): void {
        $user = $deps->users->findById($userId);
        if ($user === null) {
            $bot->sendMessage($texts->get('errors.generic'));

            return;
        }

        $streak = (int) ($user['consecutive_unexcused'] ?? 0);
        $lines = [
            $texts->get('attendance.history_header', ['name' => (string) ($user['display_name'] ?? '')]),
            $texts->get('attendance.streak_line', ['streak' => (string) $streak]),
        ];

        $history = $deps->marks->listForUserRecent($userId, 20);
        if ($history === []) {
            $lines[] = $texts->get('attendance.history_empty');
        } else {
            foreach ($history as $row) {
                $lines[] = $presenter->formatRehearsalLine($row, $tz, (string) ($row['mark'] ?? ''));
            }
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($texts->get('attendance.back'), callback_data: 'atd:ms'),
        );

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }
}
