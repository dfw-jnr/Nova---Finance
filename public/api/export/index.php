<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];

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
