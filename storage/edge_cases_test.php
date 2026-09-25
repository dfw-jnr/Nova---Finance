<?php
declare(strict_types=1);
/**
 * CSV import edge cases + forecast edge cases.
 */
require __DIR__ . '/../app/bootstrap.php';

use Nova\Helpers\Decimal;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\RecurringRepository;
use Nova\Services\AuthService;
use Nova\Services\ForecastService;
use Nova\Services\ImportService;
use Nova\Services\SafeToSpendService;
use Nova\Services\TransactionService;

$fail = 0;
function assert_true(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " $msg\n";
    if (!$cond) {
        $fail++;
    }
}

$auth = new AuthService();
$s = (string) time() . bin2hex(random_bytes(2));
$user = $auth->register('Edge', "e{$s}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$acc = (new AccountRepository())->defaultForUser($uid);
$accId = (int) $acc['id'];
$imp = new ImportService();

// Empty CSV must not succeed (run in subprocess — Response::error exits)
$php = PHP_BINARY;
$bootstrap = str_replace('\\', '/', __DIR__ . '/../app/bootstrap.php');
$script = <<<PHP
<?php
require '{$bootstrap}';
use Nova\Services\ImportService;
use Nova\Services\AuthService;
use Nova\Repositories\AccountRepository;
\$a = new AuthService();
\$u = \$a->register('XE', 'xe{$s}@t.test', 'password123', 'EUR');
\$acc = (new AccountRepository())->defaultForUser((int)\$u['id']);
(new ImportService())->ingestCsv((int)\$u['id'], 'e.csv', '', (int)\$acc['id'], 'EUR');
echo 'LEAK';
PHP;
$tmp = sys_get_temp_dir() . '/nova_empty_csv_' . $s . '.php';
file_put_contents($tmp, $script);
$out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
assert_true($out !== null && !str_contains((string) $out, 'LEAK') && str_contains((string) $out, 'VALIDATION_ERROR'), 'empty CSV rejected');

$csv = "Date,Amount,Description\n2026-03-01,€25.00,\"Coffee, shop\"\n01/04/2026,10.00,Salary deposit\n";
$preview = $imp->ingestCsv($uid, 'mix.csv', $csv, $accId, 'EUR');
assert_true(count($preview['rows'] ?? []) >= 1, 'parsed rows with currency + commas');

// Forecast: no recurring → ending ≈ starting
$fc = (new ForecastService())->forecast($uid, 'EUR', 30);
assert_true(isset($fc['ending_kind']) && $fc['ending_kind'] === 'projected', 'forecast marked projected');
assert_true(Decimal::cmp($fc['starting_balance'], $fc['ending_balance']) === 0, 'no recurring flat forecast');
assert_true(($fc['recurring_events'] ?? -1) === 0, 'zero recurring events');

$txn = new TransactionService();
$txn->create($uid, [
    'client_id' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', random_int(1, 999999)),
    'type' => 'income',
    'amount' => '500.00',
    'merchant' => 'Pay',
    'account_id' => $accId,
    'txn_date' => date('Y-m-d'),
], 'EUR');

$recRepo = new RecurringRepository();
$recRepo->create($uid, [
    'name' => 'Rent',
    'amount' => '200.00',
    'type' => 'expense',
    'frequency' => 'monthly',
    'next_date' => date('Y-m-d'),
    'account_id' => $accId,
    'category_id' => null,
    'currency' => 'EUR',
]);

$fc2 = (new ForecastService())->forecast($uid, 'EUR', 60);
assert_true(Decimal::cmp($fc2['ending_balance'], $fc2['starting_balance']) < 0, 'forecast drops with rent');
assert_true(!empty($fc2['disclaimer']), 'disclaimer present');

$sts = (new SafeToSpendService())->calculate($uid, 'EUR');
assert_true(isset($sts['notes']) && is_array($sts['notes']), 'sts notes array');
assert_true(Decimal::cmp($sts['safe_to_spend'], Decimal::zero()) >= 0, 'sts floored at 0');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
