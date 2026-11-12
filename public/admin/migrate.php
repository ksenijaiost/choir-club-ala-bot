<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Database\Migrator;
use App\Http\AdminGate;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Bootstrap::loadEnv();
$config = Bootstrap::config();

if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $_GET[$k] = $v;
        }
    }
}

AdminGate::requireKey($config);

header('Content-Type: text/plain; charset=utf-8');

try {
    $migrator = Migrator::forConfig($config);
    $applied = $migrator->migrate();

    if ($applied === []) {
        echo "No new migrations.\n";
    } else {
        echo "Applied migrations:\n";
        foreach ($applied as $name) {
            echo " - {$name}\n";
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration failed: ' . $e->getMessage() . "\n";
    exit(1);
}
