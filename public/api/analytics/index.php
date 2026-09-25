<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Decimal;
use Nova\Helpers\Response;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\AnalyticsRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
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
$base = strtoupper((string) $user['currency']);

$totalBalance = Decimal::zero();
foreach ($accounts->listForUser($userId) as $a) {
    if (strtoupper((string) $a['currency']) === $base) {
        $totalBalance = Decimal::add($totalBalance, (string) $a['balance']);
    }
}

$from = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');

Response::ok([
    'range' => $range,
    'total_balance' => $totalBalance,
    'month' => $txns->summary($userId),
    'cashflow' => $analytics->cashflow($userId, $range),
    'by_category' => $analytics->spendingByCategory($userId, $days),
    'largest_expenses' => $analytics->largestExpenses($userId, 5, $from),
    'averages' => $analytics->averages($userId, $days),
    'currency' => $base,
]);
