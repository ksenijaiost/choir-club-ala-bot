<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BroadcastRepository;
use App\Text\TextRepository;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class BroadcastKeyboardBuilder
{
    private const CHECKMARK = '✓ ';

    public function __construct(private readonly TextRepository $texts)
    {
    }

    public function buildTextKeyboard(int $broadcastId, bool $alreadyRead): InlineKeyboardMarkup
    {
        if ($alreadyRead) {
            return InlineKeyboardMarkup::make();
        }

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('broadcast.button_read'),
                callback_data: 'bc:rd:' . $broadcastId,
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $options
     * @param list<int> $selectedOptionIds
     */
    public function buildPollKeyboard(
        array $broadcast,
        array $options,
        array $selectedOptionIds,
        bool $hasCustomAnswer,
        bool $allowCustom,
    ): InlineKeyboardMarkup {
        if (($broadcast['status'] ?? '') !== BroadcastRepository::STATUS_OPEN) {
            return InlineKeyboardMarkup::make();
        }

        $broadcastId = (int) ($broadcast['id'] ?? 0);
        $multi = (bool) ((int) ($broadcast['multi_choice'] ?? 0));
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($options as $option) {
            $optionId = (int) ($option['id'] ?? 0);
            $label = (string) ($option['label'] ?? '');
            $selected = in_array($optionId, $selectedOptionIds, true);
            if ($selected) {
                $prefix = self::CHECKMARK;
            } elseif ($multi) {
                $prefix = '☐ ';
            } else {
                $prefix = '';
            }
            $buttonLabel = $prefix . $label;
            if (mb_strlen($buttonLabel) > 64) {
                $buttonLabel = mb_substr($buttonLabel, 0, 61) . '…';
            }
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $buttonLabel,
                    callback_data: 'bc:pv:' . $broadcastId . ':' . $optionId,
                ),
            );
        }

        if ($allowCustom) {
            $customLabel = $this->texts->get('broadcast.button_custom');
            if ($hasCustomAnswer) {
                $customLabel = self::CHECKMARK . $customLabel;
            }
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $customLabel,
                    callback_data: 'bc:cs:' . $broadcastId,
                ),
            );
        }

        return $keyboard;
    }

    /**
     * @param list<array<string, mixed>> $recent
     */
    public function buildAdminListKeyboard(array $recent): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($recent as $row) {
            $id = (int) ($row['id'] ?? 0);
            $kind = (string) ($row['kind'] ?? '');
            $text = (string) ($row['text'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $snippet = mb_strlen($text) > 36 ? mb_substr($text, 0, 33) . '…' : $text;
            $prefix = $kind === BroadcastRepository::KIND_POLL ? '📊 ' : '📢 ';
            $closed = $status === BroadcastRepository::STATUS_CLOSED ? ' · ✓' : '';
            $label = $prefix . $snippet . $closed;
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'bc:st:' . $id),
            );
        }

        return $keyboard;
    }

    public function buildCloseConfirmKeyboard(int $broadcastId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('broadcast.close_confirm_yes'),
                    callback_data: 'bc:cy:' . $broadcastId,
                ),
                InlineKeyboardButton::make(
                    $this->texts->get('broadcast.close_confirm_no'),
                    callback_data: 'bc:cn:' . $broadcastId,
                ),
            );
    }

    public function buildStatusActionsKeyboard(int $broadcastId, bool $isOpen): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                $this->texts->get('broadcast.refresh_status'),
                callback_data: 'bc:rf:' . $broadcastId,
            ),
        );
        if ($isOpen) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->texts->get('broadcast.close_button'),
                    callback_data: 'bc:cl:' . $broadcastId,
                ),
            );
        }

        return $keyboard;
    }
}