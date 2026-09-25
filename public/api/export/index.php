<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\BudgetRepository;
use Nova\Repositories\GoalRepository;
use Nova\Repositories\RecurringRepository;
use Nova\Repositories\SettingsRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];

$format = strtolower((string) ($_GET['format'] ?? 'csv'));

if ($format === 'json') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $budgetRepo = new BudgetRepository();
    $year = (int) date('Y');
    $month = (int) date('n');
    $payload = [
        'exported_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'user' => [
            'id' => $userId,
            'name' => $user['name'],
            'email' => $user['email'],
            'currency' => $user['currency'],
        ],
        'accounts' => (new AccountRepository())->listForUser($userId),
        'transactions' => (new TransactionRepository())->list($userId, ['sort' => 'date_asc', 'limit' => 10000]),
        'budgets' => $budgetRepo->listForUser($userId, $year, $month),
        'goals' => (new GoalRepository())->listForUser($userId),
        'recurring' => (new RecurringRepository())->listForUser($userId),
        'settings' => (new SettingsRepository())->all($userId),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="nova-export-' . date('Y-m-d') . '.json"');
    echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$repo = new TransactionRepository();
$rows = $repo->list($userId, ['sort' => 'date_asc', 'limit' => 10000]);

$filename = 'nova-transactions-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
fputcsv($out, ['Date', 'Type', 'Merchant', 'Category', 'Account', 'Amount', 'Currency', 'Notes']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['txn_date'] ?? '',
        $r['type'] ?? '',
        $r['merchant'] ?? '',
        $r['category_name'] ?? '',
        $r['account_name'] ?? '',
        $r['amount'] ?? '',
        $r['currency'] ?? ($user['currency'] ?? 'EUR'),
        $r['notes'] ?? ($r['description'] ?? ''),
    ]);
}
fclose($out);
exit;
