<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\BroadcastRepository;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\MenuReplyHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class BroadcastWizardHandler
{
    public const FLOW = 'broadcast_wizard';

    public const TEXT_BODY_MAX = 3900;

    /**
     * @param array<string, mixed> $actor
     */
    public static function start(Nutgram $bot, BroadcastDeps $deps, AccessControl $access, array $actor): void
    {
        if (!$access->canManageBroadcasts($actor)) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.forbidden'));

            return;
        }

        $telegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        $deps->flows->set($telegramId, self::FLOW, [
            'step' => 'kind',
            'kind' => null,
            'multi' => false,
            'allow_custom' => false,
            'options' => [],
        ]);
        self::presentStep($bot, $deps, $telegramId);
    }

    public static function handleCallback(
        Nutgram $bot,
        BroadcastDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'bc:w:')) {
            return false;
        }

        if (!$access->canManageBroadcasts($actor)) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('broadcast.forbidden'),
                'show_alert' => true,
            ]);

            return true;
        }

        $telegramId = (int) ($actor['telegram_id'] ?? 0);
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            SafeTelegram::answerCallbackQuery($bot);

            return true;
        }

        SafeTelegram::answerCallbackQuery($bot);
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];

        if ($data === 'bc:w:cn') {
            $deps->flows->clear($telegramId);
            CallbackUi::editCurrentMessage($bot, $deps->texts->get('broadcast.wizard_cancelled'), null, $deps->interactiveMenus, $telegramId);
            MenuReplyHelper::sendWithMenu($bot, $deps->texts, $actor, $deps->texts->get('broadcast.wizard_cancelled'));

            return true;
        }

        if ($data === 'bc:w:kt:t') {
            $ctx['kind'] = BroadcastRepository::KIND_TEXT;
            $ctx['step'] = 'body';
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'bc:w:kt:p') {
            $ctx['kind'] = BroadcastRepository::KIND_POLL;
            $ctx['step'] = 'body';
            $ctx['options'] = [];
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'bc:w:mu') {
            $ctx['multi'] = !((bool) ($ctx['multi'] ?? false));
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'bc:w:cu') {
            $ctx['allow_custom'] = !((bool) ($ctx['allow_custom'] ?? false));
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'bc:w:op:done') {
            $options = is_array($ctx['options'] ?? null) ? $ctx['options'] : [];
            if (count($options) < BroadcastRepository::MIN_OPTIONS) {
                self::presentStep($bot, $deps, $telegramId, $deps->texts->get('broadcast.wizard_need_options'));

                return true;
            }
            $ctx['step'] = 'preview';
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'bc:w:pr') {
            $ctx['step'] = 'preview';
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($data === 'bc:w:sn') {
            return self::sendBroadcast($bot, $deps, $actor, $telegramId, $ctx);
        }

        return true;
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        BroadcastDeps $deps,
        AccessControl $access,
        int $telegramId,
        string $text,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null || ($state['flow'] ?? '') !== self::FLOW) {
            return false;
        }

        $user = $deps->users->findByTelegramId($telegramId);
        if ($user === null || !$access->canManageBroadcasts($user)) {
            $deps->flows->clear($telegramId);

            return false;
        }

        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
        $step = (string) ($ctx['step'] ?? '');

        if ($step === 'body') {
            $trimmed = trim($text);
            if ($trimmed === '' || mb_strlen($trimmed) > self::TEXT_BODY_MAX) {
                SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.wizard_body_invalid'));

                return true;
            }
            $ctx['text'] = $trimmed;
            $kind = (string) ($ctx['kind'] ?? '');
            if ($kind === BroadcastRepository::KIND_POLL) {
                $ctx['step'] = 'options';
                $ctx['options'] = [];
            } else {
                $ctx['step'] = 'preview';
            }
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($step === 'options') {
            $option = trim($text);
            if ($option === '' || mb_strlen($option) > BroadcastRepository::OPTION_LABEL_MAX) {
                SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.wizard_option_invalid'));

                return true;
            }
            $options = is_array($ctx['options'] ?? null) ? $ctx['options'] : [];
            if (count($options) >= BroadcastRepository::MAX_OPTIONS) {
                SafeTelegram::sendMessage($bot, $deps->texts->get('broadcast.wizard_options_full'));

                return true;
            }
            $options[] = $option;
            $ctx['options'] = $options;
            $deps->flows->set($telegramId, self::FLOW, $ctx);
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function sendBroadcast(
        Nutgram $bot,
        BroadcastDeps $deps,
        array $actor,
        int $telegramId,
        array $ctx,
    ): bool {
        if (($ctx['step'] ?? '') !== 'preview') {
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        $kind = (string) ($ctx['kind'] ?? '');
        $body = (string) ($ctx['text'] ?? '');
        $authorId = (int) ($actor['id'] ?? 0);
        if ($authorId <= 0 || $body === '') {
            self::presentStep($bot, $deps, $telegramId);

            return true;
        }

        if ($kind === BroadcastRepository::KIND_POLL) {
            $options = is_array($ctx['options'] ?? null) ? $ctx['options'] : [];
            if (count($options) < BroadcastRepository::MIN_OPTIONS) {
                $ctx['step'] = 'options';
                $deps->flows->set($telegramId, self::FLOW, $ctx);
                self::presentStep($bot, $deps, $telegramId);

                return true;
            }
            $broadcastId = $deps->broadcasts->createPoll(
                $authorId,
                $body,
                $options,
                (bool) ($ctx['multi'] ?? false),
                (bool) ($ctx['allow_custom'] ?? false),
            );
        } else {
            $broadcastId = $deps->broadcasts->createText($authorId, $body);
        }

        $stats = $deps->delivery->deliverToParticipants($broadcastId, $actor);
        $deps->flows->clear($telegramId);

        $result = $deps->texts->get('broadcast.wizard_sent', [
            'sent' => (string) $stats['sent'],
            'failed' => (string) $stats['failed'],
        ]);
        CallbackUi::editCurrentMessage($bot, $result, null, $deps->interactiveMenus, $telegramId);
        MenuReplyHelper::sendWithMenu($bot, $deps->texts, $actor, $result);

        return true;
    }

    private static function presentStep(
        Nutgram $bot,
        BroadcastDeps $deps,
        int $telegramId,
        ?string $extra = null,
    ): void {
        $state = $deps->flows->get($telegramId);
        if ($state === null) {
            return;
        }
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
        $step = (string) ($ctx['step'] ?? 'kind');
        $kind = (string) ($ctx['kind'] ?? '');

        $text = match ($step) {
            'kind' => $deps->texts->get('broadcast.wizard_pick_kind'),
            'body' => $kind === BroadcastRepository::KIND_POLL
                ? $deps->texts->get('broadcast.wizard_ask_poll_question')
                : $deps->texts->get('broadcast.wizard_ask_text'),
            'options' => self::optionsStepText($deps, $ctx),
            'preview' => self::previewText($deps, $ctx),
            default => $deps->texts->get('broadcast.wizard_pick_kind'),
        };

        if ($extra !== null && $extra !== '') {
            $text .= "\n\n" . $extra;
        }

        $keyboard = self::stepKeyboard($deps, $ctx, $step);
        $target = self::wizardMessageTarget($bot, $deps, $telegramId);
        if ($target === null) {
            $message = InlineMenuHelper::sendInteractiveMenu(
                $bot,
                $deps->interactiveMenus,
                $telegramId,
                $text,
                $keyboard,
                $bot->chatId() ?? $telegramId,
            );
            if ($message !== null) {
                self::persistWizardMessage($deps, $telegramId, (int) $message->message_id);
            }

            return;
        }

        if ($target['message_id'] <= 0) {
            $message = SafeTelegram::sendMessage($bot, $text, $target['chat_id'], $keyboard);
            if ($message !== null) {
                self::persistWizardMessage($deps, $telegramId, (int) $message->message_id);
            }

            return;
        }

        CallbackUi::editCurrentMessage($bot, $text, $keyboard, $deps->interactiveMenus, $telegramId);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function optionsStepText(BroadcastDeps $deps, array $ctx): string
    {
        $options = is_array($ctx['options'] ?? null) ? $ctx['options'] : [];
        $lines = [$deps->texts->get('broadcast.wizard_options_hint', [
            'count' => (string) count($options),
            'max' => (string) BroadcastRepository::MAX_OPTIONS,
        ])];
        foreach ($options as $i => $label) {
            $lines[] = ($i + 1) . '. ' . $label;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function previewText(BroadcastDeps $deps, array $ctx): string
    {
        $kind = (string) ($ctx['kind'] ?? '');
        $body = (string) ($ctx['text'] ?? '');
        if ($kind === BroadcastRepository::KIND_POLL) {
            $options = is_array($ctx['options'] ?? null) ? $ctx['options'] : [];
            $optLines = [];
            foreach ($options as $i => $label) {
                $optLines[] = ($i + 1) . '. ' . $label;
            }
            $flags = [];
            if ((bool) ($ctx['multi'] ?? false)) {
                $flags[] = $deps->texts->get('broadcast.flag_multi');
            }
            if ((bool) ($ctx['allow_custom'] ?? false)) {
                $flags[] = $deps->texts->get('broadcast.flag_custom');
            }
            $flagLine = $flags === [] ? '' : "\n\n" . implode(' · ', $flags);

            return $deps->texts->get('broadcast.wizard_preview_poll', [
                'question' => $body,
                'options' => implode("\n", $optLines),
                'flags' => $flagLine,
            ]);
        }

        return $deps->texts->get('broadcast.wizard_preview_text', ['text' => $body]);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function stepKeyboard(BroadcastDeps $deps, array $ctx, string $step): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        if ($step === 'kind') {
            return $keyboard
                ->addRow(
                    InlineKeyboardButton::make($deps->texts->get('broadcast.kind_text'), callback_data: 'bc:w:kt:t'),
                    InlineKeyboardButton::make($deps->texts->get('broadcast.kind_poll'), callback_data: 'bc:w:kt:p'),
                )
                ->addRow(
                    InlineKeyboardButton::make($deps->texts->get('broadcast.wizard_cancel'), callback_data: 'bc:w:cn'),
                );
        }

        if ($step === 'options') {
            $options = is_array($ctx['options'] ?? null) ? $ctx['options'] : [];
            $keyboard = $keyboard
                ->addRow(
                    InlineKeyboardButton::make(
                        $deps->texts->get('broadcast.wizard_options_done'),
                        callback_data: 'bc:w:op:done',
                    ),
                )
                ->addRow(
                    InlineKeyboardButton::make(
                        ($ctx['multi'] ?? false) ? '✓ ' . $deps->texts->get('broadcast.toggle_multi') : $deps->texts->get('broadcast.toggle_multi'),
                        callback_data: 'bc:w:mu',
                    ),
                    InlineKeyboardButton::make(
                        ($ctx['allow_custom'] ?? false) ? '✓ ' . $deps->texts->get('broadcast.toggle_custom') : $deps->texts->get('broadcast.toggle_custom'),
                        callback_data: 'bc:w:cu',
                    ),
                );
            if (count($options) >= BroadcastRepository::MIN_OPTIONS) {
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make($deps->texts->get('broadcast.wizard_preview_button'), callback_data: 'bc:w:pr'),
                );
            }

            return InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'bc:w:cn');
        }

        if ($step === 'preview') {
            return $keyboard
                ->addRow(
                    InlineKeyboardButton::make($deps->texts->get('broadcast.wizard_send'), callback_data: 'bc:w:sn'),
                )
                ->addRow(
                    InlineKeyboardButton::make($deps->texts->get('broadcast.wizard_cancel'), callback_data: 'bc:w:cn'),
                );
        }

        return InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'bc:w:cn');
    }

    /**
     * @return array{chat_id: int, message_id: int}|null
     */
    private static function wizardMessageTarget(Nutgram $bot, BroadcastDeps $deps, int $telegramId): ?array
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null) {
            return null;
        }
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
        $messageId = (int) ($ctx['wizard_message_id'] ?? 0);
        $callbackMessage = CallbackUi::callbackMessage($bot);
        if ($messageId === 0 && $callbackMessage !== null) {
            $messageId = (int) $callbackMessage->message_id;
            self::persistWizardMessage($deps, $telegramId, $messageId);
        }

        return [
            'chat_id' => (int) ($bot->chatId() ?? $telegramId),
            'message_id' => $messageId,
        ];
    }

    private static function persistWizardMessage(BroadcastDeps $deps, int $telegramId, int $messageId): void
    {
        $state = $deps->flows->get($telegramId);
        if ($state === null) {
            return;
        }
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
        $ctx['wizard_message_id'] = $messageId;
        $deps->flows->set($telegramId, self::FLOW, $ctx);
    }
}