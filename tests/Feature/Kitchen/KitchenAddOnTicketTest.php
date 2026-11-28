<?php

namespace Tests\Feature\Kitchen;

use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenCancellation;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// #314 (REQ-004 AC-03, BR-1, BR-2): an add-on order has its own kitchen ticket, marked as one and named after the original.
class KitchenAddOnTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $cook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private Product $nasi;

    private Product $teh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create(['outlet_id' => $this->outlet->id, 'code' => 'WH-A', 'name' => 'Gudang A', 'type' => 'branch', 'is_active' => true]);
        $this->cook = User::factory()->create();
        $this->cook->givePermissionTo('dine-orders-prepare');
        $this->cook->outlets()->attach($this->outlet->id, ['is_default' => true]);

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->nasi = $this->product('Nasi goreng');
        $this->teh = $this->product('Es teh');

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
    }

    // AC-03, BR-1, TC-3
    public function test_add_on_ticket_has_kind_addon_root_number_and_own_items(): void
    {
        $device = $this->device();
        $original = $this->sell();
        $addOn = $this->addOnTo($original);

        $ticket = $this->tickets($device)[1];

        $this->assertSame('t'.$addOn->id, $ticket['key']);
        $this->assertSame('addon', $ticket['kind']);
        $this->assertSame($original->id, $ticket['root_id']);
        $this->assertSame($original->invoice, $ticket['number']);
        $this->assertFalse($ticket['unpaid']);
        $this->assertSame(['Es teh'], array_column($ticket['items'], 'title'));
        $this->assertSame(KitchenItem::where('transaction_id', $addOn->id)->pluck('id')->all(), $ticket['item_ids']);
    }

    // TC-2: the display groups an add-on under its original, the tickets never do; both come in arrival order
    public function test_add_on_is_never_merged_into_the_root_ticket(): void
    {
        $device = $this->device();
        $original = $this->sell();
        $addOn = $this->addOnTo($original);

        $tickets = $this->tickets($device);

        $this->assertSame(['t'.$original->id, 't'.$addOn->id], array_column($tickets, 'key'));
        $this->assertSame(['order', 'addon'], array_column($tickets, 'kind'));
        $this->assertSame([$original->id, $original->id], array_column($tickets, 'root_id'));
        $this->assertSame([$original->invoice, $original->invoice], array_column($tickets, 'number'));
        $this->assertSame(['Nasi goreng'], array_column($tickets[0]['items'], 'title'));
        $this->assertSame(['Es teh'], array_column($tickets[1]['items'], 'title'));
    }

    // BR-1: a table-QR original names the ticket by its table, whether the add-on came from the QR menu or the POS
    public function test_qr_add_on_uses_the_table_name(): void
    {
        $device = $this->device();
        $original = $this->onRecord('INV-TABLE', table: 'Meja 4', withItem: false);
        $this->onRecord('INV-QR-ADD', parent: $original, table: 'Meja 4');
        $this->onRecord('INV-POS-ADD', parent: $original);

        $tickets = $this->tickets($device);

        $this->assertSame(['Meja 4', 'Meja 4'], array_column($tickets, 'number'));
        $this->assertSame(['addon', 'addon'], array_column($tickets, 'kind'));
        // The type stays the add-on's own: only the number comes from the original.
        $this->assertSame(['dine_in', 'in_store'], array_column($tickets, 'type'));
    }

    // BR-1, TC-3: Belum lunas is the add-on's own payment status
    public function test_unpaid_add_on_is_marked_unpaid(): void
    {
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
        $device = $this->device();
        $original = $this->sell();
        $this->addOnTo($original, ['pay_later' => true, 'note' => 'Meja depan']);

        $this->assertSame([false, true], array_column($this->tickets($device), 'unpaid'));
    }

    // #251 BR-9: the original still waits for its payment, the paid add-on prints under the original's number
    public function test_add_on_to_an_unpaid_original_prints_when_paid_under_the_original_number(): void
    {
        $bank = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko', 'is_active' => true]);
        PaymentSetting::create(['default_gateway' => 'bank_transfer', 'bank_transfer_enabled' => true]);
        $device = $this->device();
        $original = $this->sell(['payment_gateway' => 'bank_transfer', 'bank_account_id' => $bank->id]);
        $this->addOnTo($original);

        $tickets = $this->tickets($device);

        $this->assertCount(1, $tickets);
        $this->assertSame('addon', $tickets[0]['kind']);
        $this->assertSame($original->invoice, $tickets[0]['number']);
        $this->assertFalse($tickets[0]['unpaid']);
    }

    public function test_add_on_with_nothing_to_prepare_has_no_ticket(): void
    {
        $device = $this->device();
        $original = $this->sell();
        $this->addOnTo($original, product: $this->product('Air mineral', flagged: false));

        $this->assertSame(['t'.$original->id], array_column($this->tickets($device), 'key'));
    }

    // #251 BR-6: a finished order is not linked, so there is nothing to mark
    public function test_add_on_to_a_completed_order_prints_as_a_normal_ticket(): void
    {
        $device = $this->device();
        $served = $this->sell();
        KitchenItem::where('transaction_id', $served->id)->update(['status' => KitchenItem::STATUS_SERVED]);
        $next = $this->addOnTo($served);

        $tickets = $this->tickets($device);

        $this->assertSame(['t'.$next->id], array_column($tickets, 'key'));
        $this->assertSame('order', $tickets[0]['kind']);
        $this->assertSame($next->id, $tickets[0]['root_id']);
        $this->assertSame($next->invoice, $tickets[0]['number']);
    }

    public function test_add_on_of_a_deleted_original_prints_as_a_normal_ticket(): void
    {
        $device = $this->device();
        $original = $this->onRecord('INV-GONE', withItem: false);
        $addOn = $this->onRecord('INV-LEFT', parent: $original);
        $original->delete();

        $ticket = $this->tickets($device)[0];

        $this->assertSame('order', $ticket['kind']);
        $this->assertSame($addOn->id, $ticket['root_id']);
        $this->assertSame('INV-LEFT', $ticket['number']);
    }

    // BR-2, TC-4: once per print device, on its own, whether or not the original has printed there
    public function test_add_on_is_not_offered_again_after_prints(): void
    {
        $first = $this->device('Dapur 1');
        $second = $this->device('Dapur 2');
        $original = $this->sell();
        $addOn = $this->addOnTo($original);
        $itemIds = KitchenItem::where('transaction_id', $addOn->id)->pluck('id')->all();

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $first), ['item_ids' => $itemIds])->assertNoContent();

        $this->assertSame(['t'.$original->id], array_column($this->tickets($first), 'key'));
        $this->assertSame(['t'.$original->id, 't'.$addOn->id], array_column($this->tickets($second), 'key'));

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $second), ['item_ids' => $itemIds])->assertNoContent();

        $this->assertSame(['t'.$original->id], array_column($this->tickets($second), 'key'));
    }

    // The kitchen knows the order by the original's number, so its DIBATALKAN ticket uses it too (#332)
    public function test_cancellation_ticket_of_an_add_on_item_shows_the_original_number(): void
    {
        $device = $this->device();
        $original = $this->onRecord('INV-ROOT', withItem: false);
        $addOn = $this->onRecord('INV-ADD', parent: $original);
        $item = KitchenItem::where('transaction_id', $addOn->id)->sole();
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$item->id]])->assertNoContent();
        $return = SalesReturn::create(['code' => 'RET-1', 'transaction_id' => $addOn->id, 'status' => 'completed', 'total_return_amount' => 0]);
        KitchenCancellation::create(['kitchen_item_id' => $item->id, 'sales_return_id' => $return->id, 'qty_cancelled' => 1]);

        $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk()
            ->assertJsonPath('cancellations.0.number', 'INV-ROOT');
    }

    private function tickets(KitchenPrintDevice $device): array
    {
        return $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk()->json('tickets');
    }

    /** A device named before the sales of the test (created_at is the cut-off). */
    private function device(string $name = 'Dapur 1'): KitchenPrintDevice
    {
        $device = new KitchenPrintDevice(['outlet_id' => $this->outlet->id, 'name' => $name]);
        $device->created_at = now()->subHour();
        $device->save();

        return $device;
    }

    private function product(string $title, bool $flagged = true): Product
    {
        $product = Product::create([
            'title' => $title, 'sku' => 'SKU-'.uniqid(), 'barcode' => 'BC-'.uniqid(), 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => $flagged,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 100]);

        return $product;
    }

    /** A POS checkout of one unit, paid in cash unless $payload says otherwise. */
    private function sell(array $payload = [], ?Product $product = null): Transaction
    {
        Cart::create(['cashier_id' => $this->cashier->id, 'product_id' => ($product ?? $this->nasi)->id, 'qty' => 1, 'price' => 10000]);
        $this->actingAs($this->cashier)->post(route('transactions.store'), $payload + ['cash' => 100000])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function addOnTo(Transaction $original, array $payload = [], ?Product $product = null): Transaction
    {
        return $this->sell($payload + ['add_on_to' => $original->invoice], $product ?? $this->teh);
    }

    /** A sale written straight to the database, for the shapes a POS checkout cannot make (a table order, a lone link). */
    private function onRecord(string $invoice, ?Transaction $parent = null, ?string $table = null, bool $withItem = true): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => $invoice,
            'parent_transaction_id' => $parent?->id, 'order_type' => 'in_store', 'cash' => 0, 'change' => 0, 'discount' => 0,
            'shipping_cost' => 0, 'grand_total' => 10000, 'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);

        if ($table) {
            DineOrder::create([
                'dine_table_id' => DiningTable::firstOrCreate(['name' => $table], ['is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
                'transaction_id' => $sale->id, 'status' => DineOrder::STATUS_ACCEPTED, 'payment_option' => DineOrder::PAY_AT_COUNTER,
                'subtotal' => 10000, 'item_count' => 1,
            ]);
        }

        if ($withItem) {
            $detail = $sale->details()->create([
                'transaction_id' => $sale->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'conversion_factor' => 1,
                'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
            ]);
            KitchenItem::create([
                'transaction_id' => $sale->id, 'transaction_detail_id' => $detail->id, 'product_id' => $this->nasi->id,
                'warehouse_id' => $this->warehouse->id, 'qty' => 1, 'status' => KitchenItem::STATUS_ACCEPTED, 'accepted_at' => now(),
            ]);
        }

        return $sale;
    }
}
