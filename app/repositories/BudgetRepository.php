<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class BudgetRepository
{
    public function listForUser(int $userId, ?int $year = null, ?int $month = null): array
    {
        $year ??= (int) date('Y');
        $month ??= (int) date('n');

        $stmt = Database::pdo()->prepare(
            'SELECT b.*, bc.id AS bc_id, bc.limit_amount, bc.category_id, c.name AS category_name,
                    COALESCE((
                      SELECT SUM(t.amount) FROM transactions t
                      WHERE t.user_id = b.user_id
                        AND t.category_id = bc.category_id
                        AND t.type = \'expense\'
                        AND t.txn_date >= ?
                        AND t.txn_date <= ?
                    ), 0) AS spent
             FROM budgets b
             JOIN budget_categories bc ON bc.budget_id = b.id
             JOIN categories c ON c.id = bc.category_id
             WHERE b.user_id = ? AND b.year = ? AND b.month = ?
             ORDER BY c.name ASC'
        );
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        $stmt->execute([$from, $to, $userId, $year, $month]);
        return $stmt->fetchAll();
    }

    public function create(int $userId, string $name, int $year, int $month, string $currency, int $categoryId, string $limit): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO budgets (user_id, name, month, year, currency) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $name, $month, $year, $currency]);
            $budgetId = (int) $pdo->lastInsertId();

            $stmt2 = $pdo->prepare(
                'INSERT INTO budget_categories (budget_id, category_id, limit_amount) VALUES (?, ?, ?)'
            );
            $stmt2->execute([$budgetId, $categoryId, $limit]);
            $pdo->commit();
            return $budgetId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
