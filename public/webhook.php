<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Support\ErrorLog;
use SergiX44\Nutgram\RunningMode\Webhook;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();
date_default_timezone_set($config->timezone);

if ($config->webhookSecret !== '') {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!is_string($header) || !hash_equals($config->webhookSecret, $header)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

try {
    $bot = Bootstrap::bot();
    Bootstrap::registerHandlers($bot);

    $webhook = new Webhook(secretToken: $config->webhookSecret !== '' ? $config->webhookSecret : null);
    if ($config->webhookSecret !== '') {
        $webhook->setSafeMode(true);
    }

    $bot->setRunningMode($webhook);
    $bot->run();
} catch (\Throwable $e) {
    $context = ErrorLog::contextFromWebhookInput();
    $context['source'] = 'webhook';
    ErrorLog::log($config, $e, $context);
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ok';
}