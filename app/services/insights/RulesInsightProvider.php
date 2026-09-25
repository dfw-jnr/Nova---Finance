<?php

declare(strict_types=1);



namespace Nova\Services\Insights;



use Nova\Helpers\Database;

use Nova\Helpers\Decimal;

use Nova\Repositories\AnalyticsRepository;

use Nova\Repositories\RecurringRepository;



/**

 * Deterministic rule-based insight generators (no LLM).

 */

final class RulesInsightProvider

{

    public function __construct(

        private AnalyticsRepository $analytics = new AnalyticsRepository(),

        private RecurringRepository $recurring = new RecurringRepository(),

    ) {}



    /** @return list<array<string, mixed>> */

    public function generate(int $userId): array

    {

        $insights = [];

        foreach ([

            fn () => $this->expenseMomTotal($userId),

            fn () => $this->momCategoryDelta($userId),

            fn () => $this->categoryShare($userId),

            fn () => $this->recurringMonthlyTotal($userId),

            fn () => $this->largestExpense($userId),

        ] as $gen) {

            $item = $gen();

            if ($item !== null) {

                $insights[] = $item;

            }

        }

        return $insights;

    }



    private function expenseMomTotal(int $userId): ?array

    {

        $thisMonth = $this->monthExpenseTotal($userId, 0);

        $lastMonth = $this->monthExpenseTotal($userId, 1);

        if (Decimal::cmp($thisMonth, Decimal::zero()) === 0 && Decimal::cmp($lastMonth, Decimal::zero()) === 0) {

            return null;

        }

        $delta = Decimal::sub($thisMonth, $lastMonth);

        $period = date('Y-m');

        $pct = Decimal::cmp($lastMonth, Decimal::zero()) > 0

            ? round((Decimal::toCents($delta) / Decimal::toCents($lastMonth)) * 100, 1)

            : null;

        $kind = Decimal::cmp($delta, Decimal::zero()) > 0 ? 'warning' : 'positive';

        return [

            'id' => 'expense_mom_total',

            'title' => 'Monthly spending vs last month',

            'body' => $pct !== null

                ? sprintf('You spent %s this month (%s%% vs last month).', $thisMonth, ($pct >= 0 ? '+' : '') . $pct)

                : sprintf('You spent %s this month.', $thisMonth),

            'kind' => $kind,

            'period' => $period,

            'evidence' => [

                'metrics' => [

                    'this_month' => $thisMonth,

                    'last_month' => $lastMonth,

                    'delta' => $delta,

                    'delta_pct' => $pct,

                ],

                'transaction_ids' => [],

            ],

        ];

    }



    private function momCategoryDelta(int $userId): ?array

    {

        $cur = $this->categoryTotalsForMonth($userId, 0);

        $prev = $this->categoryTotalsForMonth($userId, 1);

        if ($cur === [] && $prev === []) {

            return null;

        }

        $bestCat = null;

        $bestDelta = Decimal::zero();

        foreach ($cur as $cat => $total) {

            $was = $prev[$cat] ?? Decimal::zero();

            $delta = Decimal::sub($total, $was);

            if ($bestCat === null || Decimal::cmp(abs(Decimal::toCents($delta)), abs(Decimal::toCents($bestDelta))) > 0) {

                $bestCat = $cat;

                $bestDelta = $delta;

            }

        }

        if ($bestCat === null) {

            return null;

        }

        return [

            'id' => 'mom_category_delta_' . preg_replace('/\W+/', '_', mb_strtolower($bestCat)),

            'title' => 'Biggest category change',

            'body' => sprintf(

                '%s changed by %s compared to last month.',

                $bestCat,

                (Decimal::cmp($bestDelta, Decimal::zero()) >= 0 ? '+' : '') . $bestDelta

            ),

            'kind' => Decimal::cmp($bestDelta, Decimal::zero()) > 0 ? 'warning' : 'info',

            'period' => date('Y-m'),

            'evidence' => [

                'metrics' => [

                    'category' => $bestCat,

                    'delta' => $bestDelta,

                    'this_month' => $cur[$bestCat] ?? Decimal::zero(),

                    'last_month' => $prev[$bestCat] ?? Decimal::zero(),

                ],

                'transaction_ids' => $this->txnIdsForCategoryMonth($userId, $bestCat, 0),

            ],

        ];

    }



    private function categoryShare(int $userId): ?array

    {

        $from = date('Y-m-01');

        $stmt = Database::pdo()->prepare(

            "SELECT COALESCE(c.name, 'Other') AS category, SUM(t.amount) AS total, GROUP_CONCAT(t.id) AS ids

             FROM transactions t

             LEFT JOIN categories c ON c.id = t.category_id

             WHERE t.user_id = ? AND t.type = 'expense' AND t.txn_date >= ?

             GROUP BY category

             ORDER BY total + 0 DESC

             LIMIT 1"

        );

        $stmt->execute([$userId, $from]);

        $top = $stmt->fetch();

        if (!$top) {

            return null;

        }

        $topTotal = Decimal::normalize((string) $top['total']);

        $all = $this->monthExpenseTotal($userId, 0);

        if (Decimal::cmp($all, Decimal::zero()) === 0) {

            return null;

        }

        $share = round((Decimal::toCents($topTotal) / Decimal::toCents($all)) * 100, 1);

        $ids = array_map('intval', array_filter(explode(',', (string) ($top['ids'] ?? ''))));

        return [

            'id' => 'category_share_top',

            'title' => 'Top spending category',

            'body' => sprintf('%s is %.1f%% of your expenses this month (%s).', $top['category'], $share, $topTotal),

            'kind' => 'info',

            'period' => date('Y-m'),

            'evidence' => [

                'metrics' => [

                    'category' => $top['category'],

                    'share_pct' => $share,

                    'category_total' => $topTotal,

                    'month_total' => $all,

                ],

                'transaction_ids' => array_slice($ids, 0, 50),

            ],

        ];

    }



    private function recurringMonthlyTotal(int $userId): ?array

    {

        $total = Decimal::zero();

        $count = 0;

        foreach ($this->recurring->listForUser($userId) as $r) {

            if (!(int) ($r['is_active'] ?? 1) || ($r['type'] ?? '') !== 'expense') {

                continue;

            }

            $amt = Decimal::normalize((string) $r['amount']);

            $monthly = match ($r['frequency'] ?? 'monthly') {

                'weekly' => Decimal::fromCents((int) round(Decimal::toCents($amt) * 52 / 12)),

                'yearly' => Decimal::fromCents((int) round(Decimal::toCents($amt) / 12)),

                default => $amt,

            };

            $total = Decimal::add($total, $monthly);

            $count++;

        }

        if ($count === 0) {

            return null;

        }

        return [

            'id' => 'recurring_monthly_total',

            'title' => 'Recurring commitments',

            'body' => sprintf('Active recurring expenses total about %s per month (%d items).', $total, $count),

            'kind' => 'info',

            'period' => date('Y-m'),

            'evidence' => [

                'metrics' => [

                    'monthly_total' => $total,

                    'active_count' => $count,

                ],

                'transaction_ids' => [],

            ],

        ];

    }



    private function largestExpense(int $userId): ?array

    {

        $from = date('Y-m-01');

        $rows = $this->analytics->largestExpenses($userId, 1, $from);

        if ($rows === []) {

            return null;

        }

        $r = $rows[0];

        return [

            'id' => 'largest_expense_month',

            'title' => 'Largest expense this month',

            'body' => sprintf('%s for %s on %s.', $r['amount'], $r['merchant'], $r['txn_date']),

            'kind' => 'info',

            'period' => date('Y-m'),

            'evidence' => [

                'metrics' => [

                    'amount' => Decimal::normalize((string) $r['amount']),

                    'merchant' => $r['merchant'],

                    'txn_date' => $r['txn_date'],

                ],

                'transaction_ids' => [(int) $r['id']],

            ],

        ];

    }



    private function monthExpenseTotal(int $userId, int $monthsAgo): string

    {

        $dt = (new \DateTimeImmutable('first day of this month'))->modify("-{$monthsAgo} months");

        $from = $dt->format('Y-m-d');

        $to = $dt->format('Y-m-t');

        $stmt = Database::pdo()->prepare(

            "SELECT amount FROM transactions WHERE user_id = ? AND type = 'expense' AND txn_date >= ? AND txn_date <= ?"

        );

        $stmt->execute([$userId, $from, $to]);

        $sum = Decimal::zero();

        foreach ($stmt->fetchAll() as $row) {

            $sum = Decimal::add($sum, (string) $row['amount']);

        }

        return $sum;

    }



    /** @return array<string, string> */

    private function categoryTotalsForMonth(int $userId, int $monthsAgo): array

    {

        $dt = (new \DateTimeImmutable('first day of this month'))->modify("-{$monthsAgo} months");

        $from = $dt->format('Y-m-d');

        $to = $dt->format('Y-m-t');

        $stmt = Database::pdo()->prepare(

            "SELECT COALESCE(c.name, 'Other') AS category, SUM(t.amount) AS total

             FROM transactions t

             LEFT JOIN categories c ON c.id = t.category_id

             WHERE t.user_id = ? AND t.type = 'expense' AND t.txn_date >= ? AND t.txn_date <= ?

             GROUP BY category"

        );

        $stmt->execute([$userId, $from, $to]);

        $out = [];

        foreach ($stmt->fetchAll() as $row) {

            $out[$row['category']] = Decimal::normalize((string) $row['total']);

        }

        return $out;

    }



    /** @return list<int> */

    private function txnIdsForCategoryMonth(int $userId, string $category, int $monthsAgo): array

    {

        $dt = (new \DateTimeImmutable('first day of this month'))->modify("-{$monthsAgo} months");

        $from = $dt->format('Y-m-d');

        $to = $dt->format('Y-m-t');

        $stmt = Database::pdo()->prepare(

            "SELECT t.id FROM transactions t

             LEFT JOIN categories c ON c.id = t.category_id

             WHERE t.user_id = ? AND t.type = 'expense' AND t.txn_date >= ? AND t.txn_date <= ?

               AND COALESCE(c.name, 'Other') = ?

             ORDER BY t.amount + 0 DESC

             LIMIT 20"

        );

        $stmt->execute([$userId, $from, $to, $category]);

        return array_map(static fn ($r) => (int) $r['id'], $stmt->fetchAll());

    }

}

