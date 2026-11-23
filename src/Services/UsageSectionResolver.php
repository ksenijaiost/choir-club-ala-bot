<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserFlowRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class UsageSectionResolver
{
    public function __construct(
        private readonly TextRepository $texts,
    ) {
    }

    public function resolveSection(Nutgram $bot, UserFlowRepository $flows, int $telegramId): ?string
    {
        $callback = $bot->callbackQuery();
        if ($callback !== null) {
            $data = (string) ($callback->data ?? '');
            if ($data !== '') {
                return $this->resolveCallbackPrefix($data);
            }
        }

        $message = $bot->message();
        if ($message === null) {
            return null;
        }

        $text = trim((string) ($message->text ?? ''));
        if ($text === '') {
            return $this->resolveFlowSection($flows, $telegramId);
        }

        if (str_starts_with($text, '/')) {
            $command = strtolower(ltrim($text, '/'));
            if (str_contains($command, '@')) {
                $command = explode('@', $command, 2)[0];
            }
            $command = explode(' ', $command, 2)[0];

            return $this->resolveCommand($command);
        }

        $menuSection = $this->resolveMenuLabel($text);
        if ($menuSection !== null) {
            return $menuSection;
        }

        return $this->resolveFlowSection($flows, $telegramId);
    }

    private function resolveCommand(string $command): ?string
    {
        return match ($command) {
            'start', 'help' => null,
            'song' => 'find_song',
            'recordings' => 'my_recordings',
            'profile' => 'profile',
            'about' => 'about',
            'stats' => 'stats',
            'broadcast', 'polls' => 'broadcast',
            'programs' => 'programs',
            'schedule' => 'schedule',
            default => null,
        };
    }

    private function resolveMenuLabel(string $text): ?string
    {
        $map = [
            'menu.schedule' => 'schedule',
            'menu.wont_come' => 'wont_come',
            'menu.find_song' => 'find_song',
            'menu.my_recordings' => 'my_recordings',
            'menu.my_profile' => 'profile',
            'menu.about_bot' => 'about',
            'menu.payment' => 'payment',
            'menu.admin_schedule' => 'admin_schedule',
            'menu.admin_attendance' => 'admin_attendance',
            'menu.admin_payment' => 'admin_payment',
            'menu.admin_history' => 'admin_history',
            'menu.admin_helpers' => 'admin_helpers',
            'menu.admin_members' => 'admin_members',
            'menu.admin_materials' => 'admin_materials',
            'menu.assign_conductor' => 'admin_members',
            'menu.admin_chat' => 'admin_materials',
            'menu.broadcast' => 'broadcast',
            'menu.broadcasts_list' => 'broadcast',
            'menu.programs' => 'programs',
        ];

        foreach ($map as $key => $section) {
            try {
                if ($text === $this->texts->get($key)) {
                    return $section;
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return null;
    }

    private function resolveCallbackPrefix(string $data): ?string
    {
        if (str_starts_with($data, 'snd:my') || $data === 'snd:my') {
            return 'my_recordings';
        }
        if (str_starts_with($data, 'prg:mr')) {
            return 'my_recordings';
        }
        if (str_starts_with($data, 'prg:mo')) {
            return 'programs';
        }
        if (str_starts_with($data, 'prg:')) {
            return 'programs';
        }
        if (str_starts_with($data, 'snd:')) {
            return 'find_song';
        }
        if (str_starts_with($data, 'mat:')) {
            return 'admin_materials';
        }
        if (str_starts_with($data, 'pay:rt:') || str_starts_with($data, 'pay:adm')
            || str_starts_with($data, 'pay:cf:') || str_starts_with($data, 'pa:')) {
            return 'admin_payment';
        }
        if (str_starts_with($data, 'pay:')) {
            return 'payment';
        }
        if (str_starts_with($data, 'att:') || str_starts_with($data, 'chk:')) {
            return 'wont_come';
        }
        if (str_starts_with($data, 'atd:')) {
            return 'admin_attendance';
        }
        if (str_starts_with($data, 'sch:')) {
            return 'admin_schedule';
        }
        if (str_starts_with($data, 'hst:')) {
            return 'admin_history';
        }
        if (str_starts_with($data, 'hp:') || str_starts_with($data, 'oc:')) {
            return 'admin_helpers';
        }
        if (str_starts_with($data, 'bl:') || str_starts_with($data, 'rp:')) {
            return 'admin_members';
        }
        if (str_starts_with($data, 'bc:r:') || str_starts_with($data, 'bc:v:')) {
            return 'broadcast_answer';
        }
        if (str_starts_with($data, 'bc:')) {
            return 'broadcast';
        }
        if (str_starts_with($data, 'prf:')) {
            return 'profile';
        }
        if (str_starts_with($data, 'ab:')) {
            return 'about';
        }
        if (str_starts_with($data, 'stats:')) {
            return 'stats';
        }

        return null;
    }

    private function resolveFlowSection(UserFlowRepository $flows, int $telegramId): ?string
    {
        $state = $flows->get($telegramId);
        if ($state === null) {
            return null;
        }

        $flow = (string) ($state['flow'] ?? '');

        return match ($flow) {
            'absence_reason', 'checkin_edit' => 'wont_come',
            'payment_defer_comment' => 'payment',
            'song_search' => 'find_song',
            'profile_rename' => 'profile',
            'broadcast_wizard', 'broadcast_custom_answer' => 'broadcast',
            'program_create_title', 'program_create_date', 'program_rename', 'program_set_date' => 'programs',
            'payment_reminder_edit' => 'admin_payment',
            'material_upload' => 'admin_materials',
            default => null,
        };
    }
}