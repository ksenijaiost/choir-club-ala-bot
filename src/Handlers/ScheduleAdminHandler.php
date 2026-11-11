<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Clock;
use App\Repositories\BotSettingsRepository;
use App\Repositories\RehearsalRepository;
use App\Repositories\UserRepository;
use App\Repositories\ScheduleTemplateRepository;
use App\Repositories\UserFlowRepository;
use App\Services\AccessControl;
use App\Services\ScheduleBroadcastService;
use App\Services\ScheduleGenerator;
use App\Services\SchedulePresenter;
use App\Services\ScheduleTime;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ScheduleAdminHandler
{
    /**
     * @param array<string, mixed> $user
     */
    public static function openAdminHome(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        array $user,
    ): void {
        if (!$access->hasAdminStaffMenu($user)) {
            $bot->sendMessage($texts->get('admin.only_conductor_or_owner'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($texts->get('schedule.admin_list'), callback_data: 'sch:adm:list'),
                InlineKeyboardButton::make($texts->get('schedule.admin_templates'), callback_data: 'sch:adm:tpl'),
            )
            ->addRow(
                InlineKeyboardButton::make($texts->get('schedule.admin_add'), callback_data: 'sch:adm:add'),
            );

        $bot->sendMessage($texts->get('schedule.admin_menu'), reply_markup: $keyboard);
    }

    public static function handleCallback(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        AccessControl $access,
        ScheduleTemplateRepository $templates,
        RehearsalRepository $rehearsals,
        UserFlowRepository $flows,
        ScheduleGenerator $generator,
        ScheduleBroadcastService $broadcast,
        SchedulePresenter $presenter,
        BotSettingsRepository $settings,
        UserRepository $users,
        string $data,
        int $actorTelegramId,
    ): bool {
        if (!str_starts_with($data, 'sch:')) {
            return false;
        }

        if ($data === 'sch:view') {
            ScheduleMemberHandler::handleViewCallback($bot, $texts, $config, $generator, $presenter);

            return true;
        }

        $actor = $users->findByTelegramId($actorTelegramId);
        if ($actor === null || !$access->hasAdminStaffMenu($actor)) {
            if (str_starts_with($data, 'sch:adm:')) {
                $bot->answerCallbackQuery(text: $texts->get('admin.only_conductor_or_owner'), show_alert: true);

                return true;
            }

            return false;
        }

        $tz = new \DateTimeZone($config->timezone);
        $now = Clock::now($config);

        if ($data === 'sch:adm:list') {
            $bot->answerCallbackQuery();
            self::sendRehearsalAdminList($bot, $texts, $rehearsals, $now, $tz);

            return true;
        }

        if ($data === 'sch:adm:tpl') {
            $bot->answerCallbackQuery();
            self::sendTemplateList($bot, $texts, $templates);

            return true;
        }

        if ($data === 'sch:adm:add') {
            $bot->answerCallbackQuery();
            $duration = $settings->getInt('rehearsal_default_duration_minutes', 120);
            $flows->set($actorTelegramId, 'add_extra', ['step' => 'datetime', 'duration' => $duration]);
            $bot->sendMessage($texts->get('schedule.flow_add_datetime'));

            return true;
        }

        if (preg_match('/^sch:adm:r:(\d+)$/', $data, $m) === 1) {
            $id = (int) $m[1];
            $bot->answerCallbackQuery();
            self::sendRehearsalActions($bot, $texts, $rehearsals, $id);

            return true;
        }

        if (preg_match('/^sch:adm:mv:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $flows->set($actorTelegramId, 'reschedule', ['rehearsal_id' => (int) $m[1]]);
            $bot->sendMessage($texts->get('schedule.flow_reschedule_datetime'));

            return true;
        }

        if (preg_match('/^sch:adm:addr:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $flows->set($actorTelegramId, 'change_address', ['rehearsal_id' => (int) $m[1]]);
            $bot->sendMessage($texts->get('schedule.flow_change_address'));

            return true;
        }

        if (preg_match('/^sch:adm:cancel:(\d+)$/', $data, $m) === 1) {
            $id = (int) $m[1];
            $rehearsals->cancel($id);
            $bot->answerCallbackQuery();
            $bot->sendMessage($texts->get('schedule.admin_cancelled_ok'));
            $generator->generate($now, $tz);
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        if (preg_match('/^sch:adm:tpl:(\d+):time$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $flows->set($actorTelegramId, 'template_time', ['template_id' => (int) $m[1]]);
            $bot->sendMessage($texts->get('schedule.flow_template_time'));

            return true;
        }

        if (preg_match('/^sch:adm:tpl:(\d+):wd$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $flows->set($actorTelegramId, 'template_weekday', ['template_id' => (int) $m[1]]);
            $bot->sendMessage($texts->get('schedule.flow_template_weekday'));

            return true;
        }

        if (preg_match('/^sch:adm:tpl:(\d+):addr$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            $flows->set($actorTelegramId, 'template_address', ['template_id' => (int) $m[1]]);
            $bot->sendMessage($texts->get('schedule.flow_template_address'));

            return true;
        }

        if (preg_match('/^sch:adm:tpl:(\d+)$/', $data, $m) === 1) {
            $bot->answerCallbackQuery();
            self::sendTemplateActions($bot, $texts, $templates, (int) $m[1]);

            return true;
        }

        if ($data === 'sch:adm:regen') {
            $bot->answerCallbackQuery();
            $created = $generator->generate($now, $tz);
            $bot->sendMessage($texts->get('schedule.admin_regen_ok', ['count' => (string) $created]));

            return true;
        }

        return false;
    }

    public static function handleFlowMessage(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        RehearsalRepository $rehearsals,
        ScheduleTemplateRepository $templates,
        UserFlowRepository $flows,
        ScheduleGenerator $generator,
        ScheduleBroadcastService $broadcast,
        BotSettingsRepository $settings,
        int $telegramId,
        string $text,
    ): bool {
        $state = $flows->get($telegramId);
        if ($state === null) {
            return false;
        }

        $tz = new \DateTimeZone($config->timezone);
        $now = Clock::now($config);
        $flow = $state['flow'];
        $ctx = $state['context'];

        if ($flow === 'reschedule') {
            $dt = ScheduleTime::parseUserDateTime($text, $tz);
            if ($dt === null) {
                $bot->sendMessage($texts->get('schedule.flow_invalid_datetime'));

                return true;
            }

            $id = (int) ($ctx['rehearsal_id'] ?? 0);
            $duration = $settings->getInt('rehearsal_default_duration_minutes', 120);
            $ends = $dt->modify('+' . $duration . ' minutes');
            $rehearsals->updateSchedule($id, ScheduleTime::toStorage($dt), ScheduleTime::toStorage($ends));
            $flows->clear($telegramId);
            $bot->sendMessage($texts->get('schedule.admin_rescheduled_ok'));
            $generator->generate($now, $tz);
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        if ($flow === 'change_address') {
            $address = trim($text);
            if ($address === '') {
                $bot->sendMessage($texts->get('schedule.flow_invalid_address'));

                return true;
            }

            $rehearsals->updateAddress((int) ($ctx['rehearsal_id'] ?? 0), $address);
            $flows->clear($telegramId);
            $bot->sendMessage($texts->get('schedule.admin_address_ok'));
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        if ($flow === 'add_extra') {
            $step = (string) ($ctx['step'] ?? 'datetime');
            if ($step === 'datetime') {
                $dt = ScheduleTime::parseUserDateTime($text, $tz);
                if ($dt === null) {
                    $bot->sendMessage($texts->get('schedule.flow_invalid_datetime'));

                    return true;
                }

                $ctx['starts_at'] = ScheduleTime::toStorage($dt);
                $ctx['step'] = 'address';
                $flows->set($telegramId, 'add_extra', $ctx);
                $bot->sendMessage($texts->get('schedule.flow_add_address'));

                return true;
            }

            $address = trim($text);
            if ($address === '') {
                $bot->sendMessage($texts->get('schedule.flow_invalid_address'));

                return true;
            }

            $starts = new \DateTimeImmutable((string) $ctx['starts_at'], $tz);
            $duration = (int) ($ctx['duration'] ?? $settings->getInt('rehearsal_default_duration_minutes', 120));
            $ends = $starts->modify('+' . $duration . ' minutes');
            $rehearsals->create(null, (string) $ctx['starts_at'], ScheduleTime::toStorage($ends), $address);
            $flows->clear($telegramId);
            $bot->sendMessage($texts->get('schedule.admin_added_ok'));
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        if ($flow === 'template_address') {
            $templateId = (int) ($ctx['template_id'] ?? 0);
            $tpl = $templates->findById($templateId);
            if ($tpl === null) {
                $flows->clear($telegramId);

                return true;
            }

            $address = trim($text);
            if ($address === '') {
                $bot->sendMessage($texts->get('schedule.flow_invalid_address'));

                return true;
            }

            $templates->update(
                $templateId,
                (int) $tpl['weekday'],
                (string) $tpl['start_time'],
                (int) $tpl['duration_minutes'],
                $address,
                (int) $tpl['is_active'] === 1,
            );
            $flows->clear($telegramId);
            $generator->generate($now, $tz);
            $bot->sendMessage($texts->get('schedule.template_saved'));
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        if ($flow === 'template_time') {
            $templateId = (int) ($ctx['template_id'] ?? 0);
            $tpl = $templates->findById($templateId);
            if ($tpl === null) {
                $flows->clear($telegramId);

                return true;
            }

            if (!preg_match('/^\d{1,2}:\d{2}$/', trim($text))) {
                $bot->sendMessage($texts->get('schedule.flow_invalid_time'));

                return true;
            }

            [$h, $m] = explode(':', trim($text));
            $time = sprintf('%02d:%02d:00', (int) $h, (int) $m);

            $templates->update(
                $templateId,
                (int) $tpl['weekday'],
                $time,
                (int) $tpl['duration_minutes'],
                (string) $tpl['address'],
                (int) $tpl['is_active'] === 1,
            );
            $flows->clear($telegramId);
            $generator->generate($now, $tz);
            $bot->sendMessage($texts->get('schedule.template_saved'));
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        if ($flow === 'template_weekday') {
            $templateId = (int) ($ctx['template_id'] ?? 0);
            $tpl = $templates->findById($templateId);
            if ($tpl === null) {
                $flows->clear($telegramId);

                return true;
            }

            $day = (int) trim($text);
            if ($day < 1 || $day > 7) {
                $bot->sendMessage($texts->get('schedule.flow_invalid_weekday'));

                return true;
            }

            $templates->update(
                $templateId,
                $day,
                (string) $tpl['start_time'],
                (int) $tpl['duration_minutes'],
                (string) $tpl['address'],
                (int) $tpl['is_active'] === 1,
            );
            $flows->clear($telegramId);
            $generator->generate($now, $tz);
            $bot->sendMessage($texts->get('schedule.template_saved'));
            $broadcast->notifyScheduleChanged($bot);

            return true;
        }

        return false;
    }

    private static function sendRehearsalAdminList(
        Nutgram $bot,
        TextRepository $texts,
        RehearsalRepository $rehearsals,
        \DateTimeImmutable $now,
        \DateTimeZone $tz,
    ): void {
        $rows = $rehearsals->listFrom(ScheduleTime::toStorage($now->setTimezone($tz)), 12);
        if ($rows === []) {
            $bot->sendMessage($texts->get('schedule.empty'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($rows as $row) {
            $starts = new \DateTimeImmutable((string) $row['starts_at'], $tz);
            $label = ScheduleTime::formatLocalDate($starts, $tz) . ' ' . ScheduleTime::formatLocalTime($starts, $tz);
            if (($row['status'] ?? '') === 'cancelled') {
                $label .= ' (' . $texts->get('schedule.tag_cancelled') . ')';
            }

            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make($label, callback_data: 'sch:adm:r:' . (int) $row['id']),
            );
        }

        $bot->sendMessage($texts->get('schedule.admin_list_prompt'), reply_markup: $keyboard);
    }

    private static function sendRehearsalActions(
        Nutgram $bot,
        TextRepository $texts,
        RehearsalRepository $rehearsals,
        int $id,
    ): void {
        $row = $rehearsals->findById($id);
        if ($row === null) {
            $bot->sendMessage($texts->get('errors.generic'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($texts->get('schedule.action_reschedule'), callback_data: 'sch:adm:mv:' . $id),
                InlineKeyboardButton::make($texts->get('schedule.action_address'), callback_data: 'sch:adm:addr:' . $id),
            )
            ->addRow(
                InlineKeyboardButton::make($texts->get('schedule.action_cancel'), callback_data: 'sch:adm:cancel:' . $id),
            );

        $bot->sendMessage(
            $texts->get('schedule.admin_rehearsal_card', ['id' => (string) $id]),
            reply_markup: $keyboard,
        );
    }

    private static function sendTemplateList(Nutgram $bot, TextRepository $texts, ScheduleTemplateRepository $templates): void
    {
        $all = $templates->listAll();
        if ($all === []) {
            $bot->sendMessage($texts->get('schedule.templates_empty'));

            return;
        }

        $lines = [$texts->get('schedule.templates_header')];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($all as $tpl) {
            $wd = (int) $tpl['weekday'];
            $lines[] = $texts->get('schedule.template_row', [
                'weekday' => (string) $wd,
                'time' => substr((string) $tpl['start_time'], 0, 5),
                'address' => (string) $tpl['address'],
            ]);
            $keyboard = $keyboard->addRow(
                InlineKeyboardButton::make(
                    $texts->get('schedule.template_button', ['n' => (string) $tpl['id']]),
                    callback_data: 'sch:adm:tpl:' . (int) $tpl['id'],
                ),
            );
        }

        $keyboard = $keyboard->addRow(
            InlineKeyboardButton::make($texts->get('schedule.admin_regen'), callback_data: 'sch:adm:regen'),
        );

        $bot->sendMessage(implode("\n", $lines), reply_markup: $keyboard);
    }

    private static function sendTemplateActions(
        Nutgram $bot,
        TextRepository $texts,
        ScheduleTemplateRepository $templates,
        int $id,
    ): void {
        $tpl = $templates->findById($id);
        if ($tpl === null) {
            $bot->sendMessage($texts->get('errors.generic'));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($texts->get('schedule.tpl_edit_weekday'), callback_data: 'sch:adm:tpl:' . $id . ':wd'),
                InlineKeyboardButton::make($texts->get('schedule.tpl_edit_time'), callback_data: 'sch:adm:tpl:' . $id . ':time'),
            )
            ->addRow(
                InlineKeyboardButton::make($texts->get('schedule.tpl_edit_address'), callback_data: 'sch:adm:tpl:' . $id . ':addr'),
            );

        $bot->sendMessage($texts->get('schedule.template_edit_menu', ['id' => (string) $id]), reply_markup: $keyboard);
    }
}
