<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use Nova\Helpers\Decimal;
use Nova\Services\AuthService;
use Nova\Services\SafeToSpendService;
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
$user = $auth->register('STS', "sts{$suffix}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$acc = (new \Nova\Repositories\AccountRepository())->defaultForUser($uid);
(new TransactionService())->create($uid, [
    'type' => 'income',
    'amount' => '100.00',
    'merchant' => 'Pay',
    'account_id' => (int) $acc['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$sts = (new SafeToSpendService())->calculate($uid, 'EUR');
assert_true(isset($sts['safe_to_spend'], $sts['lines']), 'sts payload');
assert_true(Decimal::cmp($sts['available'], '100.00') === 0, 'available 100');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
