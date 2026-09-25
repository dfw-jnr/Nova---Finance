<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class TransactionRepository
{
    public function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO transactions
            (user_id, account_id, category_id, client_id, type, amount, currency, merchant, description, notes, txn_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['account_id'],
            $data['category_id'],
            $data['client_id'],
            $data['type'],
            $data['amount'],
            $data['currency'],
            $data['merchant'],
            $data['description'],
            $data['notes'],
            $data['txn_date'],
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

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, c.name AS category_name, a.name AS account_name
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

    public function list(int $userId, array $filters = []): array
    {
        $sql = 'SELECT t.*, c.name AS category_name, a.name AS account_name
                FROM transactions t
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN accounts a ON a.id = t.account_id
                WHERE t.user_id = ?';
        $params = [$userId];

        if (!empty($filters['type']) && in_array($filters['type'], ['income', 'expense'], true)) {
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

    public function summary(int $userId): array
    {
        $year = date('Y');
        $month = date('m');
        $from = sprintf('%s-%s-01', $year, $month);
        $to = date('Y-m-t');

        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
             FROM transactions
             WHERE user_id = ? AND txn_date >= ? AND txn_date <= ?"
        );
        $stmt->execute([$userId, $from, $to]);
        return $stmt->fetch() ?: ['income' => '0.00', 'expenses' => '0.00'];
    }
}
