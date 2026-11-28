<?php

namespace App\Services;

use App\Exceptions\CreditRefusedException;
use App\Models\Customer;
use App\Models\Receivable;
use App\Support\Credit\CreditStanding;
use Illuminate\Support\Facades\Log;

/**
 * A customer's credit limit against what they still owe across all outlets. Computed on read, never stored.
 */
class CustomerCreditService
{
    /** For display: takes no lock. */
    public function standing(Customer $customer): CreditStanding
    {
        return $this->read($customer);
    }

    /**
     * For a credit decision. Call inside the caller's DB::transaction: the customer row lock lets one
     * checkout per customer through at a time (a new receivable has no row of its own to lock yet).
     */
    public function lockedStanding(int $customerId): CreditStanding
    {
        return $this->read(Customer::whereKey($customerId)->lockForUpdate()->firstOrFail(), locked: true);
    }

    /**
     * Refuses a credit sale of `$total` over the limit, then one made while a receivable is overdue. A customer
     * without a limit is never refused. Call inside the DB::transaction that creates the receivable.
     *
     * @throws CreditRefusedException
     */
    public function guard(int $customerId, int $total): void
    {
        $standing = $this->lockedStanding($customerId);

        $refusal = match (true) {
            $standing->limit === null => null,
            $standing->used + $total > $standing->limit => CreditRefusedException::limitExceeded($standing->remaining()),
            $standing->hasOverdue => CreditRefusedException::overdue($standing->remaining()),
            default => null,
        };

        if ($refusal) {
            // Ids and amounts only: no customer name in the log.
            Log::info('credit.refused', ['customer_id' => $customerId, 'code' => $refusal->reason, 'total' => $total, 'remaining' => $refusal->remaining]);

            throw $refusal;
        }
    }

    private function read(Customer $customer, bool $locked = false): CreditStanding
    {
        // The locked path needs a locking read: a plain one sees the transaction's first snapshot (MySQL
        // REPEATABLE READ) and would miss a receivable committed while we waited for the customer row.
        $row = Receivable::query()->outstanding()
            ->where('customer_id', $customer->id)
            ->selectRaw(
                'COALESCE(SUM(total - paid), 0) as used, COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) as overdue_count',
                [today()->toDateString()]
            )
            ->when($locked, fn ($query) => $query->sharedLock())
            ->toBase()
            ->first();

        return new CreditStanding(
            mayBuyOnCredit: (bool) $customer->may_buy_on_credit,
            limit: $customer->credit_limit,
            used: (int) round((float) $row->used),
            hasOverdue: (int) $row->overdue_count > 0,
        );
    }
}
