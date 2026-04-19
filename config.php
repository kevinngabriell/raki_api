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

define('SMTP_HOST',       $_ENV['SMTP_HOST']       ?? 'smtp.sumopod.com');
define('SMTP_PORT',       (int)($_ENV['SMTP_PORT'] ?? 465));
define('SMTP_USER',       $_ENV['SMTP_USER']       ?? '');
define('SMTP_PASS',       $_ENV['SMTP_PASS']       ?? '');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? '');
define('SMTP_FROM_NAME',  $_ENV['SMTP_FROM_NAME']  ?? 'Raki');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();