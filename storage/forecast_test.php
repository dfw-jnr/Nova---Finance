<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use Nova\Helpers\Decimal;
use Nova\Services\AuthService;
use Nova\Services\ForecastService;
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
$user = $auth->register('Forecast', "fc{$suffix}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$svc = new TransactionService();
$acc = (new \Nova\Repositories\AccountRepository())->defaultForUser($uid);
$svc->create($uid, [
    'type' => 'income',
    'amount' => '200.00',
    'merchant' => 'Seed',
    'account_id' => (int) $acc['id'],
    'txn_date' => date('Y-m-d'),
], 'EUR');

$forecast = (new ForecastService())->forecast($uid, 'EUR', 30);
assert_true(isset($forecast['starting_balance']), 'starting_balance set');
assert_true(Decimal::cmp($forecast['starting_balance'], '200.00') === 0, 'starts at 200');
assert_true(count($forecast['points']) >= 2, 'has points');
assert_true(!empty($forecast['disclaimer']), 'disclaimer present');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
