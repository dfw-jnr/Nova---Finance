<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;
use Nova\Helpers\Decimal;

final class TransactionRepository
{
    public function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO transactions
            (user_id, account_id, category_id, client_id, type, amount, currency, merchant, description, notes, txn_date, transfer_group_id, external_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['account_id'],
            $data['category_id'] ?? null,
            $data['client_id'] ?? null,
            $data['type'],
            $data['amount'],
            $data['currency'],
            $data['merchant'],
            $data['description'] ?? '',
            $data['notes'] ?? '',
            $data['txn_date'],
            $data['transfer_group_id'] ?? null,
            $data['external_id'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function findByClientId(int $userId, string $clientId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM transactions WHERE user_id = ? AND client_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $clientId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByExternalId(int $userId, int $accountId, string $externalId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM transactions WHERE user_id = ? AND account_id = ? AND external_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $accountId, $externalId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, c.name AS category_name, a.name AS account_name,
                    (SELECT 1 FROM receipts r WHERE r.transaction_id = t.id LIMIT 1) AS has_receipt
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE t.id = ? AND t.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByTransferGroup(string $groupId, int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, a.name AS account_name
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE t.user_id = ? AND t.transfer_group_id = ?
             ORDER BY t.id ASC'
        );
        $stmt->execute([$userId, $groupId]);
        return $stmt->fetchAll();
    }

    public function list(int $userId, array $filters = []): array
    {
        $sql = 'SELECT t.*, c.name AS category_name, a.name AS account_name,
                       (SELECT 1 FROM receipts r WHERE r.transaction_id = t.id LIMIT 1) AS has_receipt
                FROM transactions t
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN accounts a ON a.id = t.account_id
                WHERE t.user_id = ?';
        $params = [$userId];

        if (!empty($filters['type']) && in_array($filters['type'], ['income', 'expense', 'transfer'], true)) {
            $sql .= ' AND t.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['category_id'])) {
            $sql .= ' AND t.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND t.txn_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND t.txn_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $sql .= ' AND (t.merchant LIKE ? OR t.description LIKE ? OR c.name LIKE ? OR CAST(t.amount AS CHAR) LIKE ?)';
            $q = '%' . $filters['q'] . '%';
            array_push($params, $q, $q, $q, $q);
        }

        $sort = ($filters['sort'] ?? 'date_desc') === 'date_asc' ? 'ASC' : 'DESC';
        $limit = min(10000, max(1, (int) ($filters['limit'] ?? 200)));
        $sql .= " ORDER BY t.txn_date $sort, t.id $sort LIMIT {$limit}";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function update(int $id, int $userId, array $data): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE transactions SET
                account_id = ?, category_id = ?, type = ?, amount = ?, currency = ?,
                merchant = ?, description = ?, notes = ?, txn_date = ?
             WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([
            $data['account_id'],
            $data['category_id'],
            $data['type'],
            $data['amount'],
            $data['currency'],
            $data['merchant'],
            $data['description'],
            $data['notes'],
            $data['txn_date'],
            $id,
            $userId,
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }

    public function deleteByTransferGroup(string $groupId, int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM transactions WHERE transfer_group_id = ? AND user_id = ?'
        );
        $stmt->execute([$groupId, $userId]);
        return $stmt->rowCount();
    }

    public function summary(int $userId): array
    {
        $year = date('Y');
        $month = date('m');
        $from = sprintf('%s-%s-01', $year, $month);
        $to = date('Y-m-t');

        $stmt = Database::pdo()->prepare(
            "SELECT type, amount
             FROM transactions
             WHERE user_id = ? AND txn_date >= ? AND txn_date <= ? AND type IN ('income','expense')"
        );
        $stmt->execute([$userId, $from, $to]);
        $income = Decimal::zero();
        $expenses = Decimal::zero();
        foreach ($stmt->fetchAll() as $row) {
            if ($row['type'] === 'income') {
                $income = Decimal::add($income, (string) $row['amount']);
            } else {
                $expenses = Decimal::add($expenses, (string) $row['amount']);
            }
        }
        return ['income' => $income, 'expenses' => $expenses];
    }

    /** Find likely duplicates for import review. */
    public function findLikelyDuplicate(int $userId, int $accountId, string $amount, string $date, string $merchant): ?array
    {
        $from = (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $to = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM transactions
             WHERE user_id = ? AND account_id = ? AND amount = ?
               AND txn_date >= ? AND txn_date <= ?
               AND type IN ('income','expense')
             ORDER BY id DESC LIMIT 20"
        );
        $stmt->execute([$userId, $accountId, $amount, $from, $to]);
        $needle = mb_strtolower(trim($merchant));
        foreach ($stmt->fetchAll() as $row) {
            $hay = mb_strtolower((string) $row['merchant']);
            if ($hay === $needle || str_contains($hay, $needle) || str_contains($needle, $hay)) {
                return $row;
            }
        }
        return null;
    }
}
