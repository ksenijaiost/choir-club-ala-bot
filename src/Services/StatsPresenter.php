<?php

declare(strict_types=1);

namespace App\Services;

use App\Clock;
use App\Config;
use App\Repositories\UsageStatsRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class StatsPresenter
{
    public function __construct(
        private readonly UsageStatsRepository $stats,
        private readonly TextRepository $texts,
        private readonly Config $config,
    ) {
    }

    public function build(string $period): array
    {
        [$fromDay, $toDay, $periodKey] = $this->periodBounds($period);

        $rows = $this->stats->aggregateBySection($fromDay, $toDay);
        $participants = $this->stats->activeParticipantsCount($fromDay, $toDay);

        $lines = [
            $this->texts->get('usage_stats.header', [
                'period' => $this->texts->get('usage_stats.period_' . $periodKey),
            ]),
            $this->texts->get('usage_stats.participants', ['count' => (string) $participants]),
            '',
        ];

        if ($rows === []) {
            $lines[] = $this->texts->get('usage_stats.empty');
        } else {
            foreach ($rows as $row) {
                $section = (string) ($row['section'] ?? '');
                $label = $this->sectionLabel($section);
                $lines[] = $this->texts->get('usage_stats.row', [
                    'section' => $label,
                    'uses' => (string) ($row['uses'] ?? 0),
                    'users' => (string) ($row['users'] ?? 0),
                ]);
            }
        }

        return [
            'text' => implode("\n", $lines),
            'keyboard' => $this->periodKeyboard(),
        ];
    }

    public function periodKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($this->texts->get('usage_stats.button_7d'), callback_data: 'stats:7'),
                InlineKeyboardButton::make($this->texts->get('usage_stats.button_30d'), callback_data: 'stats:30'),
            )
            ->addRow(
                InlineKeyboardButton::make($this->texts->get('usage_stats.button_all'), callback_data: 'stats:all'),
            );
    }

    private function sectionLabel(string $section): string
    {
        $key = 'usage_stats.section_' . $section;

        try {
            return $this->texts->get($key);
        } catch (\InvalidArgumentException) {
            return $section;
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function periodBounds(string $period): array
    {
        $tz = new \DateTimeZone($this->config->timezone);
        $today = Clock::now($this->config)->setTimezone($tz)->format('Y-m-d');

        return match ($period) {
            '7' => [
                Clock::now($this->config)->setTimezone($tz)->modify('-6 days')->format('Y-m-d'),
                $today,
                '7',
            ],
            '30' => [
                Clock::now($this->config)->setTimezone($tz)->modify('-29 days')->format('Y-m-d'),
                $today,
                '30',
            ],
            default => ['1970-01-01', $today, 'all'],
        };
    }
}