<?php
declare(strict_types=1);

namespace Nova\Repositories;

use Nova\Helpers\Database;
use Nova\Helpers\Decimal;

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
            "SELECT txn_date, type, amount
             FROM transactions
             WHERE user_id = ? AND txn_date >= ? AND type IN ('income','expense')
             ORDER BY txn_date ASC"
        );
        $stmt->execute([$userId, $from]);
        $byDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $d = $row['txn_date'];
            if (!isset($byDate[$d])) {
                $byDate[$d] = ['txn_date' => $d, 'income' => Decimal::zero(), 'expenses' => Decimal::zero()];
            }
            if ($row['type'] === 'income') {
                $byDate[$d]['income'] = Decimal::add($byDate[$d]['income'], (string) $row['amount']);
            } else {
                $byDate[$d]['expenses'] = Decimal::add($byDate[$d]['expenses'], (string) $row['amount']);
            }
        }
        return array_values($byDate);
    }

    public function spendingByCategory(int $userId, int $days = 30): array
    {
        $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $stmt = Database::pdo()->prepare(
            "SELECT COALESCE(c.name, 'Other') AS category, t.amount
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = ? AND t.type = 'expense' AND t.txn_date >= ?"
        );
        $stmt->execute([$userId, $from]);
        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $cat = $row['category'];
            $totals[$cat] = Decimal::add($totals[$cat] ?? Decimal::zero(), (string) $row['amount']);
        }
        $out = [];
        foreach ($totals as $category => $total) {
            $out[] = ['category' => $category, 'total' => $total];
        }
        usort($out, static fn ($a, $b) => Decimal::cmp($b['total'], $a['total']));
        return $out;
    }

    public function largestExpenses(int $userId, int $limit = 5, ?string $from = null): array
    {
        $sql = "SELECT t.merchant, t.amount, t.txn_date, c.name AS category_name, t.id
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = ? AND t.type = 'expense'";
        $params = [$userId];
        if ($from) {
            $sql .= ' AND t.txn_date >= ?';
            $params[] = $from;
        }
        $sql .= ' ORDER BY t.amount + 0 DESC LIMIT ' . (int) $limit;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function averages(int $userId, int $days = 30): array
    {
        $from = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');
        $stmt = Database::pdo()->prepare(
            "SELECT type, amount FROM transactions
             WHERE user_id = ? AND txn_date >= ? AND type IN ('income','expense')"
        );
        $stmt->execute([$userId, $from]);
        $income = Decimal::zero();
        $expenses = Decimal::zero();
        foreach ($stmt->fetchAll() as $row) {
            if ($row['type'] === 'income') {
                $income = Decimal::add($income, (string) $row['amount']);
            } else {
                $expenses = Decimal::add($expenses, (string) $row['amount']);
            }
        }
        $incomeCents = Decimal::toCents($income);
        $expenseCents = Decimal::toCents($expenses);
        $savingsRate = $incomeCents > 0
            ? round((($incomeCents - $expenseCents) / $incomeCents) * 100, 1)
            : 0.0;
        $avgDaily = Decimal::fromCents((int) round($expenseCents / max($days, 1)));
        return [
            'income' => $income,
            'expenses' => $expenses,
            'savings_rate' => $savingsRate,
            'avg_daily_spend' => $avgDaily,
            'days' => $days,
        ];
    }
}
