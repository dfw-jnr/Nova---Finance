<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Repositories\AnalyticsRepository;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$range = strtoupper((string) ($_GET['range'] ?? '30D'));
if (!in_array($range, ['7D', '30D', '90D', '1Y'], true)) {
    $range = '30D';
}
$days = match ($range) {
    '7D' => 7,
    '90D' => 90,
    '1Y' => 365,
    default => 30,
};

$analytics = new AnalyticsRepository();
$accounts = new AccountRepository();
$txns = new TransactionRepository();

$accountList = $accounts->listForUser($userId);
$totalBalance = '0.00';
foreach ($accountList as $a) {
    $totalBalance = number_format((float) $totalBalance + (float) $a['balance'], 2, '.', '');
}

$month = $txns->summary($userId);
$averages = $analytics->averages($userId, $days);

Response::ok([
    'range' => $range,
    'total_balance' => $totalBalance,
    'month' => $month,
    'cashflow' => $analytics->cashflow($userId, $range),
    'by_category' => $analytics->spendingByCategory($userId, $days),
    'largest_expenses' => $analytics->largestExpenses($userId),
    'averages' => $averages,
    'currency' => $user['currency'],
]);
