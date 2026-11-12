<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Http\AdminGate;
use SergiX44\Nutgram\RunningMode\Webhook;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();

if ($config->webhookSecret !== '') {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!is_string($header) || !hash_equals($config->webhookSecret, $header)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$bot = Bootstrap::bot();
Bootstrap::registerHandlers($bot);

$webhook = new Webhook(secretToken: $config->webhookSecret !== '' ? $config->webhookSecret : null);
if ($config->webhookSecret !== '') {
    $webhook->setSafeMode(true);
}

$bot->setRunningMode($webhook);
$bot->run();
