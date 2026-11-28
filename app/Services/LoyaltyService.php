<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\LoyaltyPointHistory;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoyaltyService
{
    public const TIER_REGULAR = 'regular';

    public const TIER_SILVER = 'silver';

    public const TIER_GOLD = 'gold';

    public const TIER_PLATINUM = 'platinum';

    public function settings(): array
    {
        return [
            'enable_earn' => Setting::getBool('loyalty_enable_earn', true),
            'enable_redeem' => Setting::getBool('loyalty_enable_redeem', true),
            'earn_rate_amount' => max(1, Setting::getInt('loyalty_earn_rate_amount', 10000)),
            'redeem_point_value' => max(1, Setting::getInt('loyalty_redeem_point_value', 100)),
            'tiers' => $this->tiers(),
        ];
    }

    public function tiers(): array
    {
        return [
            self::TIER_REGULAR => [
                'label' => 'Regular',
                'minimum_total_spent' => Setting::getInt('loyalty_tier_regular_threshold', 0),
            ],
            self::TIER_SILVER => [
                'label' => 'Silver',
                'minimum_total_spent' => Setting::getInt('loyalty_tier_silver_threshold', 500000),
            ],
            self::TIER_GOLD => [
                'label' => 'Gold',
                'minimum_total_spent' => Setting::getInt('loyalty_tier_gold_threshold', 1500000),
            ],
            self::TIER_PLATINUM => [
                'label' => 'Platinum',
                'minimum_total_spent' => Setting::getInt('loyalty_tier_platinum_threshold', 3000000),
            ],
        ];
    }

    public function tierOptions(): array
    {
        return collect($this->tiers())
            ->map(fn (array $tier, string $key) => [
                'value' => $key,
                'label' => $tier['label'],
            ])
            ->values()
            ->all();
    }

    public function settingsPayload(): array
    {
        $settings = $this->settings();

        return [
            'enable_earn' => $settings['enable_earn'],
            'enable_redeem' => $settings['enable_redeem'],
            'earn_rate_amount' => $settings['earn_rate_amount'],
            'redeem_point_value' => $settings['redeem_point_value'],
            'tiers' => collect($settings['tiers'])
                ->map(fn (array $config, string $key) => [
                    'key' => $key,
                    ...$config,
                ])
                ->values()
                ->all(),
        ];
    }

    public function updateSettings(array $payload): void
    {
        Setting::setMany([
            'loyalty_enable_earn' => [
                'value' => $payload['enable_earn'] ? '1' : '0',
                'description' => 'Aktifkan perolehan poin loyalty',
            ],
            'loyalty_enable_redeem' => [
                'value' => $payload['enable_redeem'] ? '1' : '0',
                'description' => 'Aktifkan redeem poin loyalty',
            ],
            'loyalty_earn_rate_amount' => [
                'value' => (string) $payload['earn_rate_amount'],
                'description' => 'Nominal belanja untuk mendapatkan 1 poin',
            ],
            'loyalty_redeem_point_value' => [
                'value' => (string) $payload['redeem_point_value'],
                'description' => 'Nilai rupiah untuk 1 poin redeem',
            ],
            'loyalty_tier_regular_threshold' => [
                'value' => (string) $payload['tiers'][self::TIER_REGULAR],
                'description' => 'Ambang total belanja tier Regular',
            ],
            'loyalty_tier_silver_threshold' => [
                'value' => (string) $payload['tiers'][self::TIER_SILVER],
                'description' => 'Ambang total belanja tier Silver',
            ],
            'loyalty_tier_gold_threshold' => [
                'value' => (string) $payload['tiers'][self::TIER_GOLD],
                'description' => 'Ambang total belanja tier Gold',
            ],
            'loyalty_tier_platinum_threshold' => [
                'value' => (string) $payload['tiers'][self::TIER_PLATINUM],
                'description' => 'Ambang total belanja tier Platinum',
            ],
        ]);
    }

    public function ensureMembership(Customer $customer, bool $force = false): Customer
    {
        if (! $customer->is_loyalty_member && ! $force) {
            return $customer;
        }

        $payload = [
            'is_loyalty_member' => true,
            'member_code' => $customer->member_code ?: $this->generateMemberCode(),
            'loyalty_member_since' => $customer->loyalty_member_since ?: now(),
        ];

        $customer->fill($payload);

        if ($customer->isDirty()) {
            $customer->save();
        }

        return $customer->refresh();
    }

    public function issueMemberCode(): string
    {
        return $this->generateMemberCode();
    }

    public function syncTier(Customer $customer): Customer
    {
        $tiers = $this->tiers();
        $tier = self::TIER_REGULAR;
        $totalSpent = (int) $customer->loyalty_total_spent;

        foreach ($tiers as $key => $config) {
            if ($totalSpent >= (int) $config['minimum_total_spent']) {
                $tier = $key;
            }
        }

        if ($customer->loyalty_tier !== $tier) {
            $customer->forceFill(['loyalty_tier' => $tier])->save();
        }

        return $customer->refresh();
    }

    public function previewCheckout(
        array $pricingPreview,
        ?Customer $customer = null,
        array $options = [],
        ?CarbonInterface $at = null,
        ?Outlet $outlet = null
    ): array {
        $at = $at ?? now();
        $settings = $this->settings();
        $subtotalAfterPromo = max(0, (int) data_get($pricingPreview, 'summary.subtotal_after_promo', 0));
        $manualDiscountRequested = max(0, (int) ($options['manual_discount'] ?? 0));
        $shippingCost = max(0, (int) ($options['shipping_cost'] ?? 0));
        $requestedRedeemPoints = max(0, (int) ($options['redeem_points'] ?? 0));
        $voucher = $options['voucher'] ?? null;

        $availablePoints = $customer?->is_loyalty_member ? (int) $customer->loyalty_points : 0;
        $validatedVoucher = $this->validateVoucher($customer, $voucher, $subtotalAfterPromo, $at, $outlet);
        $voucherDiscount = $validatedVoucher
            ? $this->calculateVoucherDiscount($validatedVoucher, $subtotalAfterPromo)
            : 0;

        $afterVoucher = max(0, $subtotalAfterPromo - $voucherDiscount);
        $redeemPointValue = (int) $settings['redeem_point_value'];
        $maxRedeemPoints = $settings['enable_redeem']
            ? (int) floor($afterVoucher / max(1, $redeemPointValue))
            : 0;
        $appliedRedeemPoints = $settings['enable_redeem']
            ? min($requestedRedeemPoints, $availablePoints, $maxRedeemPoints)
            : 0;
        $pointsDiscount = $appliedRedeemPoints * $redeemPointValue;

        $afterLoyalty = max(0, $afterVoucher - $pointsDiscount);
        $manualDiscountApplied = min($manualDiscountRequested, $afterLoyalty);

        $billTotals = app(BillTotalService::class);
        $bill = $billTotals->compute(
            $outlet,
            $billTotals->taxLines(data_get($pricingPreview, 'items', []), $outlet),
            $afterLoyalty - $manualDiscountApplied,
            $shippingCost,
            // Only a dine-in sale carries the service charge; an API client without an order type is not one.
            ($options['order_type'] ?? null) === 'in_store',
        );
        $pointsEarnedPreview = $this->calculateEarnPoints(
            $customer,
            max(0, $bill['grand_total'] - $shippingCost),
            $settings
        );

        return [
            'items' => data_get($pricingPreview, 'items', []),
            'applied_groups' => data_get($pricingPreview, 'applied_groups', []),
            'consumed_quantities' => data_get($pricingPreview, 'consumed_quantities', []),
            'unmatched_items' => data_get($pricingPreview, 'unmatched_items', []),
            'summary' => [
                'base_subtotal' => (int) data_get($pricingPreview, 'summary.base_subtotal', 0),
                'promo_discount_total' => (int) data_get($pricingPreview, 'summary.promo_discount_total', 0),
                'subtotal_after_promo' => $subtotalAfterPromo,
                'voucher_discount_total' => $voucherDiscount,
                'loyalty_discount_total' => $pointsDiscount,
                'manual_discount_total' => $manualDiscountApplied,
                'shipping_cost' => $shippingCost,
                // service_charge, tax_kind, tax_total, tax_rate, tax_base, grand_total and bill_basis
                ...$bill,
                'available_loyalty_points' => $availablePoints,
                'requested_redeem_points' => $requestedRedeemPoints,
                'applied_redeem_points' => $appliedRedeemPoints,
                'points_value' => $redeemPointValue,
                'points_earned_preview' => $pointsEarnedPreview,
            ],
            'customer' => $customer ? [
                'id' => $customer->id,
                'is_loyalty_member' => (bool) $customer->is_loyalty_member,
                'member_code' => $customer->member_code,
                'loyalty_tier' => $customer->loyalty_tier,
                'loyalty_points' => $availablePoints,
            ] : null,
            'voucher' => $validatedVoucher ? $this->serializeVoucher($validatedVoucher) : null,
            'eligible_vouchers' => $customer
                 ? $this->eligibleVouchersForCustomer($customer, $subtotalAfterPromo, $at, $outlet)
                     ->map(fn (CustomerVoucher $eligibleVoucher) => $this->serializeVoucher($eligibleVoucher))
                     ->values()
                     ->all()
                : [],
            'settings' => $this->settingsPayload(),
        ];
    }

    public function eligibleVouchersForCustomer(
        Customer $customer,
        int $subtotalAfterPromo = 0,
        ?CarbonInterface $at = null,
        ?Outlet $outlet = null
    ): Collection {
        $at = $at ?? now();

        return $customer->vouchers()
            ->where('is_active', true)
            ->where('is_used', false)
            ->where(function ($query) use ($outlet) {
                $query->whereNull('outlet_id');
                if ($outlet) {
                    $query->orWhere('outlet_id', $outlet->id);
                }
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $at);
            })
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (CustomerVoucher $voucher) => $subtotalAfterPromo >= (int) $voucher->minimum_order)
            ->values();
    }

    public function finalizeTransaction(
        Transaction $transaction,
        ?Customer $customer,
        array $checkoutPreview
    ): ?Customer {
        if (! $customer) {
            return null;
        }

        $settings = $this->settings();
        $customer = $customer->fresh();

        if ($customer->is_loyalty_member) {
            $customer = $this->ensureMembership($customer);
        }

        $voucherCode = (string) ($transaction->customer_voucher_code ?? '');
        $voucher = $voucherCode !== ''
            ? CustomerVoucher::query()
                ->where('customer_id', $customer->id)
                ->where('code', $voucherCode)
                ->first()
            : null;

        $redeemedPoints = (int) ($transaction->loyalty_points_redeemed ?? 0);
        if ($redeemedPoints > 0 && $customer->is_loyalty_member && $settings['enable_redeem']) {
            $customer->decrement('loyalty_points', $redeemedPoints);

            $this->recordHistory(
                $customer->fresh(),
                $transaction,
                LoyaltyPointHistory::TYPE_REDEEM,
                -$redeemedPoints,
                (int) ($transaction->loyalty_discount_total ?? 0),
                'Redeem poin pada transaksi '.$transaction->invoice
            );
        }

        if ($voucher) {
            // ponytail: atomic single-use claim — reject when another checkout already used it concurrently
            $claimed = CustomerVoucher::whereKey($voucher->id)
                ->where('is_used', false)
                ->update([
                    'is_used' => true,
                    'used_at' => now(),
                    'used_transaction_id' => $transaction->id,
                ]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Voucher sudah digunakan pada transaksi lain.',
                ]);
            }

            $this->recordHistory(
                $customer->fresh(),
                $transaction,
                LoyaltyPointHistory::TYPE_VOUCHER,
                0,
                (int) ($transaction->customer_voucher_discount ?? 0),
                'Voucher '.$voucher->code.' digunakan'
            );
        }

        $eligibleSpendForPoints = max(
            0,
            (int) $transaction->grand_total - (int) $transaction->shipping_cost
        );
        $earnedPoints = $this->calculateEarnPoints($customer, $eligibleSpendForPoints, $settings);

        $transaction->forceFill([
            'loyalty_points_earned' => $earnedPoints,
        ])->save();

        $customer->forceFill([
            'loyalty_points' => max(0, (int) $customer->loyalty_points) + $earnedPoints,
            'loyalty_total_spent' => (int) $customer->loyalty_total_spent + (int) $transaction->grand_total,
            'loyalty_transaction_count' => (int) $customer->loyalty_transaction_count + 1,
            'last_purchase_at' => now(),
        ])->save();

        if ($earnedPoints > 0) {
            $this->recordHistory(
                $customer->fresh(),
                $transaction,
                LoyaltyPointHistory::TYPE_EARN,
                $earnedPoints,
                (int) $transaction->grand_total,
                'Poin transaksi '.$transaction->invoice
            );
        }

        return $this->syncTier($customer->fresh());
    }

    /**
     * Take back a return's share of the points a sale moved (#545). Redeemed points come back as a positive `redeem`
     * history row; earned points are reversed by a negative `earn` row. The balance never drops below 0: the reversal
     * is clamped, and the shortfall the customer no longer holds is left in the earn row's note (the caller also
     * writes it to the return's audit entry, BR-7). `loyalty_total_spent` drops by the money given back, clamped, and
     * the tier is re-synced (Q1). Call it with the locked customer, inside the complete transaction.
     *
     * @return array{restored:int, reversed:int} the amounts actually applied
     */
    public function applyReturn(Customer $customer, SalesReturn $return, Transaction $sale, int $restore, int $reverse, int $moneyBack): array
    {
        if ($restore < 1 && $reverse < 1 && $moneyBack < 1) {
            return ['restored' => 0, 'reversed' => 0];
        }

        $balance = max(0, (int) $customer->loyalty_points);
        $newBalance = max(0, $balance + $restore - $reverse);
        // The reversal is capped by what the balance can cover after the restore; the remainder is a shortfall.
        $reversed = $balance + $restore - $newBalance;
        $shortfall = max(0, $reverse - $reversed);

        $customer->forceFill([
            'loyalty_points' => $newBalance,
            'loyalty_total_spent' => max(0, (int) $customer->loyalty_total_spent - max(0, $moneyBack)),
        ])->save();

        if ($restore > 0) {
            $this->recordHistory(
                $customer->fresh(),
                $sale,
                LoyaltyPointHistory::TYPE_REDEEM,
                $restore,
                0,
                'Retur '.$return->code.': poin redeem dikembalikan'
            );
        }

        if ($reverse > 0) {
            $this->recordHistory(
                $customer->fresh(),
                $sale,
                LoyaltyPointHistory::TYPE_EARN,
                -$reversed,
                0,
                'Retur '.$return->code.': poin perolehan ditarik kembali'
                    .($shortfall > 0 ? " (kurang {$shortfall} poin)" : '')
            );
        }

        $this->syncTier($customer->fresh());

        return ['restored' => $restore, 'reversed' => $reversed];
    }

    public function validateVoucher(
        ?Customer $customer,
        mixed $voucher,
        int $subtotalAfterPromo,
        ?CarbonInterface $at = null,
        ?Outlet $outlet = null
    ): ?CustomerVoucher {
        $at = $at ?? now();

        if (! $customer || ! $voucher instanceof CustomerVoucher) {
            return null;
        }

        if ((int) $voucher->customer_id !== (int) $customer->id) {
            return null;
        }

        if ($voucher->outlet_id && (! $outlet || (int) $voucher->outlet_id !== (int) $outlet->id)) {
            return null;
        }

        if (! $voucher->is_active || $voucher->is_used) {
            return null;
        }

        if ($voucher->starts_at && $voucher->starts_at->gt($at)) {
            return null;
        }

        if ($voucher->expires_at && $voucher->expires_at->lt($at)) {
            return null;
        }

        if ($subtotalAfterPromo < (int) $voucher->minimum_order) {
            return null;
        }

        return $voucher;
    }

    public function calculateVoucherDiscount(CustomerVoucher $voucher, int $subtotalAfterPromo): int
    {
        $discount = match ($voucher->discount_type) {
            CustomerVoucher::TYPE_PERCENTAGE => (int) round($subtotalAfterPromo * ((float) $voucher->discount_value / 100)),
            CustomerVoucher::TYPE_FIXED_AMOUNT => (int) round((float) $voucher->discount_value),
            default => 0,
        };

        return min($subtotalAfterPromo, max(0, $discount));
    }

    public function serializeVoucher(CustomerVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'name' => $voucher->name,
            'discount_type' => $voucher->discount_type,
            'discount_value' => (float) $voucher->discount_value,
            'minimum_order' => (int) $voucher->minimum_order,
            'expires_at' => optional($voucher->expires_at)?->toIso8601String(),
            'starts_at' => optional($voucher->starts_at)?->toIso8601String(),
            'used_at' => optional($voucher->used_at)?->toIso8601String(),
            'status' => $voucher->currentStatusLabel(),
        ];
    }

    public function syncAllMemberTiers(): void
    {
        Customer::query()
            ->where('is_loyalty_member', true)
            ->orderBy('id')
            ->chunkById(100, function ($customers) {
                foreach ($customers as $customer) {
                    $this->syncTier($customer);
                }
            });
    }

    /**
     * A denied discount raises the bill back to its full total (#484): add the difference's points and total spent
     * so loyalty follows the recomputed total (REQ-010 BR-7, #508). Redeemed points are untouched.
     */
    public function awardAfterDenial(Transaction $sale, int $oldGrandTotal, int $newGrandTotal): void
    {
        $difference = $newGrandTotal - $oldGrandTotal;

        if ($difference <= 0 || ! $sale->customer_id) {
            return;
        }

        $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->first();
        if (! $customer) {
            return;
        }

        $settings = $this->settings();
        $shipping = (int) $sale->shipping_cost;
        // Points on the full bill, earned on the total before and after: a difference that crosses the earn rate
        // still earns its point (95.000 -> 100.000 wins 1, though the difference is below the rate).
        $points = max(0,
            $this->calculateEarnPoints($customer, max(0, $newGrandTotal - $shipping), $settings)
            - $this->calculateEarnPoints($customer, max(0, $oldGrandTotal - $shipping), $settings));

        $customer->forceFill([
            'loyalty_points' => (int) $customer->loyalty_points + $points,
            'loyalty_total_spent' => (int) $customer->loyalty_total_spent + $difference,
        ])->save();

        if ($points > 0) {
            $this->recordHistory($customer->fresh(), $sale, LoyaltyPointHistory::TYPE_EARN, $points, $difference, 'Diskon ditolak pada transaksi '.$sale->invoice);
        }

        $this->syncTier($customer->fresh());
    }

    private function calculateEarnPoints(?Customer $customer, int $eligibleSpend, array $settings): int
    {
        if (! $customer?->is_loyalty_member || ! $settings['enable_earn']) {
            return 0;
        }

        return (int) floor($eligibleSpend / max(1, (int) $settings['earn_rate_amount']));
    }

    private function recordHistory(
        Customer $customer,
        Transaction $transaction,
        string $type,
        int $pointsDelta,
        int $amountDelta,
        string $notes
    ): void {
        LoyaltyPointHistory::create([
            'customer_id' => $customer->id,
            'transaction_id' => $transaction->id,
            'type' => $type,
            'points_delta' => $pointsDelta,
            'balance_after' => max(0, (int) $customer->loyalty_points),
            'amount_delta' => max(0, $amountDelta),
            'reference' => $transaction->invoice,
            'notes' => $notes,
        ]);
    }

    private function generateMemberCode(): string
    {
        do {
            $code = 'MEM-'.Str::upper(Str::random(8));
        } while (Customer::query()->where('member_code', $code)->exists());

        return $code;
    }
}
