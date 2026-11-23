<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Database\Migrator;
use App\Http\AdminGate;
use App\Repositories\BotSettingsRepository;
use App\Services\AdminChatBotCommands;
use App\Services\AdminChatResolver;
use App\Services\PrivateChatBotCommands;

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

    if ($config->botToken !== '') {
        $bot = Bootstrap::bot();
        $texts = Bootstrap::texts();
        try {
            PrivateChatBotCommands::register($bot, $texts);
            echo "Private chat commands registered.\n";
        } catch (\Throwable $e) {
            echo 'Private chat commands registration failed: ' . $e->getMessage() . "\n";
        }
        $settings = new BotSettingsRepository(Bootstrap::pdo());
        $resolver = new AdminChatResolver($settings, $config);
        $adminChatId = $resolver->resolvedIdAsInt();
        if ($adminChatId !== null) {
            try {
                AdminChatBotCommands::registerForAdminChat($bot, $texts, $adminChatId);
                echo "Admin chat commands registered for chat {$adminChatId}.\n";
            } catch (\Throwable $e) {
                echo 'Admin chat commands registration failed: ' . $e->getMessage() . "\n";
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration failed: ' . $e->getMessage() . "\n";
    exit(1);
}
