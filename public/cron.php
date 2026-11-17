<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Http\AdminGate;
use App\Jobs\CronJobRunner;
use App\Support\ErrorLog;
use App\Telegram\NutgramErrorHooks;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();
date_default_timezone_set($config->timezone);

$runner = new CronJobRunner();
$bot = $config->botToken !== '' ? Bootstrap::bot() : null;
if ($bot !== null) {
    NutgramErrorHooks::register($bot, $config, Bootstrap::texts(), Bootstrap::errorReportService());
}

try {
    if (PHP_SAPI === 'cli') {
        $result = $runner->run($config, Bootstrap::pdo(), $bot);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    AdminGate::requireKey($config);

    header('Content-Type: application/json; charset=utf-8');
    $result = $runner->run($config, Bootstrap::pdo(), $bot);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    ErrorLog::log($config, $e, ['source' => 'cron']);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Cron failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Cron failed'], JSON_UNESCAPED_UNICODE);
}