<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Repositories\RecurringRepository;

final class RecurringService
{
    public function __construct(
        private RecurringRepository $recurring = new RecurringRepository(),
        private TransactionService $transactions = new TransactionService(),
    ) {}

    /**
     * Post any due recurring items as real transactions. Safe on every app load.
     */
    public function materializeDue(int $userId, string $userCurrency): int
    {
        $due = $this->recurring->dueForUser($userId);
        $created = 0;
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($due as $row) {
            $date = $row['next_date'];
            $guard = 0;
            while ($date <= $today && $guard < 36) {
                $clientId = $this->stableUuid(sprintf('recurring-%d-%s', (int) $row['id'], $date));
                $this->transactions->create($userId, [
                    'client_id' => $clientId,
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'merchant' => $row['name'],
                    'category_id' => $row['category_id'],
                    'account_id' => $row['account_id'],
                    'txn_date' => $date,
                    'notes' => 'Auto from recurring',
                ], $userCurrency);
                $date = $this->advanceDate($date, $row['frequency']);
                $created++;
                $guard++;
            }
            $this->recurring->markRun((int) $row['id'], $userId, $date);
        }

        return $created;
    }

    private function advanceDate(string $date, string $frequency): string
    {
        $d = new \DateTimeImmutable($date);
        return match ($frequency) {
            'weekly' => $d->modify('+1 week')->format('Y-m-d'),
            'yearly' => $d->modify('+1 year')->format('Y-m-d'),
            default => $d->modify('+1 month')->format('Y-m-d'),
        };
    }

    private function stableUuid(string $seed): string
    {
        $h = md5('nova-recurring:' . $seed);
        return sprintf(
            '%08s-%04s-4%03s-a%03s-%012s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 13, 3),
            substr($h, 17, 3),
            substr($h, 20, 12)
        );
    }
}
