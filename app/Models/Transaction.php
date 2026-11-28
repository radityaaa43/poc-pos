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
        'parent_transaction_id',
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
        'is_pay_later',
        'payment_reference',
        'payment_url',
        'qr_string',
        'order_type',
        'note',
        'bank_account_id',
        'tax_rate',
        'tax_total',
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
        'parent_transaction_id' => 'integer',
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
        'discount_approved_at' => 'datetime',
        'payment_attempt' => 'integer',
        'late_superseded_payment_at' => 'datetime',
        'paid_after_close_at' => 'datetime',
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

    /** @return BelongsTo<Transaction, $this> the root order of an add-on sale (#251); null for a normal sale */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    /** The order a sale belongs to: the link always points at the root, so no recursion. */
    public function orderRootId(): int
    {
        return $this->parent_transaction_id ?? $this->id;
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
