<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Domain\UserStatus;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\ErrorReportService;
use App\Services\UserIdentitySync;
use App\Telegram\MenuKeyboard;
use App\Telegram\MenuReplyHelper;
use App\Telegram\PartKeyboard;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class MessageHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        AccessControl $access,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        ?MaterialsDeps $materials = null,
        ?AdminHistoryContext $adminHistory = null,
        ?AdminChatDeps $adminChat = null,
        ?BroadcastDeps $broadcast = null,
        ?ProgramDeps $programDeps = null,
        ?ErrorReportService $errorReports = null,
    ): void {
        $bot->onMessage(function (Nutgram $bot) use ($texts, $users, $access, $schedule, $attendance, $payments, $materials, $adminHistory, $adminChat, $broadcast, $programDeps, $errorReports): void {
            if ($bot->message()?->text === null) {
                return;
            }

            $chat = $bot->chat();
            if ($chat === null || $chat->type !== ChatType::PRIVATE) {
                return;
            }

            $telegramId = $bot->userId();
            if ($telegramId === null) {
                return;
            }

            UserIdentitySync::syncPrivilegedTelegramUser(
                $telegramId,
                trim($bot->user()?->first_name ?? ''),
                $access,
                $users,
            );

            $text = trim($bot->message()->text ?? '');
            if ($text === '') {
                return;
            }

            if (str_starts_with($text, '/')) {
                return;
            }

            if (
                $errorReports !== null
                && ErrorReportHandler::handleFlowMessage($bot, $schedule->flows, $errorReports, $telegramId, $text)
            ) {
                return;
            }

            $user = $users->findByTelegramId($telegramId);
            if ($user === null) {
                return;
            }

            $status = (string) ($user['status'] ?? '');

            if ($status === UserStatus::BLOCKED) {
                $bot->sendMessage($texts->get('registration.blocked'));

                return;
            }

            if ($status === UserStatus::REJECTED) {
                $bot->sendMessage($texts->get('registration.rejected'));

                return;
            }

            if ($status === UserStatus::PENDING) {
                if (StartHandler::isRegistrationIncomplete($user)) {
                    self::handleRegistrationName($bot, $texts, $users, $user, $text);

                    return;
                }

                $bot->sendMessage($texts->get('registration.pending_user'));

                return;
            }

            if ($status === UserStatus::ACTIVE) {
                if (in_array($text, MenuKeyboard::allMenuLabels($texts), true)) {
                    self::clearActiveFlows($telegramId, $schedule, $attendance, $materials, $payments, $broadcast);
                    MenuActionDispatcher::dispatchMenuByLabel(
                        $bot,
                        $texts,
                        $users,
                        $access,
                        $schedule,
                        $attendance,
                        $payments,
                        $materials,
                        $adminHistory,
                        $adminChat,
                        $broadcast,
                        $programDeps,
                        $user,
                        $text,
                    );

                    return;
                }

                if ($adminChat !== null && $telegramId !== null && AdminChatOwnerHandler::handleFlowMessage(
                    $bot,
                    $adminChat,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($payments !== null && $telegramId !== null && PaymentReminderTextAdminHandler::handleFlowMessage(
                    $bot,
                    $payments,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($payments !== null && $telegramId !== null && PaymentMemberHandler::handleFlowMessage(
                    $bot,
                    $payments,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($telegramId !== null && AttendanceMemberHandler::handleFlowMessage(
                    $bot,
                    $attendance,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($telegramId !== null && ProfileHandler::handleFlowMessage(
                    $bot,
                    $texts,
                    $users,
                    $schedule->flows,
                    $telegramId,
                    $text,
                    $schedule->interactiveMenus,
                )) {
                    return;
                }

                if ($materials !== null && $telegramId !== null && MaterialsFlowHandler::handleFlowMessage(
                    $bot,
                    $materials,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($materials !== null && $telegramId !== null && SongMemberHandler::handleFlowMessage(
                    $bot,
                    $materials,
                    $materials->delivery,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($broadcast !== null && $telegramId !== null && BroadcastWizardHandler::handleFlowMessage(
                    $bot,
                    $broadcast,
                    $access,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($broadcast !== null && $telegramId !== null && BroadcastRecipientHandler::handleFlowMessage(
                    $bot,
                    $broadcast,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($programDeps !== null && $telegramId !== null && ProgramAdminHandler::handleFlowMessage(
                    $bot,
                    $programDeps,
                    $access,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if ($telegramId !== null && ScheduleAdminHandler::handleFlowMessage(
                    $bot,
                    $texts,
                    $schedule->config,
                    $schedule->rehearsals,
                    $schedule->templates,
                    $schedule->flows,
                    $schedule->generator,
                    $schedule->broadcast,
                    $schedule->settings,
                    $telegramId,
                    $text,
                )) {
                    return;
                }

                if (!in_array($text, MenuKeyboard::allMenuLabels($texts), true)) {
                    MenuReplyHelper::sendWithMenu($bot, $texts, $user, $texts->get('common.unknown_menu_hint'));
                }

                return;
            }
        });
    }

    private static function clearActiveFlows(
        ?int $telegramId,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        ?MaterialsDeps $materials,
        ?PaymentDeps $payments = null,
        ?BroadcastDeps $broadcast = null,
    ): void {
        if ($telegramId === null) {
            return;
        }

        $schedule->flows->clear($telegramId);
        $attendance->flows->clear($telegramId);
        if ($materials !== null) {
            $materials->flows->clear($telegramId);
        }
        if ($payments !== null) {
            $payments->flows->clear($telegramId);
        }
        if ($broadcast !== null) {
            $broadcast->flows->clear($telegramId);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function handleRegistrationName(
        Nutgram $bot,
        TextRepository $texts,
        UserRepository $users,
        array $user,
        string $text,
    ): void {
        if (trim((string) ($user['display_name'] ?? '')) !== '') {
            $bot->sendMessage(
                text: $texts->get('common.use_part_buttons'),
                reply_markup: PartKeyboard::registration($texts),
            );

            return;
        }

        if (mb_strlen($text) < 2 || mb_strlen($text) > 100) {
            $bot->sendMessage($texts->get('common.invalid_name'));

            return;
        }

        $users->updateDisplayName((int) $user['id'], $text);
        $bot->sendMessage(
            text: $texts->get('registration.ask_part'),
            reply_markup: PartKeyboard::registration($texts),
        );
    }
}