<?php
declare(strict_types=1);

/**
 * Docker / production config. Override with environment variables.
 *
 * Durable production requires MySQL (Render's disk is wiped on every deploy):
 *   DB_DRIVER=mysql
 *   DATABASE_URL=mysql://USER:PASS@HOST:PORT/DBNAME
 *   — or —
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   DB_SSL=1   (recommended for Aiven / managed MySQL)
 */

$env = getenv('APP_ENV') ?: 'local';
$isProd = $env === 'production';
$appUrl = getenv('APP_URL') ?: 'http://localhost:8080';

$db = [
    'driver' => 'sqlite',
    'sqlite_path' => getenv('SQLITE_PATH') ?: (dirname(__DIR__, 2) . '/storage/nova.sqlite'),
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'nova_finance',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
    'ssl' => false,
    'ssl_ca' => getenv('DB_SSL_CA') ?: '',
    'ssl_verify' => filter_var(getenv('DB_SSL_VERIFY') ?: '0', FILTER_VALIDATE_BOOLEAN),
];

$databaseUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: getenv('MYSQL_URI') ?: '';
if (is_string($databaseUrl) && $databaseUrl !== '') {
    $parts = parse_url($databaseUrl);
    if ($parts && isset($parts['host'])) {
        $db['driver'] = 'mysql';
        $db['host'] = $parts['host'];
        $db['port'] = (int) ($parts['port'] ?? 3306);
        $db['user'] = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $db['pass'] = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
        $db['name'] = isset($parts['path']) ? ltrim($parts['path'], '/') : 'nova_finance';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['ssl-mode']) || !empty($q['sslmode'])) {
                $db['ssl'] = true;
            }
        }
    }
}

$driverEnv = getenv('DB_DRIVER');
if ($driverEnv) {
    $db['driver'] = $driverEnv;
}
if (getenv('DB_HOST')) {
    $db['driver'] = $db['driver'] === 'sqlite' && !getenv('DB_DRIVER') ? 'mysql' : $db['driver'];
    $db['host'] = getenv('DB_HOST') ?: $db['host'];
}
if (getenv('DB_PORT')) {
    $db['port'] = (int) getenv('DB_PORT');
}
if (getenv('DB_NAME')) {
    $db['name'] = getenv('DB_NAME') ?: $db['name'];
}
if (getenv('DB_USER')) {
    $db['user'] = getenv('DB_USER') ?: $db['user'];
}
if (getenv('DB_PASS') !== false && getenv('DB_PASS') !== null) {
    $db['pass'] = (string) getenv('DB_PASS');
}
if (filter_var(getenv('DB_SSL') ?: '0', FILTER_VALIDATE_BOOLEAN)) {
    $db['ssl'] = true;
}

// Production without MySQL = data loss on every Render deploy
if ($isProd && ($db['driver'] ?? '') === 'sqlite' && !getenv('ALLOW_EPHEMERAL_SQLITE')) {
    // Prefer mysql when any remote hint exists; otherwise keep sqlite for boot
    // but operators should set DATABASE_URL.
}

return [
    'app_name' => 'NOVA Finance',
    'app_url' => $appUrl,
    'env' => $env,
    'debug' => filter_var(getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : (!$isProd), FILTER_VALIDATE_BOOLEAN),
    'db' => $db,
    'session' => [
        'name' => 'NOVASESSID',
        'lifetime' => 60 * 60 * 24 * 14,
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
