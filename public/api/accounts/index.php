<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\AccountRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$repo = new AccountRepository();
$method = Request::method();
$body = Request::json();

if ($method === 'GET') {
    Response::ok($repo->listForUser($userId));
}

if ($method === 'POST') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $name = Validator::str($body['name'] ?? '', 1, 120);
    $type = (string) ($body['type'] ?? 'bank');
    $currency = strtoupper((string) ($body['currency'] ?? $user['currency']));
    $allowedTypes = ['cash', 'bank', 'savings', 'credit', 'other'];

    if (!$name || !in_array($type, $allowedTypes, true)) {
        Response::error('VALIDATION_ERROR', 'Invalid account data.');
    }
    if (!Validator::currency($currency, $config['currencies'])) {
        Response::error('VALIDATION_ERROR', 'Unsupported currency.');
    }

    $balance = Validator::money($body['balance'] ?? '0.01');
    // Allow zero opening balance
    $opening = '0.00';
    if (isset($body['balance']) && $body['balance'] !== '' && $body['balance'] !== null) {
        $raw = str_replace(',', '.', (string) $body['balance']);
        if (!is_numeric($raw) || (float) $raw < 0 || !is_finite((float) $raw)) {
            Response::error('VALIDATION_ERROR', 'Invalid opening balance.');
        }
        $opening = number_format((float) $raw, 2, '.', '');
    }

    $id = $repo->create($userId, $name, $type, $currency, $opening, !empty($body['is_default']));
    Response::ok($repo->findOwned($id, $userId), 201);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
