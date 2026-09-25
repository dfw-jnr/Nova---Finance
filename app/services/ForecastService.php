<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Helpers\Decimal;
use Nova\Repositories\AccountRepository;
use Nova\Repositories\RecurringRepository;

/**
 * Cash-flow forecast from current base-currency balances + scheduled recurring only.
 *
 * Existing transactions already moved account balances at create time — re-applying
 * future-dated rows would double-count. Transfers between base accounts net to zero
 * in available totals and are not projected here.
 */
final class ForecastService
{
    public function __construct(
        private AccountRepository $accounts = new AccountRepository(),
        private RecurringRepository $recurring = new RecurringRepository(),
    ) {}

    public function forecast(int $userId, string $baseCurrency, int $horizonDays): array
    {
        $horizonDays = max(7, min(365, $horizonDays));
        $baseCurrency = strtoupper($baseCurrency);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($today))->modify("+{$horizonDays} days")->format('Y-m-d');

        $balance = Decimal::zero();
        $baseAccountIds = [];
        $otherCurrencies = [];

        foreach ($this->accounts->listForUser($userId) as $a) {
            $cur = strtoupper((string) $a['currency']);
            if ($cur === $baseCurrency) {
                $balance = Decimal::add($balance, (string) $a['balance']);
                $baseAccountIds[(int) $a['id']] = true;
            } else {
                $otherCurrencies[] = [
                    'account' => $a['name'],
                    'currency' => $cur,
                    'balance' => Decimal::normalize((string) $a['balance']),
                ];
            }
        }

        /** @var array<string, string> date => signed delta */
        $deltas = [];
        $recurringCount = 0;

        foreach ($this->recurring->listForUser($userId) as $r) {
            if (!(int) ($r['is_active'] ?? 1)) {
                continue;
            }
            if (!isset($baseAccountIds[(int) $r['account_id']])) {
                continue;
            }
            $amt = Decimal::normalize((string) $r['amount']);
            $signed = ($r['type'] ?? 'expense') === 'income' ? $amt : Decimal::negate($amt);
            $date = (string) $r['next_date'];
            $guard = 0;
            while ($date < $today && $guard < 500) {
                $date = $this->advance($date, (string) $r['frequency']);
                $guard++;
            }
            while ($date <= $end && $guard < 500) {
                if ($date >= $today) {
                    $deltas[$date] = Decimal::add($deltas[$date] ?? Decimal::zero(), $signed);
                    $recurringCount++;
                }
                $date = $this->advance($date, (string) $r['frequency']);
                $guard++;
            }
        }

        $points = [];
        $cursor = new \DateTimeImmutable($today);
        $endDt = new \DateTimeImmutable($end);
        $running = $balance;
        $dayIndex = 0;
        $minBalance = $balance;
        $wentNegative = false;

        while ($cursor <= $endDt) {
            $d = $cursor->format('Y-m-d');
            $kind = 'actual';
            if (isset($deltas[$d])) {
                $running = Decimal::add($running, $deltas[$d]);
                $kind = 'projected';
            } elseif ($dayIndex > 0) {
                $kind = 'projected';
            }
            if (Decimal::cmp($running, $minBalance) < 0) {
                $minBalance = $running;
            }
            if (Decimal::cmp($running, Decimal::zero()) < 0) {
                $wentNegative = true;
            }

            $sample = $dayIndex === 0
                || $dayIndex === $horizonDays
                || ($dayIndex % 7 === 0)
                || $cursor == $endDt;

            if ($sample) {
                $points[] = [
                    'date' => $d,
                    'balance' => $running,
                    'kind' => $kind === 'actual' && $dayIndex === 0 ? 'actual' : 'projected',
                    'source' => isset($deltas[$d]) ? 'recurring' : ($dayIndex === 0 ? 'balance' : 'carry'),
                ];
            }

            $cursor = $cursor->modify('+1 day');
            $dayIndex++;
        }

        $last = $points[count($points) - 1] ?? [
            'date' => $today,
            'balance' => $balance,
            'kind' => 'actual',
            'source' => 'balance',
        ];

        $disclaimer = $recurringCount === 0
            ? 'No active recurring items on base-currency accounts — ending balance equals today’s balance (estimate only).'
            : 'Estimate based on recurring items only. Not a confirmed balance. Transfers and one-off spend are not projected.';

        return [
            'currency' => $baseCurrency,
            'horizon_days' => $horizonDays,
            'start_date' => $today,
            'end_date' => $end,
            'starting_balance' => $balance,
            'ending_balance' => $last['balance'],
            'ending_kind' => 'projected',
            'min_projected_balance' => $minBalance,
            'may_go_negative' => $wentNegative,
            'recurring_events' => $recurringCount,
            'points' => $points,
            'other_currencies' => $otherCurrencies,
            'disclaimer' => $disclaimer,
        ];
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
