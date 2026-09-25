<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

use Nova\Helpers\Response;
use Nova\Repositories\ReceiptRepository;
use Nova\Repositories\TransactionRepository;
use Nova\Services\AuthService;

$auth = new AuthService();
$user = $auth->requireUser();
$userId = (int) $user['id'];
$txnId = (int) ($_GET['transaction_id'] ?? 0);

if ($txnId < 1) {
    Response::error('VALIDATION_ERROR', 'transaction_id required.');
}

$txns = new TransactionRepository();
if (!$txns->findOwned($txnId, $userId)) {
    Response::error('NOT_FOUND', 'Transaction not found.', 404);
}

$receipt = (new ReceiptRepository())->findForTransaction($txnId, $userId);
if (!$receipt) {
    Response::error('NOT_FOUND', 'No receipt attached.', 404);
}

$binary = $receipt['data_blob'];
if (is_resource($binary)) {
    $binary = stream_get_contents($binary);
}

header('Content-Type: ' . ($receipt['mime'] ?: 'image/jpeg'));
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen((string) $binary));
echo $binary;
exit;
