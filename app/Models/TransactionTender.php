<?php

namespace App\Models;

use App\Services\TransactionTenderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionTender extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_MIDTRANS = 'midtrans';

    public const METHOD_XENDIT = 'xendit';

    public const METHOD_QRIS = 'qris';

    public const STATUS_PAID = 'paid';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    /** A gateway tender this old with no reference was claimed by a request that died before storing its charge. */
    public const CLAIM_STALE_SECONDS = 120; // well above the HTTP client's 30 s timeout

    protected $fillable = [
        'transaction_id',
        'method',
        'amount',
        'cash_received',
        'change',
        'bank_account_id',
        'cashier_shift_id',
        'payment_status',
        'payment_reference',
        'payment_url',
        'qr_string',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'cash_received' => 'integer',
            'change' => 'integer',
            'bank_account_id' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Abandoned claims: nothing else would ever free such a pending bill, so the cashier may pay it again.
     *
     * @param  Builder<TransactionTender>  $query
     */
    public function scopeAbandonedClaim(Builder $query): void
    {
        $query->whereIn('method', TransactionTenderService::GATEWAY_METHODS)
            ->whereNull('payment_reference')
            ->where('created_at', '<', now()->subSeconds(self::CLAIM_STALE_SECONDS));
    }

    /**
     * Gateway order id used for this tender (invoice or invoice-{method} for splits).
     */
    public function getOrderReferenceAttribute(): string
    {
        // ponytail: derived, not stored — matches PaymentGatewayManager::createTenderPayment
        $siblings = $this->transaction->tenders()->count();
        $orderId = $siblings > 1 ? $this->transaction->invoice.'-'.$this->method : $this->transaction->invoice;

        $attempt = (int) $this->transaction->getAttribute('payment_attempt');

        // A booked late gateway part (paid) beside a rest of the same method was charged at the previous attempt.
        // Keep its own order id so the rest's callback (invoice-method-r{attempt}) can never match it (#530 BR-2).
        if ($attempt > 0 && $this->payment_status === self::STATUS_PAID
            && in_array($this->method, TransactionTenderService::GATEWAY_METHODS, true)
            && $this->transaction->tenders()->where('method', $this->method)->count() > 1) {
            $attempt = 0;
        }

        return $attempt > 0 ? "{$orderId}-r{$attempt}" : $orderId;
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
