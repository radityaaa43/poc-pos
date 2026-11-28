<?php

namespace Tests\Feature\DineIn;

use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\PricingRule;
use App\Models\ProductWarehouse;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\DineIn\Concerns\MakesPosTableOrders;
use Tests\TestCase;

// #426 (REQ-009 AC-01..AC-03): a POS table order is a dine order the cashier creates and accepts in one go.
class PosTableOrderTest extends TestCase
{
    use MakesPosTableOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPosTable();
    }

    public function test_a_pos_table_order_becomes_an_open_bill_and_takes_stock(): void
    {
        $order = $this->sentOrder();
        $this->assertSame(__('messages.pos_table_order.sent', ['table' => 'Meja 7'], 'id'), session('success'));

        $this->assertSame(
            ['pos', DineOrder::STATUS_ACCEPTED, $this->cashier->id, 20000, 2],
            [$order->source, $order->status, (int) $order->cashier_id, $order->subtotal, $order->item_count],
        );
        $bill = $order->transaction;
        $this->assertSame(['unpaid', Transaction::METHOD_OPEN_BILL, 20000], [$bill->payment_status, $bill->payment_method, (int) $bill->grand_total]);
        $this->assertStock(98, $this->nasi->id);
        $this->assertSame(0, Cart::count());
        $this->getJson(route('transactions.open-bills'))->assertJsonPath('openBills.0.order_id', $order->id)->assertJsonPath('openBills.0.table', 'Meja 7');
        $this->assertSame($order->id, (int) AuditLog::where('event', 'dine_order.created_from_pos')->sole()->auditable_id);
    }

    public function test_pay_later_reaches_the_kitchen_and_pay_at_counter_waits(): void
    {
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);

        $counter = $this->sentOrder();
        $later = $this->sentOrder(['payment_option' => DineOrder::PAY_LATER]);

        $this->assertSame(0, KitchenItem::where('transaction_id', $counter->transaction_id)->count());
        $this->assertSame(1, KitchenItem::where('transaction_id', $later->transaction_id)->count());
        $this->assertTrue((bool) $later->transaction->is_pay_later);
    }

    public function test_insufficient_stock_leaves_no_order_and_keeps_the_cart(): void
    {
        ProductWarehouse::where('product_id', $this->nasi->id)->update(['stock' => 3]);
        $this->cart(qty: 5);

        $this->send()->assertSessionHasErrors('stock');

        $this->assertNothingSent(carts: 1);
        $this->assertStock(3, $this->nasi->id, product: 100);
    }

    public function test_unit_and_composite_lines_price_like_the_pos_cart(): void
    {
        PricingRule::create([
            'name' => 'Diskon', 'kind' => PricingRule::KIND_STANDARD_DISCOUNT, 'is_active' => true, 'priority' => 0,
            'target_type' => PricingRule::TARGET_ALL, 'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE, 'discount_value' => 10,
        ]);
        $box = Unit::create(['code' => 'BOX-426', 'name' => 'Box', 'symbol' => 'box']);
        $teh = $this->product('Teh', 1000);
        $paket = $this->product('Paket', 15000, ['is_composite' => true]);
        $paket->components()->attach($teh->id, ['qty' => 3]);
        // A unit line takes no pricing rule: its price is the cart's. The base line takes 10% off.
        $this->cart($teh, 2, ['unit_id' => $box->id, 'conversion_factor' => 12, 'price' => 22000]);
        $this->cart($paket, 1);
        $this->cart($this->nasi, 3);

        $this->send()->assertSessionHasNoErrors();

        $order = DineOrder::sole();
        $this->assertEquals(
            [$teh->id => [$box->id, 2, 11000], $paket->id => [null, 1, 13500], $this->nasi->id => [null, 3, 9000]],
            $order->items->mapWithKeys(fn ($item) => [$item->product_id => [$item->unit_id, $item->qty, $item->price]])->all(),
        );
        $this->assertSame(62500, (int) $order->transaction->grand_total);
        $this->assertStock(100 - 24 - 3, $teh->id); // 2 boxes of 12, and 3 in the bundle
        $this->assertStock(97, $this->nasi->id);
    }

    public function test_inactive_and_foreign_tables_are_refused(): void
    {
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $foreign = $this->tableNamed('Meja Cabang', $other);
        $this->table->update(['is_active' => false]);
        $this->cart();

        foreach ([$this->table->id, $foreign->id, 999999] as $id) {
            $this->send(['dine_table_id' => $id])->assertSessionHasErrors(['dine_table_id' => __('messages.pos_table_order.table_unavailable')]);
        }

        $this->assertNothingSent(carts: 1);
    }

    public function test_pay_later_is_refused_when_the_outlet_has_it_off(): void
    {
        $this->cart();

        $this->send(['payment_option' => DineOrder::PAY_LATER])
            ->assertSessionHasErrors(['payment_option' => __('messages.pos_table_order.pay_later_off')]);

        $this->assertNothingSent(carts: 1);
    }

    public function test_an_empty_cart_is_refused(): void
    {
        $this->sentOrder();
        // A double tap: the second post finds the cart already sent. A held cart is not sent either.
        $this->cart(overrides: ['hold_id' => 'HOLD-1']);

        $this->send()->assertSessionHasErrors(['cart' => __('messages.pos_table_order.cart_empty')]);

        $this->assertSame(1, DineOrder::count());
        $this->assertSame(1, Cart::count());
    }

    // A redirect that only flashes a message reads as success to Inertia: the refusal has to be a validation error.
    public function test_without_an_open_shift_the_order_is_refused_as_an_error(): void
    {
        CashierShift::query()->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);
        $this->cart();

        $this->send()->assertSessionHasErrors('shift')->assertSessionMissing('success');

        $this->assertNothingSent(carts: 1);
    }

    public function test_a_raw_material_in_the_cart_is_refused(): void
    {
        $this->cart($this->product('Beras', 5000, ['is_raw_material' => true]));

        $this->send()->assertSessionHasErrors('cart');

        $this->assertNothingSent(carts: 1);
    }

    public function test_adding_to_a_running_order_links_the_sale(): void
    {
        $original = $this->sentOrder();

        $addOn = $this->sentOrder(['add_on_to_dine_order_id' => $original->id]);

        $this->assertSame($original->id, $addOn->add_on_to_dine_order_id);
        $this->assertSame($original->transaction_id, $addOn->transaction->parent_transaction_id);
    }

    public function test_add_on_target_must_be_this_tables_live_order(): void
    {
        $original = $this->sentOrder();
        $elsewhere = $this->sentOrder(['dine_table_id' => $this->tableNamed('Meja 8')->id]);
        $waiting = DineOrder::create(['dine_table_id' => $this->table->id, 'status' => DineOrder::STATUS_SUBMITTED, 'subtotal' => 0, 'item_count' => 0]);
        $this->cart();

        $refusals = [
            $elsewhere->id => 'Pesanan asal tidak ditemukan di meja ini',
            999999 => 'Pesanan asal tidak ditemukan di meja ini',
            $waiting->id => 'Pesanan asal belum diterima — tunggu konfirmasi staf',
        ];
        foreach ($refusals as $target => $message) {
            $this->send(['add_on_to_dine_order_id' => $target])->assertSessionHasErrors(['add_on_to' => $message]);
        }
        $original->update(['status' => DineOrder::STATUS_CANCELLED]);
        $this->send(['add_on_to_dine_order_id' => $original->id])
            ->assertSessionHasErrors(['add_on_to' => 'Pesanan asal sudah dibatalkan — buat pesanan baru']);

        $this->assertSame(3, DineOrder::count());
        $this->assertSame(1, Cart::count());
    }

    public function test_table_routes_need_pos_access(): void
    {
        $nobody = User::factory()->create();
        $nobody->markEmailAsVerified();
        $this->cart();

        $this->actingAs($nobody)->getJson(route('transactions.tables'))->assertForbidden();
        $this->actingAs($nobody)->postJson(route('transactions.table-orders.store'), [
            'dine_table_id' => $this->table->id, 'payment_option' => DineOrder::PAY_AT_COUNTER,
        ])->assertForbidden();

        $this->assertNothingSent(carts: 1);
    }

    // Shift, table, carts, then the original order and its sales: on MySQL the first plain read fixes what the
    // transaction sees, so every lock the accept relies on comes before it.
    public function test_place_takes_its_locks_before_its_first_plain_read(): void
    {
        $original = $this->sentOrder();
        $this->cart();

        $this->assertLeadingReads(
            ['cashier_shifts', 'dine_tables', 'carts', 'dine_orders', 'transactions', 'transactions', 'transactions'],
            fn () => $this->send(['add_on_to_dine_order_id' => $original->id])->assertSessionHasNoErrors(),
        );
    }

    /** The first reads of the app's own transaction (level 1 is the test's), by table; on MySQL (CI) each of them locks. */
    private function assertLeadingReads(array $tables, Closure $act): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            if (DB::transactionLevel() > 1) {
                $sql[] = $query->sql;
            }
        });

        $act();

        $leading = array_slice($sql, 0, count($tables));
        $this->assertSame($tables, preg_replace('/^select .*? from .(\w+).*$/s', '$1', $leading));
        if (DB::getDriverName() === 'mysql') {
            foreach ($leading as $read) {
                $this->assertStringContainsString('for update', $read);
            }
        }
    }

    private function assertStock(int $expected, int $productId, ?int $product = null): void
    {
        $this->assertSame($expected, (int) ProductWarehouse::where('product_id', $productId)->value('stock'));
        $this->assertSame($product ?? $expected, (int) DB::table('products')->where('id', $productId)->value('stock'));
    }

    private function assertNothingSent(int $carts): void
    {
        $this->assertSame([0, 0, $carts], [DineOrder::count(), Transaction::count(), Cart::count()]);
    }
}
