<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReceivableService
{
    public function getAgingSummary(?Collection $warehouseIds = null): Collection
    {
        $receivables = $this->scope(Receivable::query(), $warehouseIds)
            ->where('status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->get();

        $buckets = ['current', '0-30', '31-60', '61-90', '90+'];

        return collect($buckets)->map(function ($bucket) use ($receivables) {
            $filtered = $receivables->filter(fn ($r) => $r->aging_bucket === $bucket);

            return [
                'bucket' => $bucket,
                'count' => $filtered->count(),
                'total' => $filtered->sum('total'),
                'paid' => $filtered->sum('paid'),
                'remaining' => $filtered->sum(fn ($r) => max(0, $r->total - $r->paid)),
            ];
        });
    }

    public function scopeQuery($query, Collection $warehouseIds)
    {
        return $this->scope($query, $warehouseIds);
    }

    /**
     * REQ-007 "Piutang macet": the overdue total at the end of each of the last 12 months (oldest first), measured
     * as it stood then — a receivable counts iff its due date had passed by month end, it existed, and its remaining
     * then (total − paid + payments dated after the month end) was above 0. Today is the as-of for the current month.
     *
     * @return list<array{month: string, as_of: string, overdue: int, count: int}>
     */
    public function overdueTrend(?Collection $warehouseIds = null): array
    {
        $now = CarbonImmutable::now();
        $todayEnd = $now->endOfDay()->toDateString();
        $trend = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $now->startOfMonth()->subMonths($i);
            $asOf = $i === 0 ? $todayEnd : $month->endOfMonth()->toDateString();

            $remaining = '(total - paid + coalesce((select sum(amount) from receivable_payments p '
                .'where p.receivable_id = receivables.id and p.paid_at > ? and p.paid_at <= ?), 0))';

            $row = $this->scope(Receivable::query(), $warehouseIds)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $asOf)
                ->where('created_at', '<=', $asOf.' 23:59:59')
                ->whereRaw($remaining.' > 0', [$asOf, $todayEnd])
                ->selectRaw("count(*) as aggregate_count, coalesce(sum({$remaining}), 0) as overdue", [$asOf, $todayEnd])
                ->first();

            $trend[] = [
                'month' => $month->format('Y-m'),
                'as_of' => $asOf,
                'overdue' => (int) round((float) ($row->overdue ?? 0)),
                'count' => (int) ($row->aggregate_count ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * What each of these customers still owes, in whole rupiah: [customer_id => used]. Deliberately not
     * outlet-scoped, because a credit limit counts every outlet's receivables.
     */
    public function usedByCustomer(Collection $customerIds): Collection
    {
        return Receivable::query()->outstanding()
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, SUM(total - paid) as used')
            ->groupBy('customer_id')
            ->toBase()
            ->pluck('used', 'customer_id')
            ->map(fn ($used) => (int) round((float) $used));
    }

    /**
     * The list's Batas and Terpakai % of one customer. The percentage is rounded down and the state compares the
     * same integers as scopeCredit(), so a row reads 80% only when the "near" filter lists it.
     *
     * @return array{limit: ?int, used: int, percent: ?int, state: string}
     */
    public function creditView(?int $limit, int $used): array
    {
        return [
            'limit' => $limit,
            'used' => $used,
            'percent' => $limit > 0 ? intdiv($used * 100, $limit) : null,
            'state' => match (true) {
                $limit === null => 'none',
                $used > $limit => 'over',
                $used > 0 && $used * 100 >= $limit * 80 => 'near',
                default => 'ok',
            },
        ];
    }

    /**
     * Narrows a receivables query to the customers in one credit state: `near` (80% or more, over included),
     * `over`, or `none` (may buy on credit, no limit). Null changes nothing.
     */
    public function scopeCredit($query, ?string $filter)
    {
        if ($filter === null) {
            return $query;
        }

        if ($filter === 'none') {
            $customers = Customer::query()->select('id')->where('may_buy_on_credit', true)->whereNull('credit_limit');
        } else {
            // shortcut: groups every open receivable of the shop on each filtered load; past about 500k open
            // rows, keep the usage in a maintained column.
            $usage = Receivable::query()->outstanding()
                // Rounded like usedByCustomer(), so a filter never disagrees with a row over half a rupiah.
                ->selectRaw('customer_id, ROUND(SUM(total - paid)) as used')
                ->groupBy('customer_id');
            $customers = Customer::query()->select('customers.id')
                ->joinSub($usage, 'u', 'u.customer_id', '=', 'customers.id')
                ->whereNotNull('customers.credit_limit');

            $filter === 'over'
                ? $customers->whereRaw('u.used > customers.credit_limit')
                : $customers->where('u.used', '>', 0)->whereRaw('u.used * 100 >= customers.credit_limit * 80');
        }

        return $query->whereIn('receivables.customer_id', $customers);
    }

    public function getCustomerStatement(int $customerId, ?Collection $warehouseIds = null): array
    {
        $customer = Customer::findOrFail($customerId);

        $receivables = $this->scope(Receivable::where('customer_id', $customerId), $warehouseIds)
            ->withSum('payments as total_paid', 'amount')
            ->orderBy('due_date')
            ->get();

        $receivables->transform(function ($item) {
            $item->aging_bucket = $item->aging_bucket;

            return $item;
        });

        $totalOutstanding = $receivables
            ->where('status', '!=', 'paid')
            ->sum(fn ($r) => max(0, $r->total - $r->total_paid));

        $totalPaid = $receivables->sum('total_paid');

        return [
            'customer' => $customer,
            'receivables' => $receivables,
            'total_outstanding' => $totalOutstanding,
            'total_paid' => $totalPaid,
        ];
    }

    public function getCollectionRate(?Collection $warehouseIds = null): array
    {
        $query = $this->scope(Receivable::query(), $warehouseIds);
        $totalReceivables = (clone $query)->sum('total');
        $totalPaid = (clone $query)->sum('paid');

        $paidReceivables = (clone $query)->where('status', 'paid')->count();
        $totalReceivablesCount = (clone $query)->count();

        return [
            'total_receivables_amount' => $totalReceivables,
            'total_paid_amount' => $totalPaid,
            'collection_rate' => $totalReceivables > 0
                ? round(($totalPaid / $totalReceivables) * 100, 2)
                : 0,
            'paid_count' => $paidReceivables,
            'total_count' => $totalReceivablesCount,
        ];
    }

    public function getTopCustomersByReceivable(int $limit = 10, ?Collection $warehouseIds = null): Collection
    {
        $scope = fn ($query) => $this->scope($query, $warehouseIds);

        return Customer::withSum([
            'receivables as total_receivable' => fn ($q) => $scope($q)->where('status', '!=', 'paid'),
        ], 'total')
            ->withSum([
                'receivables as total_paid' => fn ($q) => $scope($q),
            ], 'paid')
            ->orderByRaw('COALESCE(total_receivable, 0) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'total_receivable' => $c->total_receivable ?? 0,
                'total_paid' => $c->total_paid ?? 0,
                'remaining' => max(0, ($c->total_receivable ?? 0) - ($c->total_paid ?? 0)),
            ])
            ->filter(fn ($c) => $c['remaining'] > 0)
            ->values();
    }

    private function scope($query, ?Collection $warehouseIds)
    {
        if ($warehouseIds === null) {
            return $query;
        }

        return $query->where(function ($builder) use ($warehouseIds) {
            $builder->whereHas('transaction', fn ($transaction) => $transaction->whereIn('warehouse_id', $warehouseIds));

            if ($warehouseIds->isEmpty()) {
                $builder->whereRaw('1 = 0');
            } elseif ($this->singleOutlet()) {
                $builder->orWhereDoesntHave('transaction');
            }
        });
    }

    private function singleOutlet(): bool
    {
        return Outlet::active()->count() <= 1;
    }
}
