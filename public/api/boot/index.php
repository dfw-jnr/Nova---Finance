<?php
declare(strict_types=1);

/**
 * Single-shot bootstrap for the home screen.
 */
$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Csrf;
use Nova\Helpers\Decimal;
use Nova\Helpers\Response;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Repositories\RecurringRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;
use Nova\Services\RecurringService;
use Nova\Services\SafeToSpendService;

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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$accounts = new AccountRepository();
$categories = new CategoryRepository();
$txns = new TransactionRepository();
$base = strtoupper((string) $user['currency']);

$accountList = $accounts->listForUser($userId);
$totalBalance = Decimal::zero();
foreach ($accountList as $a) {
    if (strtoupper((string) $a['currency']) === $base) {
        $totalBalance = Decimal::add($totalBalance, (string) $a['balance']);
    }
}

$month = $txns->summary($userId);
$savings = Decimal::sub($month['income'], $month['expenses']);
if (Decimal::cmp($savings, Decimal::zero()) < 0) {
    $savings = Decimal::zero();
}

$sts = (new SafeToSpendService())->calculate($userId, $base);
$upcoming = array_slice(
    array_values(array_filter(
        (new RecurringRepository())->listForUser($userId),
        static fn ($r) => (int) ($r['is_active'] ?? 1) === 1
    )),
    0,
    5
);

Response::ok([
    'authenticated' => true,
    'user' => $user,
    'csrf' => $csrf,
    'recurring_posted' => $posted,
    'accounts' => $accountList,
    'categories' => $categories->listForUser($userId),
    'home' => [
        'total_balance' => $totalBalance,
        'month' => $month,
        'saved' => $savings,
        'currency' => $base,
        'recent' => $txns->list($userId, ['limit' => 6]),
        'safe_to_spend' => $sts,
        'upcoming' => $upcoming,
    ],
]);
