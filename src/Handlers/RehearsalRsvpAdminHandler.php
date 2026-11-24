<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Clock;
use App\Services\AccessControl;
use App\Telegram\CallbackUi;
use App\Telegram\InlineMenuHelper;
use App\Telegram\SafeTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class RehearsalRsvpAdminHandler
{
    /**
     * @param array<string, mixed> $actor
     */
    public static function openHome(
        Nutgram $bot,
        AttendanceDeps $deps,
        AccessControl $access,
        array $actor,
        ?int $rehearsalId = null,
        ?int $targetChatId = null,
    ): void {
        if (!$access->hasConductorPrivileges($actor)) {
            $bot->sendMessage($deps->texts->get('admin.only_conductor_or_owner'));

            return;
        }

        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config)->setTimezone($tz);
        $nowLocal = \App\Services\ScheduleTime::toStorage($now);

        if ($rehearsalId === null) {
            $default = $deps->rehearsals->findDefaultForRsvp($nowLocal);
            if ($default === null) {
                $bot->sendMessage($deps->texts->get('rsvp.no_upcoming'));

                return;
            }
            $rehearsalId = (int) $default['id'];
        }

        $built = self::buildScreen($deps, $rehearsalId, $tz);
        $ownerTelegramId = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
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
        AttendanceDeps $deps,
        AccessControl $access,
        array $actor,
        string $data,
    ): bool {
        if (!str_starts_with($data, 'rsv:')) {
            return false;
        }

        if (!$access->hasConductorPrivileges($actor)) {
            SafeTelegram::answerCallbackQuery($bot, [
                'text' => $deps->texts->get('admin.only_conductor_or_owner'),
                'show_alert' => true,
            ]);

            return true;
        }

        $actorTg = (int) ($actor['telegram_id'] ?? $bot->userId() ?? 0);
        $tz = new \DateTimeZone($deps->config->timezone);
        $now = Clock::now($deps->config);

        if ($data === 'rsv:home') {
            SafeTelegram::answerCallbackQuery($bot);
            self::openHome($bot, $deps, $access, $actor);

            return true;
        }

        if (preg_match('/^rsv:rh:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $built = self::buildScreen($deps, (int) $m[1], $tz);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^rsv:prev:(\d+)$/', $data, $m) === 1) {
            $currentId = (int) $m[1];
            $prev = $deps->rehearsals->findPreviousScheduledId($currentId, '');
            SafeTelegram::answerCallbackQuery($bot);
            if ($prev === null) {
                return true;
            }
            $built = self::buildScreen($deps, $prev, $tz);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^rsv:next:(\d+)$/', $data, $m) === 1) {
            $currentId = (int) $m[1];
            $next = $deps->rehearsals->findNextScheduledId($currentId);
            SafeTelegram::answerCallbackQuery($bot);
            if ($next === null) {
                return true;
            }
            $built = self::buildScreen($deps, $next, $tz);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^rsv:rf:(\d+)$/', $data, $m) === 1) {
            SafeTelegram::answerCallbackQuery($bot);
            $built = self::buildScreen($deps, (int) $m[1], $tz);
            CallbackUi::editCurrentMessage($bot, $built['text'], $built['keyboard'], $deps->interactiveMenus, $actorTg);

            return true;
        }

        if (preg_match('/^rsv:nd:(\d+)$/', $data, $m) === 1) {
            $rehearsalId = (int) $m[1];
            $result = $deps->rsvpNudge->nudgePending($rehearsalId, $now, $tz);
            $text = match ($result['reason']) {
                'rate_limit' => $deps->texts->get('rsvp.nudge_rate_limit'),
                'not_sent' => $deps->texts->get('rsvp.nudge_not_sent'),
                'ok' => $deps->texts->get('rsvp.nudge_ok', ['count' => (string) $result['sent']]),
                default => $deps->texts->get('errors.generic'),
            };
            SafeTelegram::answerCallbackQuery($bot, ['text' => $text, 'show_alert' => true]);

            return true;
        }

        return false;
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkup}
     */
    private static function buildScreen(AttendanceDeps $deps, int $rehearsalId, \DateTimeZone $tz): array
    {
        $rehearsal = $deps->rehearsals->findById($rehearsalId);
        if ($rehearsal === null) {
            return [
                'text' => $deps->texts->get('errors.generic'),
                'keyboard' => InlineMenuHelper::appendFooter(InlineKeyboardMarkup::make(), $deps->texts),
            ];
        }

        $participants = $deps->users->listActiveParticipants();
        $lists = $deps->rsvpStatus->buildLists($rehearsalId, $participants);
        $header = $deps->rsvpPresenter->headerText($rehearsal, $tz);
        $body = $deps->rsvpPresenter->buildBody($lists);
        $text = $header . "\n\n" . $body;

        $prevId = $deps->rehearsals->findPreviousScheduledId($rehearsalId, '');
        $nextId = $deps->rehearsals->findNextScheduledId($rehearsalId);

        $navRow = [];
        if ($prevId !== null) {
            $navRow[] = InlineKeyboardButton::make($deps->texts->get('rsvp.nav_prev'), callback_data: 'rsv:prev:' . $rehearsalId);
        }
        if ($nextId !== null) {
            $navRow[] = InlineKeyboardButton::make($deps->texts->get('rsvp.nav_next'), callback_data: 'rsv:next:' . $rehearsalId);
        }

        $keyboard = InlineKeyboardMarkup::make();
        if ($navRow !== []) {
            $keyboard = $keyboard->addRow(...$navRow);
        }

        $keyboard = $keyboard
            ->addRow(
                InlineKeyboardButton::make($deps->texts->get('rsvp.button_refresh'), callback_data: 'rsv:rf:' . $rehearsalId),
                InlineKeyboardButton::make($deps->texts->get('rsvp.button_nudge'), callback_data: 'rsv:nd:' . $rehearsalId),
            );

        $keyboard = InlineMenuHelper::appendFooter($keyboard, $deps->texts, 'atd:rh');

        return ['text' => $text, 'keyboard' => $keyboard];
    }
}