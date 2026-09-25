<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Request;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Repositories\RecurringRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$repo = new RecurringRepository();
$method = Request::method();
$body = Request::json();

if ($method === 'GET') {
    // Also materialize any due items when listing
    try {
        (new \Nova\Services\RecurringService())->materializeDue($userId, (string) $user['currency']);
    } catch (Throwable $e) {
        // listing still works
    }
    Response::ok($repo->listForUser($userId));
}

if ($method === 'POST') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $name = Validator::str($body['name'] ?? '', 1, 160);
    $amount = Validator::money($body['amount'] ?? null);
    $type = ($body['type'] ?? '') === 'income' ? 'income' : 'expense';
    $freq = (string) ($body['frequency'] ?? 'monthly');
    $next = (string) ($body['next_date'] ?? '');
    $accountId = (int) ($body['account_id'] ?? 0);
    $categoryId = (int) ($body['category_id'] ?? 0);

    if (!in_array($freq, ['weekly', 'monthly', 'yearly'], true) || !$name || !$amount || !Validator::date($next)) {
        Response::error('VALIDATION_ERROR', 'Invalid recurring transaction.');
    }
    $accounts = new AccountRepository();
    $cats = new CategoryRepository();
    if (!$accounts->findOwned($accountId, $userId)) {
        Response::error('VALIDATION_ERROR', 'Invalid account.');
    }
    if ($categoryId && !$cats->findAccessible($categoryId, $userId)) {
        Response::error('VALIDATION_ERROR', 'Invalid category.');
    }

    $id = $repo->create($userId, [
        'account_id' => $accountId,
        'category_id' => $categoryId ?: null,
        'name' => $name,
        'amount' => $amount,
        'currency' => $user['currency'],
        'type' => $type,
        'frequency' => $freq,
        'next_date' => $next,
    ]);
    Response::ok(['id' => $id], 201);
}

if ($method === 'DELETE') {
    Csrf::requireValid(Request::bearerOrBodyCsrf($body));
    $id = (int) ($_GET['id'] ?? $body['id'] ?? 0);
    if ($id < 1 || !$repo->findOwned($id, $userId)) {
        Response::error('NOT_FOUND', 'Recurring item not found.', 404);
    }
    $repo->deactivate($id, $userId);
    Response::ok(['deleted' => true]);
}

Response::error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
