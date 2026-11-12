<?php

declare(strict_types=1);

namespace App\Services;

use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class AttendanceMessageFactory
{
    public function __construct(
        private readonly TextRepository $texts,
    ) {
    }

    public function dayBeforeKeyboard(int $rehearsalId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($this->texts->get('buttons.yes'), callback_data: 'att:db:' . $rehearsalId . ':y'),
            InlineKeyboardButton::make($this->texts->get('buttons.no'), callback_data: 'att:db:' . $rehearsalId . ':n'),
        );
    }

    public function checkinKeyboard(int $rehearsalId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($this->texts->get('buttons.yes'), callback_data: 'att:ci:' . $rehearsalId . ':y'),
            InlineKeyboardButton::make($this->texts->get('buttons.no'), callback_data: 'att:ci:' . $rehearsalId . ':n'),
        );
    }

    /**
     * @param array<string, mixed> $rehearsal
     */
    public function dayBeforeText(array $rehearsal, \DateTimeZone $tz): string
    {
        $starts = new \DateTimeImmutable((string) $rehearsal['starts_at'], $tz);

        return $this->texts->get('reminder.day_before', [
            'date' => ScheduleTime::formatLocalDate($starts, $tz),
            'time' => ScheduleTime::formatRehearsalTimeRange($rehearsal, $tz),
            'address' => (string) $rehearsal['address'],
        ]);
    }

    public function reasonPromptKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($this->texts->get('buttons.skip_reason'), callback_data: 'att:sk'),
        );
    }
}
