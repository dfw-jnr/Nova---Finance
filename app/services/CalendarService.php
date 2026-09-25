<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Repositories\RecurringRepository;
use Nova\Repositories\TransactionRepository;

final class CalendarService
{
    public function __construct(
        private TransactionRepository $txns = new TransactionRepository(),
        private RecurringRepository $recurring = new RecurringRepository(),
    ) {}

    public function month(int $userId, int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 1970 || $year > 2100) {
            $year = (int) date('Y');
        }

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));

        $days = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $days[$cursor->format('Y-m-d')] = ['actual' => [], 'projected' => []];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($this->txns->list($userId, ['from' => $from, 'to' => $to, 'sort' => 'date_asc', 'limit' => 10000]) as $row) {
            $d = (string) $row['txn_date'];
            if (!isset($days[$d])) {
                continue;
            }
            $days[$d]['actual'][] = $row;
        }

        foreach ($this->recurring->listForUser($userId) as $r) {
            if (!(int) ($r['is_active'] ?? 1)) {
                continue;
            }
            foreach ($this->occurrencesInRange($r, $from, $to) as $occDate) {
                if (!isset($days[$occDate])) {
                    continue;
                }
                $days[$occDate]['projected'][] = [
                    'kind' => 'recurring',
                    'recurring_id' => (int) $r['id'],
                    'name' => $r['name'],
                    'merchant' => $r['name'],
                    'amount' => $r['amount'],
                    'currency' => $r['currency'],
                    'type' => $r['type'],
                    'frequency' => $r['frequency'],
                    'account_id' => (int) $r['account_id'],
                    'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                    'txn_date' => $occDate,
                ];
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'days' => $days,
        ];
    }

    /** @return list<string> Y-m-d dates */
    private function occurrencesInRange(array $recurring, string $from, string $to): array
    {
        $dates = [];
        $next = (string) $recurring['next_date'];
        $frequency = (string) $recurring['frequency'];
        $guard = 0;

        while ($next < $from && $guard < 500) {
            $next = $this->advance($next, $frequency);
            $guard++;
        }

        while ($next <= $to && $guard < 500) {
            if ($next >= $from) {
                $dates[] = $next;
            }
            $next = $this->advance($next, $frequency);
            $guard++;
        }

        return $dates;
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
