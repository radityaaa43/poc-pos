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
     * The per-customer mutex of credit checkouts (a new receivable has no row of its own to lock yet). Call inside the
     * caller's DB::transaction before its first plain SELECT: MySQL REPEATABLE READ fixes the snapshot there, and only a
     * snapshot taken under this lock sees every receivable committed by the checkout that held it before.
     */
    public function lock(int $customerId): Customer
    {
        return Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
    }

    /** For a credit decision, under {@see lock()}. */
    public function lockedStanding(int $customerId): CreditStanding
    {
        return $this->read($this->lock($customerId));
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
            $standing->used + $total > $standing->limit => CreditRefusedException::limitExceeded($standing->remaining(), $total),
            $standing->hasOverdue => CreditRefusedException::overdue($standing->remaining(), $total),
            default => null,
        };

        if ($refusal) {
            // Ids and amounts only: no customer name in the log.
            Log::info('credit.refused', ['customer_id' => $customerId, 'code' => $refusal->reason, 'total' => $total, 'remaining' => $refusal->remaining]);

            throw $refusal;
        }
    }

    private function read(Customer $customer): CreditStanding
    {
        // A plain read on purpose: a locking one takes gap locks that deadlock credit sales of different customers.
        $row = Receivable::query()->outstanding()
            ->where('customer_id', $customer->id)
            ->selectRaw(
                'COALESCE(SUM(total - paid), 0) as used, COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) as overdue_count',
                [today()->toDateString()]
            )
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
