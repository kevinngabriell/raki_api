<?php
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

loadEnv(__DIR__ . '/.env');

define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('DB_SCHEMA', $_ENV['DB_SCHEMA'] ?? 'raki');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();