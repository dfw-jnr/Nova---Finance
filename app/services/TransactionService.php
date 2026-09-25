<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Helpers\Decimal;
use Nova\Helpers\Database;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Repositories\ReceiptRepository;
use Nova\Repositories\TransactionRepository;

final class TransactionService
{
    public function __construct(
        private TransactionRepository $txns = new TransactionRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private CategoryRepository $categories = new CategoryRepository(),
        private ReceiptRepository $receipts = new ReceiptRepository(),
    ) {}

    public function create(int $userId, array $input, string $userCurrency): array
    {
        if (($input['type'] ?? '') === 'transfer') {
            return $this->transfer($userId, $input, $userCurrency);
        }

        $clientId = isset($input['client_id']) ? (string) $input['client_id'] : null;
        if ($clientId) {
            if (!Validator::uuid($clientId)) {
                Response::error('VALIDATION_ERROR', 'Invalid client_id.');
            }
            $existing = $this->txns->findByClientId($userId, $clientId);
            if ($existing) {
                return $this->txns->findOwned((int) $existing['id'], $userId) ?? $existing;
            }
        }

        $type = ($input['type'] ?? '') === 'income' ? 'income' : 'expense';
        $amount = Validator::money($input['amount'] ?? null);
        $merchant = Validator::str($input['merchant'] ?? ($input['description'] ?? ''), 1, 160);
        $txnDate = (string) ($input['txn_date'] ?? $input['date'] ?? '');
        $accountId = (int) ($input['account_id'] ?? 0);
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : 0;
        $externalId = isset($input['external_id']) ? Validator::str((string) $input['external_id'], 1, 64) : null;

        if (!$amount) {
            Response::error('VALIDATION_ERROR', 'Invalid amount.');
        }
        if (!$merchant) {
            Response::error('VALIDATION_ERROR', 'Merchant is required.');
        }
        if (!Validator::date($txnDate)) {
            Response::error('VALIDATION_ERROR', 'Invalid date.');
        }

        $account = $this->accounts->findOwned($accountId, $userId);
        if (!$account) {
            $account = $this->accounts->defaultForUser($userId);
        }
        if (!$account) {
            Response::error('VALIDATION_ERROR', 'No account available.', 422);
        }

        if ($externalId) {
            $dup = $this->txns->findByExternalId($userId, (int) $account['id'], $externalId);
            if ($dup) {
                return $this->txns->findOwned((int) $dup['id'], $userId) ?? $dup;
            }
        }

        $category = null;
        if ($categoryId > 0) {
            $category = $this->categories->findAccessible($categoryId, $userId);
            if (!$category) {
                Response::error('VALIDATION_ERROR', 'Invalid category.');
            }
        }

        $signedDelta = $type === 'income' ? $amount : Decimal::negate($amount);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->txns->create([
                'user_id' => $userId,
                'account_id' => (int) $account['id'],
                'category_id' => $category ? (int) $category['id'] : null,
                'client_id' => $clientId,
                'type' => $type,
                'amount' => $amount,
                'currency' => $account['currency'] ?: $userCurrency,
                'merchant' => $merchant,
                'description' => Validator::str((string) ($input['description'] ?? ''), 0, 500) ?? '',
                'notes' => Validator::str((string) ($input['notes'] ?? ''), 0, 2000) ?? '',
                'txn_date' => $txnDate,
                'external_id' => $externalId,
            ]);
            $this->accounts->adjustBalance((int) $account['id'], $signedDelta);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ((int) $e->getCode() === 23000 && $clientId) {
                $existing = $this->txns->findByClientId($userId, $clientId);
                if ($existing) {
                    return $this->txns->findOwned((int) $existing['id'], $userId) ?? $existing;
                }
            }
            throw $e;
        }

        $this->attachReceiptIfPresent($userId, $id, $input);

        return $this->txns->findOwned($id, $userId) ?? ['id' => $id];
    }

    /**
     * Atomic transfer: two linked transfer rows, excluded from income/expense.
     */
    public function transfer(int $userId, array $input, string $userCurrency): array
    {
        $clientId = isset($input['client_id']) ? (string) $input['client_id'] : null;
        if ($clientId) {
            if (!Validator::uuid($clientId)) {
                Response::error('VALIDATION_ERROR', 'Invalid client_id.');
            }
            $existing = $this->txns->findByClientId($userId, $clientId);
            if ($existing) {
                $group = $existing['transfer_group_id'] ?? null;
                if ($group) {
                    return [
                        'type' => 'transfer',
                        'transfer_group_id' => $group,
                        'legs' => $this->txns->findByTransferGroup($group, $userId),
                    ];
                }
                return $this->txns->findOwned((int) $existing['id'], $userId) ?? $existing;
            }
        }

        $amount = Validator::money($input['amount'] ?? null);
        $fromId = (int) ($input['from_account_id'] ?? $input['account_id'] ?? 0);
        $toId = (int) ($input['to_account_id'] ?? 0);
        $txnDate = (string) ($input['txn_date'] ?? $input['date'] ?? '');
        $notes = Validator::str((string) ($input['notes'] ?? ''), 0, 2000) ?? '';

        if (!$amount) {
            Response::error('VALIDATION_ERROR', 'Invalid amount.');
        }
        if (!Validator::date($txnDate)) {
            Response::error('VALIDATION_ERROR', 'Invalid date.');
        }
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            Response::error('VALIDATION_ERROR', 'Choose two different accounts.');
        }

        $from = $this->accounts->findOwned($fromId, $userId);
        $to = $this->accounts->findOwned($toId, $userId);
        if (!$from || !$to) {
            Response::error('FORBIDDEN', 'You do not own one of these accounts.', 403);
        }
        if (strtoupper((string) $from['currency']) !== strtoupper((string) $to['currency'])) {
            Response::error('VALIDATION_ERROR', 'Transfers require the same currency on both accounts.');
        }

        $groupId = $this->uuid();
        $outClient = $clientId ?: $this->uuid();
        $inClient = $this->uuid();
        $merchantOut = 'Transfer to ' . $to['name'];
        $merchantIn = 'Transfer from ' . $from['name'];

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $outId = $this->txns->create([
                'user_id' => $userId,
                'account_id' => $fromId,
                'category_id' => null,
                'client_id' => $outClient,
                'type' => 'transfer',
                'amount' => $amount,
                'currency' => $from['currency'] ?: $userCurrency,
                'merchant' => $merchantOut,
                'description' => '',
                'notes' => $notes,
                'txn_date' => $txnDate,
                'transfer_group_id' => $groupId,
            ]);
            $inId = $this->txns->create([
                'user_id' => $userId,
                'account_id' => $toId,
                'category_id' => null,
                'client_id' => $inClient,
                'type' => 'transfer',
                'amount' => $amount,
                'currency' => $to['currency'] ?: $userCurrency,
                'merchant' => $merchantIn,
                'description' => '',
                'notes' => $notes,
                'txn_date' => $txnDate,
                'transfer_group_id' => $groupId,
            ]);
            $this->accounts->adjustBalance($fromId, Decimal::negate($amount));
            $this->accounts->adjustBalance($toId, $amount);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'type' => 'transfer',
            'transfer_group_id' => $groupId,
            'legs' => $this->txns->findByTransferGroup($groupId, $userId),
            'out_id' => $outId,
            'in_id' => $inId,
        ];
    }

    public function update(int $userId, int $id, array $input, string $userCurrency): array
    {
        $current = $this->txns->findOwned($id, $userId);
        if (!$current) {
            Response::error('NOT_FOUND', 'Transaction not found.', 404);
        }
        if ($current['type'] === 'transfer') {
            Response::error('VALIDATION_ERROR', 'Transfers cannot be edited. Delete and recreate.');
        }

        $type = ($input['type'] ?? $current['type']) === 'income' ? 'income' : 'expense';
        $amount = Validator::money($input['amount'] ?? $current['amount']);
        $merchant = Validator::str($input['merchant'] ?? $current['merchant'], 1, 160);
        $txnDate = (string) ($input['txn_date'] ?? $current['txn_date']);
        $accountId = (int) ($input['account_id'] ?? $current['account_id']);
        $categoryId = (int) ($input['category_id'] ?? $current['category_id']);

        if (!$amount || !$merchant || !Validator::date($txnDate)) {
            Response::error('VALIDATION_ERROR', 'Invalid transaction data.');
        }

        $account = $this->accounts->findOwned($accountId, $userId);
        if (!$account) {
            Response::error('VALIDATION_ERROR', 'Invalid account.');
        }

        $oldDelta = $current['type'] === 'income'
            ? (string) $current['amount']
            : Decimal::negate((string) $current['amount']);
        $newDelta = $type === 'income' ? $amount : Decimal::negate($amount);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $this->accounts->adjustBalance((int) $current['account_id'], Decimal::negate($oldDelta));
            $this->txns->update($id, $userId, [
                'account_id' => $accountId,
                'category_id' => $categoryId ?: null,
                'type' => $type,
                'amount' => $amount,
                'currency' => $account['currency'] ?: $userCurrency,
                'merchant' => $merchant,
                'description' => Validator::str((string) ($input['description'] ?? $current['description'] ?? ''), 0, 500) ?? '',
                'notes' => Validator::str((string) ($input['notes'] ?? $current['notes'] ?? ''), 0, 2000) ?? '',
                'txn_date' => $txnDate,
            ]);
            $this->accounts->adjustBalance($accountId, $newDelta);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->attachReceiptIfPresent($userId, $id, $input);

        return $this->txns->findOwned($id, $userId) ?? [];
    }

    public function delete(int $userId, int $id): void
    {
        $current = $this->txns->findOwned($id, $userId);
        if (!$current) {
            Response::error('NOT_FOUND', 'Transaction not found.', 404);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($current['type'] === 'transfer' && !empty($current['transfer_group_id'])) {
                $legs = $this->txns->findByTransferGroup((string) $current['transfer_group_id'], $userId);
                foreach ($legs as $leg) {
                    // Outgoing leg: merchant starts with "Transfer to" → was deducted
                    // Incoming: "Transfer from" → was added
                    $isOut = str_starts_with((string) $leg['merchant'], 'Transfer to');
                    $delta = $isOut
                        ? (string) $leg['amount']
                        : Decimal::negate((string) $leg['amount']);
                    $this->accounts->adjustBalance((int) $leg['account_id'], $delta);
                }
                $this->txns->deleteByTransferGroup((string) $current['transfer_group_id'], $userId);
            } else {
                $delta = $current['type'] === 'income'
                    ? (string) $current['amount']
                    : Decimal::negate((string) $current['amount']);
                $this->accounts->adjustBalance((int) $current['account_id'], Decimal::negate($delta));
                $this->txns->delete($id, $userId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function attachReceiptIfPresent(int $userId, int $transactionId, array $input): void
    {
        $raw = (string) ($input['receipt_base64'] ?? '');
        if ($raw === '') {
            return;
        }
        $mime = 'image/jpeg';
        if (preg_match('#^data:(image/(?:jpeg|jpg|png|webp|heic|heif));base64,#i', $raw, $m)) {
            $mime = strtolower($m[1]);
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $binary = base64_decode($raw, true);
        if ($binary === false || strlen($binary) < 32) {
            Response::error('VALIDATION_ERROR', 'Invalid receipt image.');
        }
        if (strlen($binary) > 3_500_000) {
            Response::error('VALIDATION_ERROR', 'Receipt image is too large (max ~3MB).');
        }
        $ocr = isset($input['receipt_ocr']) ? substr((string) $input['receipt_ocr'], 0, 8000) : null;
        $this->receipts->upsert($userId, $transactionId, $mime, $binary, $ocr);
    }
}
