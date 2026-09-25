<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use Nova\Services\AuthService;
use Nova\Services\ImportService;
use Nova\Services\TransactionService;

$fail = 0;
function assert_true(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? 'PASS' : 'FAIL') . " $msg\n";
    if (!$cond) {
        $fail++;
    }
}

$suffix = (string) time();
$auth = new AuthService();
$user = $auth->register('Import', "imp{$suffix}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$acc = (new \Nova\Repositories\AccountRepository())->defaultForUser($uid);
$accId = (int) $acc['id'];

(new TransactionService())->create($uid, [
    'type' => 'expense',
    'amount' => '12.50',
    'merchant' => 'Coffee Shop',
    'account_id' => $accId,
    'txn_date' => date('Y-m-d'),
], 'EUR');

$csv = "date,amount,description\n" . date('Y-m-d') . ",12.50,Coffee Shop\n";
$preview = (new ImportService())->ingestCsv($uid, 'test.csv', $csv, $accId, 'EUR');
$row = $preview['rows'][0] ?? [];
assert_true(!empty($row['duplicate_of']), 'duplicate detected');
assert_true(($row['suggested_action'] ?? '') === 'keep', 'suggest keep');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
