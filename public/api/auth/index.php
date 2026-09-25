<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\RateLimiter;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Services\AuthService;

$auth = new AuthService();
$method = Request::method();
$body = Request::json();

if ($method === 'GET') {
    $user = $auth->user();
    Response::ok([
        'authenticated' => (bool) $user,
        'user' => $user,
        'csrf' => Csrf::token(),
    ]);
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? ($body['action'] ?? 'login');

    if ($action === 'logout') {
        Csrf::requireValid(Request::bearerOrBodyCsrf($body));
        $auth->logout();
        Response::ok(['logged_out' => true]);
    }

    if ($action === 'register') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        if (!RateLimiter::hit('register:' . $ip, 5, 3600)) {
            Response::error('RATE_LIMITED', 'Too many registration attempts.', 429);
        }
        Csrf::requireValid(Request::bearerOrBodyCsrf($body));

        $name = Validator::str($body['name'] ?? '', 2, 120);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $currency = strtoupper((string) ($body['currency'] ?? $config['default_currency']));

        if (!$name || !Validator::email($email)) {
            Response::error('VALIDATION_ERROR', 'Valid name and email are required.');
        }
        if (strlen($password) < 8) {
            Response::error('VALIDATION_ERROR', 'Password must be at least 8 characters.');
        }
        if (!Validator::currency($currency, $config['currencies'])) {
            Response::error('VALIDATION_ERROR', 'Unsupported currency.');
        }

        $user = $auth->register($name, $email, $password, $currency);
        Response::ok(['user' => $user, 'csrf' => Csrf::token()], 201);
    }

    // login
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    if (!RateLimiter::hit('login:' . $ip, (int) $config['rate_limit']['login_max'], (int) $config['rate_limit']['login_window'])) {
        Response::error('RATE_LIMITED', 'Too many login attempts. Try again later.', 429);
    }
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));

    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    if (!Validator::email($email) || $password === '') {
        Response::error('VALIDATION_ERROR', 'Email and password are required.');
    }

    $user = $auth->login($email, $password);
    Response::ok(['user' => $user, 'csrf' => Csrf::token()]);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
