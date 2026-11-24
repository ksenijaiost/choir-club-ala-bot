<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BroadcastRepository;
use App\Repositories\UserRepository;
use App\Telegram\TelegramHtml;
use App\Telegram\TelegramMessageChunker;
use App\Text\TextRepository;

final class BroadcastStatusPresenter
{
    public const CHUNK_LIMIT = 4000;

    public function __construct(
        private readonly TextRepository $texts,
        private readonly BroadcastRepository $broadcasts,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<string>
     */
    public function statusChunks(int $broadcastId): array
    {
        $broadcast = $this->broadcasts->find($broadcastId);
        if ($broadcast === null) {
            return [$this->texts->get('broadcast.not_found')];
        }

        $kind = (string) ($broadcast['kind'] ?? '');
        if ($kind === BroadcastRepository::KIND_TEXT) {
            return $this->textStatusChunks($broadcast);
        }

        return $this->pollStatusChunks($broadcast);
    }

    /**
     * @param array<string, mixed> $broadcast
     * @return list<string>
     */
    private function textStatusChunks(array $broadcast): array
    {
        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $recipients = $this->broadcasts->listRecipients($broadcastId);
        $names = $this->nameCounts($recipients);

        $blocks = [$this->texts->get('broadcast.status_text_header', [
            'text' => TelegramHtml::escape((string) ($broadcast['text'] ?? '')),
            'status' => TelegramHtml::escape($this->statusLabel($broadcast)),
        ])];

        $read = [];
        $unread = [];
        $failed = [];
        foreach ($recipients as $row) {
            $line = $this->formatPersonLine($row, $names);
            if (($row['delivery_error'] ?? null) !== null && ($row['delivery_error'] ?? '') !== '') {
                $reason = TelegramDeliveryReason::label($this->texts, (string) $row['delivery_error']);
                $failed[] = $this->texts->get('broadcast.status_failed_line', [
                    'name' => TelegramHtml::escape(trim((string) ($row['display_name'] ?? '')) ?: '#' . (int) ($row['user_id'] ?? 0)),
                    'reason' => TelegramHtml::escape($reason),
                ]);
            } elseif (($row['read_at'] ?? null) !== null && ($row['read_at'] ?? '') !== '') {
                $read[] = $line;
            } else {
                $unread[] = $line;
            }
        }

        $blocks[] = $this->texts->get('broadcast.status_read', ['names' => $this->joinOrDash($read)]);
        $blocks[] = $this->texts->get('broadcast.status_unread', ['names' => $this->joinOrDash($unread)]);
        if ($failed !== []) {
            $blocks[] = $this->texts->get('broadcast.status_delivery_failed', ['names' => implode("\n", $failed)]);
        }

        return TelegramMessageChunker::splitByBlocks($blocks, self::CHUNK_LIMIT);
    }

    /**
     * @param array<string, mixed> $broadcast
     * @return list<string>
     */
    private function pollStatusChunks(array $broadcast): array
    {
        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $options = $this->broadcasts->listOptions($broadcastId);
        $answers = $this->broadcasts->listAnswers($broadcastId);
        $recipients = $this->broadcasts->listRecipients($broadcastId);
        $participantIds = [];
        foreach ($this->users->listActiveParticipants() as $user) {
            $participantIds[(int) $user['id']] = true;
        }

        $names = $this->nameCounts(array_merge($recipients, $answers));
        $blocks = [$this->texts->get('broadcast.status_poll_header', [
            'question' => TelegramHtml::escape((string) ($broadcast['text'] ?? '')),
            'status' => TelegramHtml::escape($this->statusLabel($broadcast)),
        ])];

        foreach ($options as $option) {
            $optionId = (int) ($option['id'] ?? 0);
            $voters = [];
            foreach ($answers as $answer) {
                if ((int) ($answer['option_id'] ?? 0) === $optionId) {
                    $voters[] = $this->formatPersonLine($answer, $names);
                }
            }
            $blocks[] = $this->texts->get('broadcast.status_poll_option', [
                'option' => TelegramHtml::escape((string) ($option['label'] ?? '')),
                'names' => $this->joinOrDash($voters),
            ]);
        }

        $customLines = [];
        foreach ($answers as $answer) {
            if (($answer['option_id'] ?? null) !== null) {
                continue;
            }
            $custom = trim((string) ($answer['custom_text'] ?? ''));
            if ($custom === '') {
                continue;
            }
            $customLines[] = $this->formatPersonLine($answer, $names) . ': ' . TelegramHtml::escape($custom);
        }
        if ((bool) ((int) ($broadcast['allow_custom'] ?? 0))) {
            $blocks[] = $this->texts->get('broadcast.status_poll_custom', [
                'lines' => $customLines === [] ? '—' : implode("\n", $customLines),
            ]);
        }

        $answeredUserIds = [];
        foreach ($answers as $answer) {
            $answeredUserIds[(int) ($answer['user_id'] ?? 0)] = true;
        }
        $noAnswer = [];
        foreach ($recipients as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if (!isset($participantIds[$uid])) {
                continue;
            }
            if (!isset($answeredUserIds[$uid])) {
                if (($row['delivery_error'] ?? null) !== null && ($row['delivery_error'] ?? '') !== '') {
                    $reason = TelegramDeliveryReason::label($this->texts, (string) $row['delivery_error']);
                    $noAnswer[] = $this->texts->get('broadcast.status_failed_line', [
                        'name' => TelegramHtml::escape(trim((string) ($row['display_name'] ?? '')) ?: '#' . $uid),
                        'reason' => TelegramHtml::escape($reason),
                    ]);
                } else {
                    $noAnswer[] = $this->formatPersonLine($row, $names);
                }
            }
        }
        $blocks[] = $this->texts->get('broadcast.status_poll_no_answer', [
            'names' => $this->joinOrDash($noAnswer),
        ]);

        return TelegramMessageChunker::splitByBlocks($blocks, self::CHUNK_LIMIT);
    }

    /**
     * @param array<string, mixed> $broadcast
     */
    private function statusLabel(array $broadcast): string
    {
        return ($broadcast['status'] ?? '') === BroadcastRepository::STATUS_OPEN
            ? $this->texts->get('broadcast.status_open')
            : $this->texts->get('broadcast.status_closed');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function nameCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['display_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $nameCounts
     */
    private function formatPersonLine(array $row, array $nameCounts): string
    {
        $name = trim((string) ($row['display_name'] ?? ''));
        if ($name === '') {
            $name = '#' . (int) ($row['user_id'] ?? 0);
        }
        if (($nameCounts[$name] ?? 0) > 1) {
            $username = trim((string) ($row['telegram_username'] ?? ''));
            if ($username !== '') {
                $name .= ' @' . ltrim($username, '@');
            }
        }

        return '• ' . TelegramHtml::escape($name);
    }

    /**
     * @param list<string> $lines
     */
    private function joinOrDash(array $lines): string
    {
        return $lines === [] ? '—' : implode("\n", $lines);
    }
}