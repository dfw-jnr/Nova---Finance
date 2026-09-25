<?php
declare(strict_types=1);

/**
 * Copy to env.php for local use, or leave as-is in Docker
 * (Dockerfile copies this file to env.php).
 *
 * Override with environment variables in production:
 *   APP_URL, APP_ENV, APP_DEBUG, DB_DRIVER, …
 */

$env = getenv('APP_ENV') ?: 'local';
$isProd = $env === 'production';
$appUrl = getenv('APP_URL') ?: 'http://localhost:8080';

return [
    'app_name' => 'NOVA Finance',
    'app_url' => $appUrl,
    'env' => $env,
    'debug' => filter_var(getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : (!$isProd), FILTER_VALIDATE_BOOLEAN),

    'db' => [
        'driver' => getenv('DB_DRIVER') ?: ($isProd ? 'sqlite' : 'mysql'),
        'sqlite_path' => getenv('SQLITE_PATH') ?: (dirname(__DIR__, 2) . '/storage/nova.sqlite'),
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'nova_finance',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name' => 'NOVASESSID',
        'lifetime' => 60 * 60 * 24 * 14,
        // Secure cookies required for HTTPS phone installs
        'secure' => $isProd || str_starts_with($appUrl, 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'csrf' => [
        'token_key' => '_csrf',
    ],

    'rate_limit' => [
        'login_max' => 8,
        'login_window' => 900,
    ],

    'currencies' => ['EUR', 'USD', 'GBP', 'GHS'],
    'default_currency' => 'EUR',
];
