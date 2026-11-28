<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The cashier's current bill, as every screen and API preview shows it: the active shift's outlet decides the
 * prices, the vouchers and the bill rules.
 */
class CheckoutPreviewService
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly PricingService $pricingService,
        private readonly LoyaltyService $loyaltyService,
    ) {}

    /**
     * @param  array<string, mixed>  $options  see LoyaltyService::previewCheckout
     * @param  Collection<int, Cart>  $carts  the cashier's active cart
     */
    public function checkout(User $user, ?Customer $customer, array $options, Collection $carts): array
    {
        $shift = $this->cashierShiftService->getActiveShiftForUser($user->id);
        $shift?->loadMissing('warehouse.outlet');
        $outlet = $shift?->warehouse?->outlet;

        // Read the cart in the order checkout stores it (id ascending): a bill with two tax rates takes the last
        // line's rate, so a preview over a `latest()` cart showed a different total than the sale stored (#450).
        $carts = $carts->sortBy('id')->values();

        return $this->loyaltyService->previewCheckout(
            $this->pricingService->previewCart($carts, $customer, null, $outlet),
            $customer,
            $options,
            null,
            $outlet,
        );
    }
}
