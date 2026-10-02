<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Load .env.test for test environment BEFORE kernel creation
// Use overload to ensure test env vars override .env
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'test') {
    echo "DEBUG: Loading .env.test from " . dirname(__DIR__).'/.env.test' . PHP_EOL;
    echo "DIR: " . __DIR__ . PHP_EOL;
    echo "dirname(DIR): " . dirname(__DIR__) . PHP_EOL;
    (new Dotenv())->loadEnv(dirname(__DIR__).'/.env.test', true);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
