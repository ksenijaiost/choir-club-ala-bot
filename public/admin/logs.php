<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Http\AdminGate;
use App\Support\ErrorLog;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();
date_default_timezone_set($config->timezone);

if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $_GET[$k] = $v;
        }
    }
}

AdminGate::requireKey($config);

$lines = isset($_GET['lines']) ? (int) $_GET['lines'] : 200;
if ($lines < 1) {
    $lines = 1;
}
if ($lines > 5000) {
    $lines = 5000;
}

$tz = new \DateTimeZone($config->timezone);
$defaultDate = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
$date = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : $defaultDate;

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid date. Use YYYY-MM-DD.\n";
    exit;
}

$parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid date. Use YYYY-MM-DD.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$path = ErrorLog::pathForDate($date);
$tail = ErrorLog::tailLines($path, $lines);

if ($tail === []) {
    echo "No log entries for {$date}.\n";
    exit(0);
}

echo implode("\n", $tail) . "\n";