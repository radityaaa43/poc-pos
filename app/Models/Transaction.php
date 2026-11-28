<?php

namespace App\Models;

use App\Services\KitchenIntakeService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use HasFactory;

    // Neutral payment_method of an accepted dine-in bill until the cashier settles it.
    public const METHOD_OPEN_BILL = 'open_bill';

    protected static function booted(): void
    {
        // Whichever way a bill becomes paid (cash, a confirmed transfer, ...) may be what completes a served dine-in order.
        static::updated(function (Transaction $transaction) {
            if ($transaction->wasChanged('payment_status') && $transaction->payment_status === 'paid') {
                $transaction->dineOrder?->completeIfSettled();
                // Every paid-by-update path (confirmed transfer, webhooks, split, dine-in pay) reaches the kitchen here.
                // Not a pay-later sale (marked by the flag, since a later settle can change payment_method): its items are
                // recorded at checkout, so paying the receivable later (including a pre-release one) adds nothing. Money first: a kitchen-intake failure is reported, never allowed to undo a payment.
                if (! $transaction->is_pay_later) {
                    DB::afterCommit(fn () => rescue(fn () => app(KitchenIntakeService::class)->intake($transaction)));
                }
            }
        });

        static::creating(function ($transaction) {
            $transaction->access_token = (string) Str::uuid();
        });
    }

    protected $fillable = [
        'cashier_id',
        'cashier_shift_id',
        'warehouse_id',
        'customer_id',
        'invoice',
        'client_uuid',
        'sync_fingerprint',
        'cash',
        'change',
        'discount',
        'loyalty_points_earned',
        'loyalty_points_redeemed',
        'loyalty_discount_total',
        'customer_voucher_discount',
        'customer_voucher_code',
        'customer_voucher_name',
        'shipping_cost',
        'grand_total',
        'payment_method',
        'payment_status',
        'payment_attempt',
        'payment_failure_reason',
        'late_superseded_payment_at',
        'paid_after_close_at',
        'overpaid_amount',
        'is_pay_later',
        'payment_reference',
        'payment_url',
        'qr_string',
        'order_type',
        'note',
        'bank_account_id',
        'tax_rate',
        'tax_total',
        'service_charge',
        'tax_kind',
        'tax_base',
        'customer_npwp',
        'price_list_id',
        'discount_approved_by',
        'discount_approved_at',
        'discount_approval_status',
        'access_token',
    ];

    protected $casts = [
        'is_pay_later' => 'boolean',
        'cashier_id' => 'integer',
        'cashier_shift_id' => 'integer',
        'customer_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'cash' => 'integer',
        'change' => 'integer',
        'discount' => 'integer',
        'loyalty_points_earned' => 'integer',
        'loyalty_points_redeemed' => 'integer',
        'loyalty_discount_total' => 'integer',
        'customer_voucher_discount' => 'integer',
        'shipping_cost' => 'integer',
        'grand_total' => 'integer',
        'bank_account_id' => 'integer',
        'tax_rate' => 'decimal:2',
        'tax_total' => 'integer',
        'service_charge' => 'integer',
        'tax_base' => 'integer',
        'discount_approved_at' => 'datetime',
        'payment_attempt' => 'integer',
        'late_superseded_payment_at' => 'datetime',
        'paid_after_close_at' => 'datetime',
        'overpaid_amount' => 'integer',
    ];

    /** @return HasMany<TransactionDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(TransactionDetail::class);
    }

    /**
     * customer
     *
     * @return void
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * cashier
     *
     * @return void
     */
    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * bankAccount
     *
     * @return void
     */
    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<Profit, $this> */
    public function profits(): HasMany
    {
        return $this->hasMany(Profit::class);
    }

    public function receivable()
    {
        return $this->hasOne(Receivable::class);
    }

    /** @return HasOne<DineOrder, $this> */
    public function dineOrder(): HasOne
    {
        return $this->hasOne(DineOrder::class);
    }

    /** @return HasMany<KitchenItem, $this> */
    public function kitchenItems(): HasMany
    {
        return $this->hasMany(KitchenItem::class);
    }

    /** Statuses of an open bill the cashier may still pay: nothing paid, or a gateway attempt that failed or expired. */
    public const OPEN_BILL_PAYABLE = ['unpaid', 'failed', 'expired'];

    /** Unsettled bills a cashier can take payment for: accepted dine-in orders, or guest pay-later sales. Not a credit sale: no receivable. */
    public function scopeOpenBills($query)
    {
        return $query->where(fn ($bills) => $bills
            ->where(fn ($dine) => $dine->whereIn('payment_status', [...self::OPEN_BILL_PAYABLE, 'pending'])
                ->whereHas('dineOrder', fn ($order) => $order->whereIn('status', DineOrder::IN_PROGRESS)))
            ->orWhere(fn ($posBills) => $posBills->payLaterBills()));
    }

    /** POS pay-later sales left unpaid (a pending gateway attempt included): no receivable, no dine order, not fully returned. */
    public function scopePayLaterBills($query)
    {
        return $query->where('is_pay_later', true)
            ->whereIn('payment_status', [...self::OPEN_BILL_PAYABLE, 'pending'])
            ->doesntHave('receivable')
            ->doesntHave('dineOrder')
            ->notFullyReturned()
            // A bill-level discount can leave nothing owed while some quantity is unreturned: such a bill can't be paid.
            ->whereRaw('transactions.grand_total > coalesce((select sum(sr2.total_return_amount) from sales_returns sr2'
                ." where sr2.transaction_id = transactions.id and sr2.status = 'completed'), 0)");
    }

    /**
     * Bayar sekarang sales with no money received, cancelled when their shift closes (REQ-003 AC-20). Never a bill
     * (pay later or dine-in), a split with a paid part or a sale with a completed return (BR-15), or one already cancelled.
     */
    public function scopeUnpaidPayNow($query)
    {
        return $query->where('is_pay_later', false)
            ->whereIn('payment_status', ['pending', 'failed', 'expired'])
            ->whereNotIn('payment_method', ['pay_later', self::METHOD_OPEN_BILL])
            ->doesntHave('dineOrder')
            ->doesntHave('receivable')
            ->whereDoesntHave('tenders', fn ($tenders) => $tenders->where('payment_status', TransactionTender::STATUS_PAID))
            ->whereDoesntHave('salesReturns', fn ($returns) => $returns->where('status', 'completed'))
            ->notRestocked();
    }

    /** Not cancelled: see wasRestocked(). */
    public function scopeNotRestocked($query)
    {
        return $query->whereNotIn('transactions.id', StockMutation::query()
            ->where('reference_type', 'transaction_expire')
            ->select('reference_id'));
    }

    /** Some quantity is still unreturned (no completed return, or returned qty below sold qty). */
    public function scopeNotFullyReturned($query)
    {
        return $query->where(fn ($q) => $q
            ->whereDoesntHave('salesReturns', fn ($returns) => $returns->where('status', 'completed'))
            ->orWhereRaw('(select coalesce(sum(sri.qty_return), 0) from sales_return_items sri'
                .' join sales_returns sr on sr.id = sri.sales_return_id'
                ." where sr.transaction_id = transactions.id and sr.status = 'completed')"
                .' < (select coalesce(sum(td.qty), 0) from transaction_details td where td.transaction_id = transactions.id)'));
    }

    /** @return HasMany<SalesReturn, $this> */
    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** What is still owed after partial returns: grand_total minus completed returns' total_return_amount (the sum SalesReturnController::calculateSettlement subtracts from a receivable). */
    public function remainingTotal(): int
    {
        return max(0, (int) $this->grand_total
            - (int) $this->salesReturns()->where('status', 'completed')->sum('total_return_amount'));
    }

    /**
     * What a sale owes after the returns that handed nothing back, those completed before it was paid: a payment beyond
     * it is an overpayment (#444). The shift recap takes off the same returns.
     */
    public function owedAfterReturns(): int
    {
        return max(0, (int) $this->grand_total
            - (int) $this->salesReturns()->nothingRefunded()->sum('total_return_amount'));
    }

    /**
     * The part of overpaid_amount taken in cash (#444): what a split's excess is beyond its paid non-cash tenders. The
     * cashier hands it back in cash, and the shift recap takes it off the cash sales.
     */
    public function cashExcess(): int
    {
        // Only a split holds both: any other overpaid sale was paid without cash.
        if ($this->payment_method !== 'split' || $this->overpaid_amount < 1) {
            return 0;
        }

        return max(0, (int) $this->overpaid_amount - (int) $this->tenders()
            ->where('payment_status', TransactionTender::STATUS_PAID)
            ->where('method', '!=', TransactionTender::METHOD_CASH)
            ->sum('amount'));
    }

    /**
     * For the refund notice of the sale page, as the overpayment audit row kept it: the cash to hand back, and whether the
     * sale's shift was open when the excess was flagged. That picks the instruction, and a later close does not change it
     * (BR-6). A row without that fact gives no cash line.
     *
     * @return array{cash_excess: int, flagged_shift_open: bool}
     */
    public function cashRefundNotice(): array
    {
        $meta = $this->overpaid_amount > 0
            ? AuditLog::where('event', 'payment.overpaid_after_return')
                ->where('auditable_type', $this->getMorphClass())
                ->where('auditable_id', $this->id)
                ->latest('id')
                ->first()?->meta
            : null;

        return isset($meta['shift_open'])
            ? ['cash_excess' => $meta['cash_excess'], 'flagged_shift_open' => $meta['shift_open']]
            : ['cash_excess' => 0, 'flagged_shift_open' => false];
    }

    /**
     * What the customer is asked for this sale, after returns. A credit sale keeps its total (its receivable says what is
     * owed). An unpaid, failed or expired sale is asked what it still owes. A pending or paid one was asked the sum of
     * its tenders; without tenders, an open bill or a retried payment its total less the returns that refunded nothing,
     * and any other sale its total. CashierShiftService::calculateSummary counts by the same rule.
     */
    public function amountDue(): int
    {
        // Read without setting the relation: the model may be serialised into a page afterwards.
        $related = fn (string $name) => $this->relationLoaded($name) ? $this->getRelation($name) : $this->{$name}()->getResults();
        $total = (int) $this->grand_total;

        if ($related('receivable')) {
            return $total;
        }

        $payable = in_array($this->payment_status, self::OPEN_BILL_PAYABLE, true);

        if (! $payable) {
            $tenders = $related('tenders');

            if ($tenders->isNotEmpty()) {
                return (int) $tenders->sum('amount');
            }

            if (! $this->is_pay_later && ! $this->payment_attempt && ! $related('dineOrder')) {
                return $total;
            }
        }

        $returns = $related('salesReturns')->where('status', 'completed');

        if (! $payable) {
            $returns = $returns->where('refund_amount', 0)->where('credited_amount', 0);
        }

        return max(0, $total - (int) $returns->sum('total_return_amount'));
    }

    /** For screens and receipts: the amount to ask when returns put a sale not yet paid below its total, else null. */
    public function reducedAmountDue(): ?int
    {
        if ($this->payment_status === 'paid') {
            return null;
        }

        $due = $this->amountDue();

        return $due < (int) $this->grand_total ? $due : null;
    }

    /**
     * The history page's amounts: owed_total is what the "Bayar" dialog of an open bill asks, amount_due what is shown
     * when returns lowered the amount. Eager load them with withScreenAmounts() for a list.
     *
     * @return array{owed_total: int, amount_due: ?int}
     */
    public function screenAmounts(): array
    {
        $returned = (int) $this->getRelationValue('salesReturns')->where('status', 'completed')->sum('total_return_amount');

        return ['owed_total' => max(0, (int) $this->grand_total - $returned), 'amount_due' => $this->reducedAmountDue()];
    }

    /** The relations screenAmounts() reads beyond receivable and dineOrder, for a list of sales. */
    public function scopeWithScreenAmounts($query)
    {
        return $query->with('salesReturns', 'tenders');
    }

    public function tenders()
    {
        return $this->hasMany(TransactionTender::class);
    }

    /** What a bill still has to collect: the remaining total less the parts already paid. */
    public function unpaidAmount(): int
    {
        return max(0, $this->remainingTotal()
            - (int) $this->tenders()->where('payment_status', TransactionTender::STATUS_PAID)->sum('amount'));
    }

    /**
     * Does a cashier's tender hold the bill, so a late online payment must not be booked on top of it?
     * A pending or paid tender does (the cashier is collecting, or money was taken); a failed or expired one never held
     * money and does not (business answer on #327). The one place this rule lives.
     */
    public function hasBlockingTenders(): bool
    {
        return $this->tenders()->whereIn('payment_status', [TransactionTender::STATUS_PENDING, TransactionTender::STATUS_PAID])->exists();
    }

    /**
     * The expiry command or a shift close returned the stock of this sale: it is cancelled for good.
     *
     * @param  bool  $locking  inside a transaction that already read something: a locking read sees what was committed since
     */
    public function wasRestocked(bool $locking = false): bool
    {
        $marker = StockMutation::where('reference_type', 'transaction_expire')->where('reference_id', $this->id);

        return $locking ? $marker->lockForUpdate()->first() !== null : $marker->exists();
    }

    /**
     * Store a gateway charge's outcome only while its attempt is still the bill's current one and still pending: a
     * newer claim or a webhook that already settled the bill wins. One guarded UPDATE, so no lock is needed.
     *
     * @param  array<string, mixed>  $values
     * @return int affected rows: 0 means the attempt is no longer current and nothing was written
     */
    public static function storeChargeResult(int $id, int $attempt, array $values): int
    {
        return self::whereKey($id)
            ->where('payment_attempt', $attempt)
            ->where('payment_status', 'pending')
            ->update($values);
    }

    /**
     * The guest's online charge is in flight: pending, charged at least once, and no tender rows. A guest charge
     * never writes tenders; a cashier's own charge or transfer always does (whatever its attempt number, since a
     * cashier's gateway charge after a guest attempt also carries one).
     */
    public function isAwaitingGuestPayment(): bool
    {
        if ($this->payment_status !== 'pending' || $this->payment_attempt < 1) {
            return false;
        }

        // The POS list selects a tenders_exists column (1 or null), so a list of bills costs no query per row.
        $hasTenders = array_key_exists('tenders_exists', $this->attributes)
            ? (bool) $this->attributes['tenders_exists']
            : $this->tenders()->exists();

        return ! $hasTenders;
    }

    public function usedCustomerVoucher()
    {
        return $this->hasOne(CustomerVoucher::class, 'used_transaction_id');
    }

    public function campaignLogs()
    {
        return $this->hasMany(CustomerCampaignLog::class);
    }

    public function discountApprover()
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function discountApprovalLogs()
    {
        return $this->hasMany(DiscountApprovalLog::class);
    }

    public function needsDiscountApproval(): bool
    {
        $threshold = (int) Setting::get('discount_approval_threshold', 0);
        $percentThreshold = (int) Setting::get('discount_approval_percent_threshold', 0);

        if ($threshold > 0 && $this->discount >= $threshold) {
            return true;
        }
        if ($percentThreshold > 0 && $this->grand_total > 0) {
            $percent = ($this->discount / $this->grand_total) * 100;
            if ($percent >= $percentThreshold) {
                return true;
            }
        }

        return false;
    }
}
