<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserActionRepository;
use App\Repositories\UserFlowRepository;
use App\Support\ErrorLog;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class UserActionRecorder
{
    public function __construct(
        private readonly UserActionRepository $actions,
        private readonly TextRepository $texts,
        private readonly UserFlowRepository $flows,
    ) {
    }

    public function record(Nutgram $bot): void
    {
        $user = $bot->user();
        if ($user === null || ($user->is_bot ?? false)) {
            return;
        }

        $telegramId = (int) $user->id;
        if ($telegramId <= 0) {
            return;
        }

        if ($this->shouldSkipForHelpFlow($telegramId, $bot)) {
            return;
        }

        $ctx = ErrorLog::contextFromNutgram($bot);
        $updateType = isset($ctx['update_type']) ? (string) $ctx['update_type'] : null;
        $action = $this->describe($bot);
        if ($action === '') {
            return;
        }

        try {
            $this->actions->add($telegramId, $updateType, $action);
        } catch (\Throwable $e) {
            error_log('[user-actions] ' . $e->getMessage());
        }
    }

    private function shouldSkipForHelpFlow(int $telegramId, Nutgram $bot): bool
    {
        if ($bot->callbackQuery() !== null) {
            return false;
        }

        $message = $bot->message();
        if ($message === null) {
            return false;
        }

        $state = $this->flows->get($telegramId);
        if ($state === null) {
            return false;
        }

        $flow = (string) ($state['flow'] ?? '');

        return in_array($flow, [ErrorReportService::FLOW_HELP, ErrorReportService::FLOW_COMMENT], true);
    }

    private function describe(Nutgram $bot): string
    {
        $callback = $bot->callbackQuery();
        if ($callback !== null) {
            return $this->describeCallback($callback->data ?? '', $callback->message);
        }

        $message = $bot->message();
        if ($message === null) {
            return '';
        }

        $text = trim((string) ($message->text ?? ''));
        if ($text !== '') {
            return $this->describeText($text);
        }

        return $this->describeAttachment($message);
    }

    private function describeText(string $text): string
    {
        if (str_starts_with($text, '/')) {
            return mb_substr($text, 0, 200);
        }

        if ($this->isMenuButton($text)) {
            return $text;
        }

        $length = mb_strlen($text);

        return 'текст (' . $length . ' симв.)';
    }

    private function describeCallback(?string $data, ?Message $message): string
    {
        $data = (string) ($data ?? '');
        if ($data === '') {
            return '';
        }

        $label = $this->callbackButtonText($data, $message);
        if ($label !== null && $label !== '') {
            return $label;
        }

        return mb_substr($data, 0, 200);
    }

    private function callbackButtonText(string $data, ?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $keyboard = $message->reply_markup?->inline_keyboard;
        if (is_array($keyboard)) {
            foreach ($keyboard as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $button) {
                    if ($button instanceof InlineKeyboardButton) {
                        if (($button->callback_data ?? '') === $data) {
                            return (string) ($button->text ?? '');
                        }

                        continue;
                    }

                    if (is_array($button) && ($button['callback_data'] ?? '') === $data) {
                        return (string) ($button['text'] ?? '');
                    }
                }
            }
        }

        return null;
    }

    private function describeAttachment(Message $message): string
    {
        if ($message->voice !== null) {
            return 'голосовое';
        }
        if ($message->audio !== null) {
            return 'аудио';
        }
        if ($message->document !== null) {
            return 'документ';
        }
        if ($message->photo !== null && $message->photo !== []) {
            return 'фото';
        }
        if ($message->video !== null) {
            return 'видео';
        }
        if ($message->video_note !== null) {
            return 'видеосообщение';
        }
        if ($message->sticker !== null) {
            return 'стикер';
        }

        return '';
    }

    private function isMenuButton(string $text): bool
    {
        foreach ($this->menuLabels() as $label) {
            if ($label === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function menuLabels(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $labels = [];
        foreach ([
            'schedule',
            'wont_come',
            'find_song',
            'my_recordings',
            'my_profile',
            'admin_schedule',
            'admin_attendance',
            'admin_payment',
            'admin_history',
            'admin_helpers',
            'admin_members',
            'admin_materials',
            'assign_conductor',
            'about_bot',
            'admin_chat',
        ] as $key) {
            try {
                $labels[] = $this->texts->get('menu.' . $key);
            } catch (\InvalidArgumentException) {
            }
        }

        $cache = $labels;

        return $cache;
    }
}