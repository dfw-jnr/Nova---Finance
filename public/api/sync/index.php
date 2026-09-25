<?php
declare(strict_types=1);

/**
 * Offline sync: accept a batch of pending transactions with client_id for idempotency.
 */
$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Services\AuthService;
use Nova\Services\TransactionService;

$auth = new AuthService();
$user = $auth->requireUser();
$body = Request::json();

if (Request::method() !== 'POST') {
    Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

Csrf::requireValid(Request::bearerOrBodyCsrf($body));

$items = $body['transactions'] ?? [];
if (!is_array($items)) {
    Response::error('VALIDATION_ERROR', 'Invalid sync payload.');
}

$service = new TransactionService();
$results = [];
foreach (array_slice($items, 0, 50) as $item) {
    if (!is_array($item)) {
        continue;
    }
    try {
        $row = $service->create((int) $user['id'], $item, $user['currency']);
        $results[] = [
            'client_id' => $item['client_id'] ?? null,
            'success' => true,
            'data' => $row,
        ];
    } catch (Throwable $e) {
        $results[] = [
            'client_id' => $item['client_id'] ?? null,
            'success' => false,
            'error' => 'SYNC_ITEM_FAILED',
        ];
    }
}

Response::ok(['results' => $results]);
