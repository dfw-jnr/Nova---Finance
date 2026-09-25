<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;

final class AnalyticsRepository
{
    public function cashflow(int $userId, string $range): array
    {
        $days = match ($range) {
            '7D' => 7,
            '90D' => 90,
            '1Y' => 365,
            default => 30,
        };
        $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');

        $stmt = Database::pdo()->prepare(
            "SELECT txn_date,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expenses
             FROM transactions
             WHERE user_id = ? AND txn_date >= ?
             GROUP BY txn_date
             ORDER BY txn_date ASC"
        );
        $stmt->execute([$userId, $from]);
        return $stmt->fetchAll();
    }

    public function spendingByCategory(int $userId, int $days = 30): array
    {
        $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $stmt = Database::pdo()->prepare(
            "SELECT COALESCE(c.name, 'Other') AS category, SUM(t.amount) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = ? AND t.type = 'expense' AND t.txn_date >= ?
             GROUP BY COALESCE(c.name, 'Other')
             ORDER BY total DESC"
        );
        $stmt->execute([$userId, $from]);
        return $stmt->fetchAll();
    }

    public function largestExpenses(int $userId, int $limit = 5): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT t.merchant, t.amount, t.txn_date, c.name AS category_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = ? AND t.type = 'expense'
             ORDER BY CAST(t.amount AS REAL) DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function averages(int $userId, int $days = 30): array
    {
        $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $stmt = Database::pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
             FROM transactions
             WHERE user_id = ? AND txn_date >= ?"
        );
        $stmt->execute([$userId, $from]);
        $row = $stmt->fetch() ?: ['income' => 0, 'expenses' => 0];
        $income = (float) $row['income'];
        $expenses = (float) $row['expenses'];
        $savingsRate = $income > 0 ? round((($income - $expenses) / $income) * 100, 1) : 0.0;
        $avgDaily = round($expenses / max($days, 1), 2);
        return [
            'income' => number_format($income, 2, '.', ''),
            'expenses' => number_format($expenses, 2, '.', ''),
            'savings_rate' => $savingsRate,
            'avg_daily_spend' => number_format($avgDaily, 2, '.', ''),
            'days' => $days,
        ];
    }
}
