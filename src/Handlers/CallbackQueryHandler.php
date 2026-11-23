<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Config;
use App\Domain\UserRole;
use App\Domain\UserStatus;
use App\Domain\VoicePart;
use App\Repositories\RegistrationRepository;
use App\Repositories\UserRepository;
use App\Services\AccessControl;
use App\Services\AdminChatResolver;
use App\Services\ErrorReportService;
use App\Services\MemberBlockService;
use App\Services\RegistrationAdminMessageBuilder;
use App\Services\UserIdentitySync;
use App\Telegram\InlineMenuHelper;
use App\Telegram\InteractiveMenuService;
use App\Telegram\MenuKeyboard;
use App\Telegram\SafeTelegram;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

final class CallbackQueryHandler
{
    public static function register(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        UserRepository $users,
        RegistrationRepository $registrations,
        AccessControl $access,
        MemberBlockService $blocks,
        ScheduleDeps $schedule,
        AttendanceDeps $attendance,
        PaymentDeps $payments,
        MaterialsDeps $materials,
        AdminHistoryContext $adminHistory,
        AdminChatDeps $adminChat,
        ?BroadcastDeps $broadcast,
        InteractiveMenuService $interactiveMenus,
        ErrorReportService $errorReports,
    ): void {
        $bot->onCallbackQuery(function (Nutgram $bot) use ($texts, $config, $users, $registrations, $access, $blocks, $schedule, $attendance, $payments, $materials, $adminHistory, $adminChat, $broadcast, $interactiveMenus, $errorReports): void {
            $callback = $bot->callbackQuery();
            if ($callback === null) {
                return;
            }

            $data = (string) ($callback->data ?? '');
            if ($data === '') {
                return;
            }

            $actorTelegramId = $callback->from?->id;
            if ($actorTelegramId === null) {
                return;
            }

            if (str_starts_with($data, 'err:')) {
                if (ErrorReportHandler::handleCallback($bot, $texts, $schedule->flows, $errorReports, $data, $actorTelegramId)) {
                    return;
                }
            }

            if ($data === InlineMenuHelper::CLOSE_CALLBACK) {
                InlineMenuHelper::handleClose($bot, $schedule->flows, $interactiveMenus, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'ab:')) {
                AboutBotHandler::handleCallback($bot, $texts, $access, $users, $interactiveMenus, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'ach:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow !== null && AdminChatOwnerHandler::handleCallback(
                    $bot,
                    $access,
                    $adminChat,
                    $actorRow,
                    $data,
                    $actorTelegramId,
                )) {
                    return;
                }

                return;
            }

            if (str_starts_with($data, 'snd:')) {
                if (SongsCallbackHandler::handle($bot, $materials, $materials->delivery, $data)) {
                    return;
                }

                return;
            }

            if (str_starts_with($data, 'mat:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow !== null && MaterialsCallbackHandler::handle($bot, $materials, $access, $actorRow, $data)) {
                    return;
                }

                return;
            }

            if (str_starts_with($data, 'bc:') && $broadcast !== null) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow === null) {
                    return;
                }

                if (str_starts_with($data, 'bc:w:')) {
                    BroadcastWizardHandler::handleCallback($bot, $broadcast, $access, $actorRow, $data);

                    return;
                }

                if (BroadcastAdminHandler::handleCallback($bot, $broadcast, $access, $actorRow, $data)) {
                    return;
                }

                BroadcastRecipientHandler::handleCallback($bot, $broadcast, $actorRow, $data);

                return;
            }

            if (str_starts_with($data, 'pay:') || str_starts_with($data, 'pa:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow === null) {
                    return;
                }

                if (PaymentAdminHandler::handleCallback($bot, $payments, $access, $actorRow, $data)) {
                    return;
                }

                if (str_starts_with($data, 'pa:')) {
                    SafeTelegram::answerCallbackQuery($bot);

                    return;
                }

                PaymentCallbackHandler::handle($bot, $payments, $access, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'atd:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow !== null) {
                    AttendanceAdminHandler::handleCallback(
                        $bot,
                        $texts,
                        $access,
                        $attendance,
                        $actorRow,
                        $data,
                    );
                }

                return;
            }

            if (str_starts_with($data, 'att:') || str_starts_with($data, 'chk:')) {
                AttendanceCallbackHandler::handle($bot, $attendance, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'sch:')) {
                ScheduleAdminHandler::handleCallback(
                    $bot,
                    $texts,
                    $config,
                    $access,
                    $schedule->templates,
                    $schedule->rehearsals,
                    $schedule->flows,
                    $schedule->generator,
                    $schedule->broadcast,
                    $schedule->presenter,
                    $schedule->settings,
                    $users,
                    $schedule->interactiveMenus,
                    $data,
                    $actorTelegramId,
                );

                return;
            }

            if (str_starts_with($data, 'hst:')) {
                $actorRow = $users->findByTelegramId($actorTelegramId);
                if ($actorRow !== null) {
                    AdminHistoryHandler::handleCallback($bot, $adminHistory, $access, $actorRow, $data);
                }

                return;
            }

            if (str_starts_with($data, 'prf:')) {
                ProfileHandler::handleCallback($bot, $texts, $users, $schedule->flows, $schedule->interactiveMenus, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'bl:')) {
                MembersAdminHandler::handleCallback($bot, $texts, $users, $access, $blocks, $interactiveMenus, $data, $actorTelegramId);

                return;
            }

            $actorRow = $users->findByTelegramId($actorTelegramId);
            if (
                $actorRow !== null
                && ($actorRow['status'] ?? '') === UserStatus::BLOCKED
                && !str_starts_with($data, 'rr:')
            ) {
                $bot->answerCallbackQuery(text: $texts->get('registration.blocked'), show_alert: true);

                return;
            }

            if (str_starts_with($data, 'oc:')) {
                ConductorAdminHandler::handleCallback($bot, $texts, $users, $access, $interactiveMenus, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'hp:')) {
                HelpersAdminHandler::handleCallback($bot, $texts, $users, $access, $interactiveMenus, $data, $actorTelegramId);

                return;
            }

            if (str_starts_with($data, 'rp:')) {
                self::handleRegistrationPart(
                    $bot,
                    $texts,
                    $config,
                    $adminChat->resolver,
                    $users,
                    $registrations,
                    $data,
                    $actorTelegramId,
                );

                return;
            }

            if (str_starts_with($data, 'rr:')) {
                self::handleRegistrationReview($bot, $texts, $config, $users, $registrations, $access, $data, $actorTelegramId);

                return;
            }
        });
    }

    private static function handleRegistrationPart(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        AdminChatResolver $adminChatResolver,
        UserRepository $users,
        RegistrationRepository $registrations,
        string $data,
        int $actorTelegramId,
    ): void {
        $part = substr($data, 3);
        if (!VoicePart::isValid($part)) {
            $bot->answerCallbackQuery();

            return;
        }

        $user = $users->findByTelegramId($actorTelegramId);
        if ($user === null || ($user['status'] ?? '') !== UserStatus::PENDING) {
            if ($user !== null && ($user['role'] ?? '') === \App\Domain\UserRole::CONDUCTOR) {
                $bot->answerCallbackQuery(text: $texts->get('profile.conductor_no_part'), show_alert: true);
            } else {
                $bot->answerCallbackQuery();
            }

            return;
        }

        if (trim((string) ($user['display_name'] ?? '')) === '') {
            $bot->answerCallbackQuery(text: $texts->get('registration.welcome'), show_alert: true);

            return;
        }

        $from = $bot->callbackQuery()?->from;
        if ($from !== null) {
            $username = $from->username;
            $users->updateTelegramUsername((int) $user['id'], $username !== null ? (string) $username : null);
            $user = $users->findById((int) $user['id']) ?? $user;
        }

        $users->updateVoicePart((int) $user['id'], $part);

        $existing = $registrations->findOpenByUserId((int) $user['id']);
        if ($existing === null) {
            $requestId = $registrations->create((int) $user['id']);
        } else {
            $requestId = (int) $existing['id'];
        }

        $bot->answerCallbackQuery();
        $bot->sendMessage($texts->get('registration.pending_user'));

        $adminChatId = $adminChatResolver->resolvedIdAsInt();
        if ($adminChatId === null) {
            return;
        }

        $partLabel = $texts->get('parts.' . $part);
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                text: $texts->get('buttons.accept'),
                callback_data: 'rr:a:' . $requestId,
            ),
            InlineKeyboardButton::make(
                text: $texts->get('buttons.reject'),
                callback_data: 'rr:r:' . $requestId,
            ),
        );

        $builder = new RegistrationAdminMessageBuilder($texts, $config);

        $message = $bot->sendMessage(
            text: $builder->buildNewRequest($user, $partLabel, $from),
            chat_id: $adminChatId,
            reply_markup: $keyboard,
            parse_mode: ParseMode::HTML,
        );

        if ($message !== null) {
            $registrations->setAdminMessageId($requestId, $message->message_id);
        }
    }

    private static function handleRegistrationReview(
        Nutgram $bot,
        TextRepository $texts,
        Config $config,
        UserRepository $users,
        RegistrationRepository $registrations,
        AccessControl $access,
        string $data,
        int $actorTelegramId,
    ): void {
        if (!$access->isAdminChatId($bot->chatId() ?? '')) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return;
        }

        $from = $bot->callbackQuery()?->from;
        UserIdentitySync::syncPrivilegedTelegramUser(
            $actorTelegramId,
            trim($from?->first_name ?? ''),
            $access,
            $users,
        );

        $moderator = $users->findByTelegramId($actorTelegramId);
        if ($moderator === null || !$access->canModerateRegistration($moderator)) {
            $bot->answerCallbackQuery(text: $texts->get('registration.moderation_forbidden'), show_alert: true);

            return;
        }

        if (preg_match('/^rr:([ar]):(\d+)$/', $data, $matches) !== 1) {
            $bot->answerCallbackQuery();

            return;
        }

        $action = $matches[1];
        $requestId = (int) $matches[2];

        $builder = new RegistrationAdminMessageBuilder($texts, $config);
        $request = $registrations->findById($requestId);

        if ($request === null) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return;
        }

        $applicant = $users->findById((int) $request['user_id']);
        $partLabel = $applicant !== null ? self::applicantPartLabel($texts, $applicant) : '—';
        $callbackMessage = $bot->callbackQuery()?->message;
        $adminChatId = (int) ($bot->chatId() ?? 0);

        if (($request['status'] ?? '') !== 'pending') {
            $bot->answerCallbackQuery(text: $texts->get('registration.request_already_handled'), show_alert: true);
            if ($applicant !== null) {
                $resolvedHtml = ($request['status'] ?? '') === 'accepted'
                    ? $builder->buildResolvedAccepted($applicant, $partLabel, null)
                    : $builder->buildResolvedRejected($applicant, $partLabel, null);
                self::finalizeRegistrationAdminMessage($bot, $callbackMessage, $resolvedHtml, $adminChatId);
            }

            return;
        }

        if ($applicant === null) {
            $bot->answerCallbackQuery(text: $texts->get('errors.generic'), show_alert: true);

            return;
        }

        $name = (string) $applicant['display_name'];
        $telegramId = (int) $applicant['telegram_id'];

        if ($action === 'a') {
            $registrations->resolve($requestId, 'accepted');
            $users->updateStatus((int) $applicant['id'], UserStatus::ACTIVE);
            $applicant = $users->findById((int) $applicant['id']) ?? $applicant;

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: $texts->get('registration.accepted', ['name' => $name]),
                chat_id: $telegramId,
                reply_markup: MenuKeyboard::forUser($texts, $applicant),
            );

            $resolvedHtml = $builder->buildResolvedAccepted($applicant, $partLabel, $moderator);
        } else {
            $registrations->resolve($requestId, 'rejected');
            $users->updateStatus((int) $applicant['id'], UserStatus::REJECTED);

            $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: $texts->get('registration.rejected'),
                chat_id: $telegramId,
            );

            $resolvedHtml = $builder->buildResolvedRejected($applicant, $partLabel, $moderator);
        }

        self::finalizeRegistrationAdminMessage($bot, $callbackMessage, $resolvedHtml, $adminChatId);
    }

    /**
     * @param array<string, mixed> $applicant
     */
    private static function applicantPartLabel(TextRepository $texts, array $applicant): string
    {
        $part = (string) ($applicant['voice_part'] ?? '');
        if ($part !== '' && VoicePart::isValid($part)) {
            return $texts->get('parts.' . $part);
        }

        return '—';
    }

    private static function finalizeRegistrationAdminMessage(
        Nutgram $bot,
        ?Message $message,
        string $html,
        int $fallbackChatId,
    ): void {
        if ($message !== null) {
            try {
                $bot->editMessageText(
                    text: $html,
                    chat_id: $message->chat->id,
                    message_id: $message->message_id,
                    parse_mode: ParseMode::HTML,
                    reply_markup: InlineKeyboardMarkup::make(),
                );

                return;
            } catch (\Throwable) {
            }
        }

        if ($fallbackChatId !== 0) {
            $bot->sendMessage(text: $html, chat_id: $fallbackChatId, parse_mode: ParseMode::HTML);
        }
    }
}
