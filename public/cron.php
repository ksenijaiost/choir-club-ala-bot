<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Http\AdminGate;
use App\Jobs\CronJobRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();

$runner = new CronJobRunner();

if (PHP_SAPI === 'cli') {
    $result = $runner->run($config, Bootstrap::pdo());
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

AdminGate::requireKey($config);

header('Content-Type: application/json; charset=utf-8');
$result = $runner->run($config, Bootstrap::pdo());
echo json_encode($result, JSON_UNESCAPED_UNICODE);
