<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use Nova\Repositories\AccountRepository;
use Nova\Services\AuthService;
use Nova\Services\ImportService;

$fail = 0;
function assert_true(bool $c, string $m): void {
    global $fail;
    echo ($c ? "PASS" : "FAIL") . " $m\n";
    if (!$c) $fail++;
}

$auth = new AuthService();
$s = (string) time() . bin2hex(random_bytes(2));
$user = $auth->register('Map', "map{$s}@nova.test", 'password123', 'EUR');
$uid = (int) $user['id'];
$accId = (int) (new AccountRepository())->defaultForUser($uid)['id'];
$imp = new ImportService();

// Weird headers — auto-detect may map poorly; remap fixes
$csv = "Foo,Bar,Baz\n2026-01-02,12.00,Coffee\n";
$preview = $imp->ingestCsv($uid, 'weird.csv', $csv, $accId, 'EUR');
assert_true(!empty($preview['headers']), 'headers returned');
assert_true(isset($preview['column_map']['date']), 'suggested map');

$remapped = $imp->remap($uid, (int) $preview['import']['id'], [
    'date' => 'Foo',
    'amount' => 'Bar',
    'description' => 'Baz',
], 'EUR');
$row = $remapped['rows'][0] ?? [];
assert_true(($row['merchant'] ?? '') === 'Coffee', 'remap merchant');
assert_true(($row['amount'] ?? '') === '12.00', 'remap amount');
assert_true(($row['txn_date'] ?? '') === '2026-01-02', 'remap date');

echo $fail === 0 ? "ALL PASS\n" : "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
