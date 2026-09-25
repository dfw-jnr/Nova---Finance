<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Helpers\Decimal;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\BudgetRepository;
use Nova\Repositories\RecurringRepository;
use Nova\Repositories\SettingsRepository;

/**
 * Traceable Safe-to-Spend: base-currency balances minus known reservations.
 */
final class SafeToSpendService
{
    public function __construct(
        private AccountRepository $accounts = new AccountRepository(),
        private RecurringRepository $recurring = new RecurringRepository(),
        private BudgetRepository $budgets = new BudgetRepository(),
        private SettingsRepository $settings = new SettingsRepository(),
    ) {}

    public function calculate(int $userId, string $baseCurrency): array
    {
        $baseCurrency = strtoupper($baseCurrency);
        $lines = [];
        $available = Decimal::zero();
        $otherCurrencies = [];

        foreach ($this->accounts->listForUser($userId) as $a) {
            $cur = strtoupper((string) $a['currency']);
            $bal = Decimal::normalize((string) $a['balance']);
            if ($cur === $baseCurrency) {
                $available = Decimal::add($available, $bal);
                $lines[] = [
                    'source' => 'account',
                    'label' => 'Balance: ' . $a['name'],
                    'amount' => $bal,
                    'sign' => 'credit',
                    'ref_id' => (int) $a['id'],
                ];
            } else {
                $otherCurrencies[] = [
                    'account' => $a['name'],
                    'currency' => $cur,
                    'balance' => $bal,
                ];
            }
        }

        $reserved = Decimal::zero();
        $horizon = $this->defaultHorizon();

        // Upcoming recurring expenses until horizon
        foreach ($this->recurring->listForUser($userId) as $r) {
            if (!(int) ($r['is_active'] ?? 1)) {
                continue;
            }
            if (($r['type'] ?? 'expense') !== 'expense') {
                continue;
            }
            $next = (string) $r['next_date'];
            if ($next > $horizon) {
                continue;
            }
            // Sum occurrences until horizon (cap 6)
            $amt = Decimal::normalize((string) $r['amount']);
            $date = $next;
            $guard = 0;
            $total = Decimal::zero();
            while ($date <= $horizon && $guard < 6) {
                $total = Decimal::add($total, $amt);
                $date = $this->advance($date, (string) $r['frequency']);
                $guard++;
            }
            if (Decimal::cmp($total, Decimal::zero()) > 0) {
                $reserved = Decimal::add($reserved, $total);
                $lines[] = [
                    'source' => 'recurring',
                    'label' => 'Upcoming: ' . $r['name'],
                    'amount' => Decimal::negate($total),
                    'sign' => 'debit',
                    'ref_id' => (int) $r['id'],
                    'detail' => 'Through ' . $horizon,
                ];
            }
        }

        // Remaining budget room this month (reserved for planned spend)
        foreach ($this->budgets->listForCurrentMonth($userId) as $b) {
            $limit = Decimal::normalize((string) ($b['limit_amount'] ?? '0'));
            $spent = Decimal::normalize((string) ($b['spent'] ?? '0'));
            $remain = Decimal::sub($limit, $spent);
            if (Decimal::cmp($remain, Decimal::zero()) > 0) {
                $reserved = Decimal::add($reserved, $remain);
                $lines[] = [
                    'source' => 'budget',
                    'label' => 'Budget remaining: ' . ($b['category_name'] ?? $b['name']),
                    'amount' => Decimal::negate($remain),
                    'sign' => 'debit',
                    'ref_id' => (int) ($b['bc_id'] ?? $b['id']),
                ];
            }
        }

        $minBalance = Decimal::normalize(
            (string) ($this->settings->get($userId, 'minimum_balance') ?? '0')
        );
        if (Decimal::cmp($minBalance, Decimal::zero()) > 0) {
            $reserved = Decimal::add($reserved, $minBalance);
            $lines[] = [
                'source' => 'setting',
                'label' => 'Minimum balance buffer',
                'amount' => Decimal::negate($minBalance),
                'sign' => 'debit',
                'ref_id' => null,
            ];
        }

        $goalAllot = Decimal::normalize(
            (string) ($this->settings->get($userId, 'goal_monthly_allotment') ?? '0')
        );
        if (Decimal::cmp($goalAllot, Decimal::zero()) > 0) {
            $reserved = Decimal::add($reserved, $goalAllot);
            $lines[] = [
                'source' => 'goal',
                'label' => 'Planned savings this period',
                'amount' => Decimal::negate($goalAllot),
                'sign' => 'debit',
                'ref_id' => null,
            ];
        }

        $safe = Decimal::sub($available, $reserved);
        if (Decimal::cmp($safe, Decimal::zero()) < 0) {
            $safe = Decimal::zero();
        }

        $notes = [];
        if ($otherCurrencies !== []) {
            $notes[] = 'Other-currency balances are listed separately and are not converted (no FX rates).';
        }
        $hasRecurringDebit = false;
        foreach ($lines as $ln) {
            if (($ln['source'] ?? '') === 'recurring') {
                $hasRecurringDebit = true;
                break;
            }
        }
        if (!$hasRecurringDebit) {
            $notes[] = 'Add recurring bills to refine Safe-to-Spend.';
        }

        return [
            'currency' => $baseCurrency,
            'available' => $available,
            'reserved' => $reserved,
            'safe_to_spend' => $safe,
            'horizon' => $horizon,
            'lines' => $lines,
            'other_currencies' => $otherCurrencies,
            'notes' => $notes,
        ];
    }

    private function defaultHorizon(): string
    {
        // End of next month
        return (new \DateTimeImmutable('first day of next month'))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }

    private function advance(string $date, string $frequency): string
    {
        $d = new \DateTimeImmutable($date);
        return match ($frequency) {
            'weekly' => $d->modify('+1 week')->format('Y-m-d'),
            'yearly' => $d->modify('+1 year')->format('Y-m-d'),
            default => $d->modify('+1 month')->format('Y-m-d'),
        };
    }
}
