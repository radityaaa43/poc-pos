<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as CartCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A table order taken on the POS (#426, REQ-009 AC-02): the cashier's cart becomes a dine order that is accepted at
 * once, so stock, the unpaid bill and the kitchen follow the same code as an accepted QR order.
 */
class PosTableOrderService
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly PricingService $pricingService,
        private readonly AddOnOrderService $addOnOrderService,
        private readonly DineOrderService $dineOrderService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /** All or nothing: any refusal leaves no order and keeps the cart. */
    public function place(User $cashier, int $tableId, string $paymentOption, ?int $addOnToId = null): DineOrder
    {
        // Retried here: accept() can't retry a deadlock from inside this transaction.
        return DB::transaction(function () use ($cashier, $tableId, $paymentOption, $addOnToId) {
            // Every lock before the first plain read, which on MySQL fixes what this transaction (accept() included)
            // sees: the shift first, as everywhere; the table, as retiring it does; the cart; then the order and sales.
            $shift = $this->cashierShiftService->requireActiveShiftForUser($cashier->id, lockForUpdate: true);
            DiningTable::whereKey($tableId)->lockForUpdate()->first();
            $carts = Cart::where('cashier_id', $cashier->id)->active()->lockForUpdate()->get();
            $original = $addOnToId ? DineOrder::whereKey($addOnToId)->lockForUpdate()->first() : null;
            $this->addOnOrderService->resolveForDine($original); // locks the sales it would join

            $outlet = $shift->warehouse?->outlet;
            $table = DiningTable::active()->forOutlet($outlet)->find($tableId)
                ?? $this->refuse('dine_table_id', 'table_unavailable');

            // The same gate as the table QR.
            if ($paymentOption === DineOrder::PAY_LATER && ! ($outlet && Setting::getBoolForOutlet('pay_later_enabled', $outlet))) {
                $this->refuse('payment_option', 'pay_later_off');
            }

            $original = $addOnToId ? $this->addOnOrderService->originalForGuest((string) $original?->access_token, $table) : null;

            if ($carts->isEmpty()) {
                $this->refuse('cart', 'cart_empty');
            }

            $lines = $this->lines($carts, $outlet);
            // A product can be turned into a raw material while it sits in a cart.
            if ($raw = $carts->pluck('product')->firstWhere('is_raw_material', true)) {
                $this->refuse('cart', 'raw_material', ['product' => $raw->title]);
            }
            $order = DineOrder::create([
                'dine_table_id' => $table->id,
                'source' => DineOrder::SOURCE_POS,
                'cashier_id' => $cashier->id,
                'status' => DineOrder::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'payment_option' => $paymentOption,
                'subtotal' => $this->total($lines),
                'item_count' => $lines->sum('qty'),
                'add_on_to_dine_order_id' => $original?->id,
            ]);
            $order->items()->createMany($lines->all());

            $this->dineOrderService->accept($order);
            Cart::whereKey($carts->modelKeys())->delete();

            $this->auditLogService->log(
                event: 'dine_order.created_from_pos',
                module: 'dine_orders',
                auditable: $order,
                description: "Pesanan meja {$table->name} dibuat dari POS.",
                meta: ['dine_table_id' => $table->id, 'item_count' => $order->item_count, 'payment_option' => $paymentOption, 'source' => $order->source],
                actor: $cashier,
            );

            return $order;
        }, 3);
    }

    /**
     * The cart as the order will bill it, priced by the POS preview (a table bill takes no customer or discount).
     *
     * @param  CartCollection<int, Cart>  $carts  their `product` is loaded here: a plain read, so after any lock
     */
    public function lines(CartCollection $carts, ?Outlet $outlet): Collection
    {
        // Set by hand: Cart::product() is untyped, which Larastan refuses in with()/load().
        $products = Product::whereKey($carts->pluck('product_id'))->get()->keyBy('id');
        $carts->each(fn (Cart $cart) => $cart->setRelation('product', $products->get($cart->product_id)));

        return collect($this->pricingService->previewCart($carts, null, null, $outlet)['items'])->map(function (array $line) use ($carts) {
            $cart = $carts->find($line['cart_id']);

            return [
                'product_id' => $cart->product_id,
                'unit_id' => $cart->unit_id,
                'conversion_factor' => $cart->conversion_factor ?? 1,
                'qty' => $line['qty'],
                'price' => $line['effective_unit_price'],
            ];
        });
    }

    /** What the bill will say: accept() charges price x qty per line. */
    public function total(Collection $lines): int
    {
        return (int) $lines->sum(fn (array $line) => $line['price'] * $line['qty']);
    }

    /** @param array<string, string> $replace */
    private function refuse(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __("messages.pos_table_order.{$key}", $replace)]);
    }
}
