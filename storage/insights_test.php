<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use Nova\Services\AuthService;
use Nova\Services\InsightsService;
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
$user = $auth->register('Insights', "ins{$suffix}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$acc = (new \Nova\Repositories\AccountRepository())->defaultForUser($uid);
$tx = new TransactionService();
$tx->create($uid, [
    'type' => 'expense',
    'amount' => '50.00',
    'merchant' => 'Big Purchase',
    'account_id' => (int) $acc['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$list = (new InsightsService())->list($uid);
assert_true(is_array($list), 'insights array');
assert_true(count($list) >= 1, 'at least one insight');
$first = $list[0];
assert_true(isset($first['id'], $first['title'], $first['body'], $first['evidence']), 'insight shape');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
