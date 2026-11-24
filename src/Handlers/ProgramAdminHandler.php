<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Services\ProgramDateParser;
use App\Support\SqlPagination;
use App\Telegram\CallbackUi;
use App\Telegram\FlowStepCleanup;
use App\Telegram\TelegramHtml;
use App\Telegram\GroupFlowSession;
use App\Telegram\InlineMenuHelper;
use App\Telegram\MenuReplyHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ProgramAdminHandler
{
    public const FLOW_CREATE_TITLE = 'program_create_title';

    public const FLOW_CREATE_DATE = 'program_create_date';

    public const FLOW_RENAME = 'program_rename';

    public const FLOW_SET_DATE = 'program_set_date';

    /**
     * @param array<string, mixed> $actor
     */
    public static function openHome(
        Nutgram $bot,
        ProgramDeps $deps,
        AccessControl $access,
        array $actor,
        ?int $targetChatId = null,
        ?int $replyToMessageId = null,
    ): void {
        if (!$access->canManageBroadcasts($actor)) {
            SafeTelegram::sendMessage($bot, $deps->texts->get('programs.forbidden'));

            return;
        }

        $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        $built = self::buildActiveList($deps, 0);
        $chatId = $targetChatId ?? $bot->chatId() ?? $ownerTelegramId;
        InlineMenuHelper::sendInteractiveMenu(
            $bot,
            $deps->interactiveMenus,
            $ownerTelegramId,
            $built['text'],
            $built['keyboard'],
            $chatId,
        );
    }

    /**
     * @param array<string, mixed> $actor
     */
    public static function handleCallback(
        Nutgram $bot,
        ProgramDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        $actorTg = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);

        if ($data === 'prg:home') {
            SafeTelegram::answerCallbackQuery($bot);
            $built = self::buildActiveList($deps, 0);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^prg:pg:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $built = self::buildActiveList($deps, (int) $m[1]);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if ($data === 'prg:ar') {
            SafeTelegram::answerCallbackQuery($bot);
            $built = self::buildArchivedList($deps, 0);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^prg:ar:pg:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $built = self::buildArchivedList($deps, (int) $m[1]);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if ($data === 'prg:cn') {
            SafeTelegram::answerCallbackQuery($bot);
            $state = $deps->flows->get($actorTg);
            $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
            FlowStepCleanup::purgeAll($bot, $ctx);
            $deps->flows->clear($actorTg);
            self::sendActorFeedback($bot, $deps, $actor, $deps->texts->get('programs.flow_cancelled'), $ctx);

            return true;
        }

        if ($data === 'prg:cr') {
            SafeTelegram::answerCallbackQuery($bot);
            $ctx = [];
            self::beginTextFlow($bot, $deps, $access, $actorTg, self::FLOW_CREATE_TITLE, $ctx, 'programs.prompt_create_title');

            return true;
        }

        if (preg_match('/^prg:st:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editDetail($bot, $deps, (int) $m[1], $actorTg);

            return true;
        }

        if (preg_match('/^prg:ed:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::editSongPicker($bot, $deps, (int) $m[1], (int) $m[2], $actorTg);

            return true;
        }

        if (preg_match('/^prg:tg:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->toggleSong((int) $m[1], (int) $m[2]);
            $ctx = $deps->flows->get($actorTg);
            $page = is_array($ctx['context'] ?? null) ? (int) ($ctx['context']['page'] ?? 0) : 0;
            self::editSongPicker($bot, $deps, (int) $m[1], $page, $actorTg);

            return true;
        }

        if (preg_match('/^prg:mu:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->moveUp((int) $m[1], (int) $m[2]);
            self::editDetail($bot, $deps, (int) $m[1], $actorTg);

            return true;
        }

        if (preg_match('/^prg:md:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->moveDown((int) $m[1], (int) $m[2]);
            self::editDetail($bot, $deps, (int) $m[1], $actorTg);

            return true;
        }

        if (preg_match('/^prg:rm:(\d+):(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->removeSong((int) $m[1], (int) $m[2]);
            self::editDetail($bot, $deps, (int) $m[1], $actorTg);

            return true;
        }

        if (preg_match('/^prg:rn:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $ctx = ['program_id' => (int) $m[1]];
            self::beginTextFlow($bot, $deps, $access, $actorTg, self::FLOW_RENAME, $ctx, 'programs.prompt_rename');

            return true;
        }

        if (preg_match('/^prg:dt:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $ctx = ['program_id' => (int) $m[1]];
            self::beginTextFlow($bot, $deps, $access, $actorTg, self::FLOW_SET_DATE, $ctx, 'programs.prompt_set_date');

            return true;
        }

        if (preg_match('/^prg:dc:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->setDate((int) $m[1], null);
            self::editDetail($bot, $deps, (int) $m[1], $actorTg);

            return true;
        }

        if (preg_match('/^prg:n1:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::runNotify($bot, $deps, (int) $m[1], false, $actorTg);

            return true;
        }

        if (preg_match('/^prg:n2:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            self::runNotify($bot, $deps, (int) $m[1], true, $actorTg);

            return true;
        }

        if (preg_match('/^prg:ac:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->archive((int) $m[1]);
            $built = self::buildActiveList($deps, 0);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^prg:ua:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->unarchive((int) $m[1]);
            $built = self::buildArchivedList($deps, 0);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^prg:dl:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $programId = (int) $m[1];
            $keyboard = InlineMenuHelper::appendFooter(
                InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(
                        $deps->texts->get('programs.button_delete_confirm'),
                        callback_data: 'prg:dx:' . $programId,
                    ),
                ),
                $deps->texts,
                'prg:st:' . $programId,
            );
            CallbackUi::editCurrentMessage(
                $bot,
                $deps->texts->get('programs.delete_confirm'),
                $keyboard,
                $deps->interactiveMenus,
                $actorTg,
            );

            return true;
        }

        if (preg_match('/^prg:dx:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $deps->programs->delete((int) $m[1]);
            $built = self::buildActiveList($deps, 0);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        return false;
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        ProgramDeps $deps,
        AccessControl $access,
        int $telegramId,
        string $text,
        ?int $chatId = null,
    ): bool {
        $state = $deps->flows->get($telegramId);
        if ($state === null) {
            return false;
        }

        $flow = (string) ($state['flow'] ?? '');
        $ctx = is_array($state['context'] ?? null) ? $state['context'] : [];
        if (!GroupFlowSession::matchesChat($ctx, $chatId)) {
            return false;
        }

        $trimmed = trim($text);
        FlowStepCleanup::recordUserReply($bot, $ctx, $chatId);
        $deps->flows->set($telegramId, $flow, $ctx);

        if ($flow === self::FLOW_CREATE_TITLE) {
            if ($trimmed === '') {
                return true;
            }
            $actor = $deps->users->findByTelegramId($telegramId);
            if ($actor === null || !$access->canManageBroadcasts($actor)) {
                $deps->flows->clear($telegramId);

                return true;
            }
            $programId = $deps->programs->create($trimmed, (int) $actor['id'], null);
            $ctx['program_id'] = $programId;
            GroupFlowSession::sendTextPrompt(
                $bot,
                $deps->texts,
                $deps->texts->get('programs.prompt_create_date'),
                $ctx,
                'prg:cn',
            );
            $deps->flows->set($telegramId, self::FLOW_CREATE_DATE, $ctx);

            return true;
        }

        if ($flow === self::FLOW_CREATE_DATE) {
            $programId = (int) ($ctx['program_id'] ?? 0);
            if ($programId <= 0) {
                $deps->flows->clear($telegramId);

                return true;
            }
            $dateIso = self::parseDateInput($deps, $trimmed);
            if ($dateIso === false) {
                self::sendFlowPrompt($bot, $deps, $telegramId, $flow, $ctx, 'programs.date_invalid');

                return true;
            }
            if ($dateIso !== null) {
                $deps->programs->setDate($programId, $dateIso);
            }
            FlowStepCleanup::purgeAll($bot, $ctx);
            $deps->flows->clear($telegramId);
            $actor = $deps->users->findByTelegramId($telegramId);
            if ($actor !== null) {
                if (GroupFlowSession::isGroupContext($ctx)) {
                    self::openHome(
                        $bot,
                        $deps,
                        $access,
                        $actor,
                        GroupFlowSession::targetChatId($ctx, $bot, $telegramId),
                    );
                } else {
                    self::openHome($bot, $deps, $access, $actor);
                }
            }

            return true;
        }

        if ($flow === self::FLOW_RENAME) {
            $programId = (int) ($ctx['program_id'] ?? 0);
            if ($programId <= 0 || $trimmed === '') {
                return true;
            }
            $deps->programs->rename($programId, $trimmed);
            FlowStepCleanup::purgeAll($bot, $ctx);
            $deps->flows->clear($telegramId);
            $actor = $deps->users->findByTelegramId($telegramId);
            if ($actor !== null) {
                self::sendActorFeedback($bot, $deps, $actor, $deps->texts->get('programs.renamed'), $ctx);
            } else {
                SafeTelegram::sendMessage($bot, $deps->texts->get('programs.renamed'));
            }

            return true;
        }

        if ($flow === self::FLOW_SET_DATE) {
            $programId = (int) ($ctx['program_id'] ?? 0);
            if ($programId <= 0) {
                $deps->flows->clear($telegramId);

                return true;
            }
            $dateIso = self::parseDateInput($deps, $trimmed);
            if ($dateIso === false) {
                self::sendFlowPrompt($bot, $deps, $telegramId, $flow, $ctx, 'programs.date_invalid');

                return true;
            }
            $deps->programs->setDate($programId, $dateIso);
            FlowStepCleanup::purgeAll($bot, $ctx);
            $deps->flows->clear($telegramId);
            $actor = $deps->users->findByTelegramId($telegramId);
            if ($actor !== null) {
                self::sendActorFeedback($bot, $deps, $actor, $deps->texts->get('programs.date_saved'), $ctx);
            } else {
                SafeTelegram::sendMessage($bot, $deps->texts->get('programs.date_saved'));
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $ctx
     */
    private static function sendActorFeedback(
        Nutgram $bot,
        ProgramDeps $deps,
        array $actor,
        string $text,
        array $ctx,
    ): void {
        if (GroupFlowSession::isGroupContext($ctx)) {
            $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
            SafeTelegram::sendMessage(
                $bot,
                $text,
                GroupFlowSession::targetChatId($ctx, $bot, $ownerTelegramId),
            );

            return;
        }

        MenuReplyHelper::sendWithMenu($bot, $deps->texts, $actor, $text);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function sendFlowPrompt(
        Nutgram $bot,
        ProgramDeps $deps,
        int $telegramId,
        string $flow,
        array &$ctx,
        string $textKey,
    ): void {
        GroupFlowSession::sendTextPrompt($bot, $deps->texts, $deps->texts->get($textKey), $ctx, 'prg:cn');
        $deps->flows->set($telegramId, $flow, $ctx);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function beginTextFlow(
        Nutgram $bot,
        ProgramDeps $deps,
        AccessControl $access,
        int $actorTg,
        string $flow,
        array $ctx,
        string $promptKey,
    ): void {
        self::bindGroupFromBot($bot, $access, $ctx, $actorTg);
        GroupFlowSession::sendTextPrompt($bot, $deps->texts, $deps->texts->get($promptKey), $ctx, 'prg:cn');
        $deps->flows->set($actorTg, $flow, $ctx);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function bindGroupFromBot(
        Nutgram $bot,
        AccessControl $access,
        array &$ctx,
        int $ownerTelegramId,
        ?int $targetChatId = null,
        ?int $replyToMessageId = null,
    ): void {
        $chatId = $targetChatId ?? $bot->chatId();
        if ($chatId === null || !$access->isAdminChatId((int) $chatId)) {
            return;
        }

        if ($replyToMessageId === null) {
            $callbackMessage = CallbackUi::callbackMessage($bot);
            if ($callbackMessage !== null) {
                $replyToMessageId = (int) $callbackMessage->message_id;
            } else {
                $commandMessageId = (int) ($bot->message()?->message_id ?? 0);
                $replyToMessageId = $commandMessageId > 0 ? $commandMessageId : null;
            }
        }

        GroupFlowSession::bindGroup($ctx, (int) $chatId, $ownerTelegramId, $replyToMessageId);
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}
     */
    private static function buildActiveList(ProgramDeps $deps, int $page): array
    {
        $rows = $deps->programs->listActive();
        $total = count($rows);
        $page = SqlPagination::clampPage($page);

        $keyboard = InlineKeyboardMarkup::make();
        if ($total === 0) {
            $text = $deps->texts->get('programs.list_header') . "\n\n" . $deps->texts->get('programs.list_empty');
        } else {
            foreach (InlineMenuHelper::pageSlice($rows, $page) as $row) {
                $id = (int) $row['id'];
                $title = (string) ($row['title'] ?? '');
                $date = $deps->programMessages->listButtonDate($row);
                $count = (int) ($row['song_count'] ?? 0);
                $label = $title . ' · ' . $date . ' (' . $count . ')';
                if (mb_strlen($label) > 48) {
                    $label = mb_substr($title, 0, 28) . '… (' . $count . ')';
                }
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make($label, callback_data: 'prg:st:' . $id),
                );
            }
            $text = $deps->texts->get('programs.list_header', [
                'page' => (string) ($page + 1),
                'pages' => (string) max(1, InlineMenuHelper::totalPages($total)),
            ]);
            $nav = InlineMenuHelper::paginationButtons($page, $total, 'prg:pg:');
            if ($nav !== []) {
                $keyboard = $keyboard->addRow(...$nav);
            }
        }

        $keyboard = $keyboard
            ->addRow(
                InlineKeyboardButton::make($deps->texts->get('programs.button_create'), callback_data: 'prg:cr'),
                InlineKeyboardButton::make($deps->texts->get('programs.button_archived'), callback_data: 'prg:ar'),
            );
        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts);

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}
     */
    private static function buildArchivedList(ProgramDeps $deps, int $page): array
    {
        $rows = $deps->programs->listArchived();
        $total = count($rows);
        $page = SqlPagination::clampPage($page);

        $keyboard = InlineKeyboardMarkup::make();
        if ($total === 0) {
            $text = $deps->texts->get('programs.archived_header') . "\n\n" . $deps->texts->get('programs.archived_empty');
        } else {
            foreach (InlineMenuHelper::pageSlice($rows, $page) as $row) {
                $id = (int) $row['id'];
                $title = (string) ($row['title'] ?? '');
                $label = mb_strlen($title) > 42 ? mb_substr($title, 0, 39) . '…' : $title;
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make($label, callback_data: 'prg:st:' . $id),
                );
            }
            $text = $deps->texts->get('programs.archived_header');
            $nav = InlineMenuHelper::paginationButtons($page, $total, 'prg:ar:pg:');
            if ($nav !== []) {
                $keyboard = $keyboard->addRow(...$nav);
            }
        }

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'prg:home');

        return ['text' => $text, 'keyboard' => $keyboard];
    }

    private static function editDetail(Nutgram $bot, ProgramDeps $deps, int $programId, int $actorTg): void
    {
        $program = $deps->programs->find($programId);
        if ($program === null) {
            CallbackUi::editCurrentMessage(
                $bot,
                $deps->texts->get('programs.not_found'),
                InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $deps->texts, 'prg:home'),
                $deps->interactiveMenus,
                $actorTg,
            );

            return;
        }

        $lines = [
            $deps->texts->get('programs.detail_header', [
                'title' => TelegramHtml::escape((string) ($program['title'] ?? '')),
                'date' => TelegramHtml::escape($deps->programMessages->formatDateLine($program)),
            ]),
        ];
        $songs = $deps->programs->getSongs($programId);
        $n = 1;
        foreach ($songs as $song) {
            $lines[] = $n . '. ' . TelegramHtml::escape((string) ($song['title'] ?? ''));
            $n++;
        }
        if ($songs === []) {
            $lines[] = $deps->texts->get('programs.detail_no_songs');
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($songs as $idx => $song) {
            $songId = (int) $song['song_id'];
            $title = (string) ($song['title'] ?? '');
            $short = mb_strlen($title) > 20 ? mb_substr($title, 0, 17) . '…' : $title;
            $row = [];
            if ($idx > 0) {
                $row[] = InlineKeyboardButton::make('↑', callback_data: 'prg:mu:' . $programId . ':' . $songId);
            }
            if ($idx < count($songs) - 1) {
                $row[] = InlineKeyboardButton::make('↓', callback_data: 'prg:md:' . $programId . ':' . $songId);
            }
            $row[] = InlineKeyboardButton::make('✕ ' . $short, callback_data: 'prg:rm:' . $programId . ':' . $songId);
            $keyboard = $keyboard->addRow(...$row);
        }

        $archived = (int) ($program['is_archived'] ?? 0) === 1;
        $actionRow = [
            InlineKeyboardButton::make($deps->texts->get('programs.button_edit_songs'), callback_data: 'prg:ed:' . $programId . ':0'),
            InlineKeyboardButton::make($deps->texts->get('programs.button_rename'), callback_data: 'prg:rn:' . $programId),
        ];
        $keyboard = $keyboard->addRow(...$actionRow);
        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make($deps->texts->get('programs.button_set_date'), callback_data: 'prg:dt:' . $programId),
            InlineKeyboardButton::make($deps->texts->get('programs.button_clear_date'), callback_data: 'prg:dc:' . $programId),
        );

        if (!$archived) {
            if ($deps->programs->needsFirstNotify($program)) {
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make($deps->texts->get('programs.button_notify_new'), callback_data: 'prg:n1:' . $programId),
                );
            } elseif ($deps->programs->needsUpdateNotify($program)) {
                $keyboard = $keyboard->addRow(
                    InlineKeyboardButton::make($deps->texts->get('programs.button_notify_update'), callback_data: 'prg:n2:' . $programId),
                );
            }
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($deps->texts->get('programs.button_archive'), callback_data: 'prg:ac:' . $programId),
            );
        } else {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($deps->texts->get('programs.button_unarchive'), callback_data: 'prg:ua:' . $programId),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make($deps->texts->get('programs.button_delete'), callback_data: 'prg:dl:' . $programId),
        );

        $back = $archived ? 'prg:ar' : 'prg:home';
        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, $back);

        CallbackUi::editCurrentMessage(
            $bot,
            implode("\n", $lines),
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
            ParseMode::HTML,
        );
    }

    private static function editSongPicker(
        Nutgram $bot,
        ProgramDeps $deps,
        int $programId,
        int $page,
        int $actorTg,
    ): void {
        $program = $deps->programs->find($programId);
        if ($program === null) {
            return;
        }

        $deps->flows->set($actorTg, 'program_edit_songs', ['program_id' => $programId, 'page' => $page]);

        $total = $deps->songs->countWithCurrentMaterials();
        $page = SqlPagination::clampPage($page);
        $songs = $deps->songs->listWithCurrentMaterials(
            SqlPagination::offsetForPage($page, InlineMenuHelper::PAGE_SIZE),
            InlineMenuHelper::PAGE_SIZE,
        );

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($songs as $song) {
            $songId = (int) $song['id'];
            $title = (string) ($song['title'] ?? '');
            $in = $deps->programs->isSongInProgram($programId, $songId);
            $prefix = $in ? '✓ ' : '';
            $label = $prefix . (mb_strlen($title) > 40 ? mb_substr($title, 0, 37) . '…' : $title);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'prg:tg:' . $programId . ':' . $songId),
            );
        }

        $keyboard = InlineMenuHelper::appendPaginationAndFooter(
            $keyboard,
            $deps->texts,
            $page,
            $total,
            'prg:ed:' . $programId . ':',
            'prg:st:' . $programId,
        );

        CallbackUi::editCurrentMessage(
            $bot,
            $deps->texts->get('programs.edit_songs_header', [
                'title' => TelegramHtml::escape((string) ($program['title'] ?? '')),
                'page' => (string) ($page + 1),
                'pages' => (string) InlineMenuHelper::totalPages($total),
            ]),
            $keyboard,
            $deps->interactiveMenus,
            $actorTg,
            ParseMode::HTML,
        );
    }

    private static function runNotify(
        Nutgram $bot,
        ProgramDeps $deps,
        int $programId,
        bool $isUpdate,
        int $actorTg,
    ): void {
        $program = $deps->programs->find($programId);
        if ($program === null) {
            return;
        }

        $result = $deps->delivery->notifyParticipants($bot, $program, $isUpdate);
        $chatId = (int) ($bot->chatId() ?? $actorTg);
        $deps->delivery->sendResultSummary($bot, $chatId, $result);
        self::editDetail($bot, $deps, $programId, $actorTg);
    }

    /**
     * @return string|null|false null = skip date, false = invalid
     */
    private static function parseDateInput(ProgramDeps $deps, string $input): string|null|false
    {
        $skip = mb_strtolower(trim($input));
        if ($skip === '' || $skip === '-' || $skip === '—' || $skip === 'нет' || $skip === 'пропустить') {
            return null;
        }

        $year = (int) substr($deps->delivery->todayIso(), 0, 4);

        $parsed = ProgramDateParser::parseUserInput($input, $year);
        if ($parsed === null) {
            return false;
        }

        return ProgramDateParser::toIsoDate($parsed['year'], $parsed['month'], $parsed['day']);
    }
}