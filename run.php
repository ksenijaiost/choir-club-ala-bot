#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Bootstrap;

require __DIR__ . '/vendor/autoload.php';

Bootstrap::loadEnv();

$bot = Bootstrap::bot();
Bootstrap::registerHandlers($bot);

$bot->run();
