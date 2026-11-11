<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ConductorAdminHandler
{
    public static function register(Nutgram $bot, TextRepository $texts, UserRepository $users, AccessControl $access): void
    {
        $bot->onCommand('conductor', function (Nutgram $bot) use ($texts, $users, $access): void {
            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            self::openConductorPicker($bot, $texts, $users, $access, $user);
        });
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function openConductorPicker(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        array $user,
    ): void {
        if (!$access->isOwner($user)) {
            $bot->sendMessage($texts->get('admin.only_owner'));

            return;
        }

        self::sendConductorUi($bot, $texts, $users);
    }

    public static function sendConductorUi(Nutgram $bot, TextRepository $texts, UserRepository $users): void
    {
        $current = $users->findActiveConductor();
        $lines = [$texts->get('admin.conductor_manage_header')];

        if ($current === null) {
            $lines[] = $texts->get('admin.conductor_not_assigned');
        } else {
            $lines[] = $texts->get('admin.conductor_current', [
                'name' => (string) $current['display_name'],
            ]);
        }

        $candidates = $users->listActiveConductorCandidates();
        if ($candidates === []) {
            $lines[] = $texts->get('admin.no_conductor_candidates');
            $bot->sendMessage(implode("\n", $lines));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($candidates as $candidate) {
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: (string) $candidate['display_name'],
                    callback_data: 'oc:a:' . (int) $candidate['id'],
                ),
            );
        }

        $bot->sendMessage(text: implode("\n", $lines), reply_markup: $keyboard);
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'oc:')) {
            return false;
        }

        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null || !$access->isOwner($actor)) {
            $bot->answerCallbackQuery(text: $texts->get('admin.only_owner'), show_alert: true);

            return true;
        }

        if (preg_match('/^oc:a:(\d+)$/', $data, $m) !== 1) {
            $bot->answerCallbackQuery();

            return true;
        }

        $targetId = (int) $m[1];
        $target = $users->findById($targetId);
        if ($target === null) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return true;
        }

        try {
            $users->appointConductor($targetId);
        } catch (\InvalidArgumentException) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return true;
        }

        $bot->answerCallbackQuery();
        $bot->sendMessage($texts->get('admin.conductor_appointed', [
            'name' => (string) $target['display_name'],
        ]));
        self::sendConductorUi($bot, $texts, $users);

        return true;
    }
}
