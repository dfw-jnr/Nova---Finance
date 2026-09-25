<?php
declare(strict_types=1);

/**
 * Single-shot bootstrap for the home screen.
 * Cuts several Aiven round-trips into one request (critical on free Render).
 */
$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Response;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;
use Nova\Services\RecurringService;

$auth = new AuthService();
$user = $auth->user();
$csrf = Csrf::token();

if (!$user) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    Response::ok([
        'authenticated' => false,
        'user' => null,
        'csrf' => $csrf,
    ]);
}

$userId = (int) $user['id'];
$posted = 0;
$dayKey = 'recurring_done_' . date('Y-m-d');
if (empty($_SESSION[$dayKey])) {
    try {
        $posted = (new RecurringService())->materializeDue($userId, (string) $user['currency']);
    } catch (Throwable $e) {
        $posted = 0;
    }
    $_SESSION[$dayKey] = 1;
}

// Release session lock before remaining DB work.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$accounts = new AccountRepository();
$categories = new CategoryRepository();
$txns = new TransactionRepository();

$accountList = $accounts->listForUser($userId);
$totalBalance = '0.00';
foreach ($accountList as $a) {
    $totalBalance = number_format((float) $totalBalance + (float) $a['balance'], 2, '.', '');
}

Response::ok([
    'authenticated' => true,
    'user' => $user,
    'csrf' => $csrf,
    'recurring_posted' => $posted,
    'accounts' => $accountList,
    'categories' => $categories->listForUser($userId),
    'home' => [
        'total_balance' => $totalBalance,
        'month' => $txns->summary($userId),
        'currency' => $user['currency'],
        'recent' => $txns->list($userId, ['limit' => 6]),
    ],
]);
