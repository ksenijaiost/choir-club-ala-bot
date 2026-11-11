<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\AccessControl;
use App\Text\TextRepository;
use SergiX44\Nutgram\Nutgram;

final class MaterialsAdminHandler
{
    /**
     * @param array<string, mixed> $user
     */
    public static function openHome(
        Nutgram $bot,
        TextRepository $texts,
        AccessControl $access,
        MaterialsDeps $deps,
        array $user,
    ): void {
        if (!$access->canUploadMaterials($user)) {
            $bot->sendMessage($texts->get('materials.upload_forbidden'));

            return;
        }

        $bot->sendMessage($texts->get('materials.admin_chat_hint'));
        MaterialsCallbackHandler::openManagementHome($bot, $deps);
    }
}
