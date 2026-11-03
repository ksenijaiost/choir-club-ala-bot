<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Http\AdminGate;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();
AdminGate::requireKey($config);

header('Content-Type: text/plain; charset=utf-8');

if ($config->botToken === '') {
    http_response_code(500);
    exit("BOT_TOKEN is not set.\n");
}

if ($config->webhookUrl === '') {
    http_response_code(500);
    exit("WEBHOOK_URL is not set (full URL to public/webhook.php).\n");
}

$bot = Bootstrap::bot();

$ok = $bot->setWebhook(
    url: $config->webhookUrl,
    allowed_updates: ['message', 'callback_query', 'my_chat_member'],
    drop_pending_updates: false,
    secret_token: $config->webhookSecret !== '' ? $config->webhookSecret : null,
);

if ($ok) {
    echo "Webhook set to {$config->webhookUrl}\n";
    $info = $bot->getWebhookInfo();
    if ($info !== null) {
        echo 'Pending updates: ' . ($info->pending_update_count ?? 0) . "\n";
    }
} else {
    http_response_code(500);
    echo "setWebhook returned false.\n";
}
