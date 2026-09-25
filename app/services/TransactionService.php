<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Helpers\Decimal;
use Nova\Helpers\Database;
use Nova\Helpers\Response;
use Nova\Helpers\Validator;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\CategoryRepository;
use Nova\Repositories\TransactionRepository;

final class TransactionService
{
    public function __construct(
        private TransactionRepository $txns = new TransactionRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private CategoryRepository $categories = new CategoryRepository(),
    ) {}

    public function create(int $userId, array $input, string $userCurrency): array
    {
        $clientId = isset($input['client_id']) ? (string) $input['client_id'] : null;
        if ($clientId) {
            if (!Validator::uuid($clientId)) {
                Response::error('VALIDATION_ERROR', 'Invalid client_id.');
            }
            $existing = $this->txns->findByClientId($userId, $clientId);
            if ($existing) {
                // Idempotent: return existing row (no duplicate)
                return $this->txns->findOwned((int) $existing['id'], $userId) ?? $existing;
            }
        }

        $type = ($input['type'] ?? '') === 'income' ? 'income' : 'expense';
        $amount = Validator::money($input['amount'] ?? null);
        $merchant = Validator::str($input['merchant'] ?? ($input['description'] ?? ''), 1, 160);
        $txnDate = (string) ($input['txn_date'] ?? $input['date'] ?? '');
        $accountId = (int) ($input['account_id'] ?? 0);
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : 0;

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

        $category = null;
        if ($categoryId > 0) {
            $category = $this->categories->findAccessible($categoryId, $userId);
            if (!$category) {
                Response::error('VALIDATION_ERROR', 'Invalid category.');
            }
        }

        $signedDelta = $type === 'income' ? $amount : '-' . $amount;
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
            ]);
            $this->accounts->adjustBalance((int) $account['id'], $signedDelta);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            // Unique client_id race → fetch existing
            if ((int) $e->getCode() === 23000 && $clientId) {
                $existing = $this->txns->findByClientId($userId, $clientId);
                if ($existing) {
                    return $this->txns->findOwned((int) $existing['id'], $userId) ?? $existing;
                }
            }
            throw $e;
        }

        return $this->txns->findOwned($id, $userId) ?? ['id' => $id];
    }

    public function update(int $userId, int $id, array $input, string $userCurrency): array
    {
        $current = $this->txns->findOwned($id, $userId);
        if (!$current) {
            Response::error('NOT_FOUND', 'Transaction not found.', 404);
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

        $oldDelta = $current['type'] === 'income' ? $current['amount'] : '-' . $current['amount'];
        $newDelta = $type === 'income' ? $amount : '-' . $amount;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $this->accounts->adjustBalance((int) $current['account_id'], Decimal::negate((string) $oldDelta));
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

        return $this->txns->findOwned($id, $userId) ?? [];
    }

    public function delete(int $userId, int $id): void
    {
        $current = $this->txns->findOwned($id, $userId);
        if (!$current) {
            Response::error('NOT_FOUND', 'Transaction not found.', 404);
        }
        $delta = $current['type'] === 'income' ? $current['amount'] : '-' . $current['amount'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $this->accounts->adjustBalance((int) $current['account_id'], Decimal::negate((string) $delta));
            $this->txns->delete($id, $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
