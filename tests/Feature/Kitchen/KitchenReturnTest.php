<?php

namespace Tests\Feature\Kitchen;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KitchenItemService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

// #241: completing a sales return cancels the kitchen item (a partial return lowers its qty); the restock default follows its status.
class KitchenReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $cook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // refusals render the 403 error page
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create(['outlet_id' => $this->outlet->id, 'code' => 'WH-A', 'name' => 'Gudang A', 'type' => 'branch', 'is_active' => true]);
        $this->cashier = $this->userWith(['sales-returns-access', 'sales-returns-create', 'sales-returns-complete', 'transactions-access']);
        $this->cook = $this->userWith(['dine-orders-prepare']);
        CashierShift::create([
            'user_id' => $this->cashier->id, 'opened_by' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash' => 100000, 'expected_cash' => 100000, 'status' => 'open',
        ]);
    }

    public function test_full_return_cancels_the_kitchen_item(): void
    {
        [$sale, $detail, $item] = $this->sale();

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_completed_return_cancels_kitchen_items(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2);
        $return = $this->draft($sale, $detail, 2);

        $this->completeReturn($return);

        $this->assertSame('completed', $return->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_draft_return_does_not_cancel_kitchen_items(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2);

        $return = $this->draft($sale, $detail, 2);
        $this->actingAs($this->cashier)->patch(route('sales-returns.update', $return), [
            'return_type' => 'refund_cash',
            'items' => [['transaction_detail_id' => $detail->id, 'qty_return' => 1, 'return_reason' => 'Salah pesan', 'restock_to_inventory' => true]],
        ])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
        $this->assertSame(2, $item->qty);
    }

    public function test_cancelled_item_shows_two_minutes_then_leaves_the_display(): void
    {
        [$sale, $detail] = $this->sale();
        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.items.0.status', KitchenItem::STATUS_CANCELLED));

        $this->travel(3)->minutes();

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders', []));
    }

    public function test_partial_return_lowers_qty_and_keeps_status(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 3);

        $this->completeReturn($this->draft($sale, $detail, 1));

        $item->refresh();
        $this->assertSame(2, $item->qty);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
    }

    public function test_second_partial_return_cancels_when_qty_hits_zero(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 3);

        $this->completeReturn($this->draft($sale, $detail, 1));
        $this->completeReturn($this->draft($sale, $detail, 2));

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_return_form_defaults_restock_on_for_accepted_off_for_preparing_and_ready(): void
    {
        $expected = [
            KitchenItem::STATUS_ACCEPTED => true,
            KitchenItem::STATUS_PREPARING => false,
            KitchenItem::STATUS_READY => false,
            KitchenItem::STATUS_SERVED => true,
        ];

        foreach ($expected as $status => $restock) {
            [$sale] = $this->sale(status: $status);

            $this->actingAs($this->cashier)->get(route('sales-returns.create', $sale))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('transaction.details.0.kitchen_status', $status)
                    ->where('transaction.details.0.restock_default', $restock));
        }

        [$plain] = $this->sale(withKitchenItem: false);
        $this->actingAs($this->cashier)->get(route('sales-returns.create', $plain))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transaction.details.0.kitchen_status', null)
                ->where('transaction.details.0.restock_default', true));
    }

    public function test_saved_draft_keeps_its_own_restock_choice_and_the_api_form_carries_the_default(): void
    {
        [$sale, $detail] = $this->sale(status: KitchenItem::STATUS_PREPARING);
        $return = $this->draft($sale, $detail, 1, restock: true);

        $this->actingAs($this->cashier)->get(route('sales-returns.show', $return))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transaction.details.0.draft_item.restock_to_inventory', true)
                ->where('transaction.details.0.restock_default', false));

        Sanctum::actingAs($this->cashier, ['*']);
        $this->getJson(route('api.sales-returns.create', $sale))
            ->assertOk()
            ->assertJsonPath('data.details.0.kitchen_status', KitchenItem::STATUS_PREPARING)
            ->assertJsonPath('data.details.0.restock_default', false);
    }

    public function test_chosen_restock_value_is_what_is_stored(): void
    {
        [$kept, $keptDetail, , $keptProduct] = $this->sale(qty: 2, status: KitchenItem::STATUS_PREPARING);
        [$back, $backDetail, , $backProduct] = $this->sale(qty: 2, status: KitchenItem::STATUS_PREPARING);

        $this->completeReturn($this->draft($kept, $keptDetail, 2, restock: false));
        $this->completeReturn($this->draft($back, $backDetail, 2, restock: true));

        $this->assertSame(10, $keptProduct->fresh()->stock);
        $this->assertSame(10, ProductWarehouse::where('product_id', $keptProduct->id)->value('stock'));
        $this->assertSame(12, $backProduct->fresh()->stock);
        $this->assertSame(12, ProductWarehouse::where('product_id', $backProduct->id)->value('stock'));
    }

    public function test_returning_a_served_item_leaves_the_kitchen_row_alone(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2, status: KitchenItem::STATUS_SERVED);
        $before = $item->fresh()->only('status', 'qty', 'updated_at');

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertEquals($before, $item->fresh()->only('status', 'qty', 'updated_at'));
    }

    public function test_return_of_a_line_without_a_kitchen_item_completes(): void
    {
        [$sale, $detail] = $this->sale(withKitchenItem: false);

        $return = $this->draft($sale, $detail, 1);
        $this->completeReturn($return);

        $this->assertSame('completed', $return->fresh()->status);
    }

    public function test_refund_and_receivable_follow_the_existing_return_rules_with_kitchen_items(): void
    {
        [$cash, $cashDetail] = $this->sale(qty: 3);
        $cashReturn = $this->draft($cash, $cashDetail, 1);
        $this->completeReturn($cashReturn);
        $this->assertSame(10000, $cashReturn->fresh()->refund_amount);

        [$later, $laterDetail] = $this->sale(qty: 3, paymentMethod: 'pay_later');
        $receivable = Receivable::create([
            'transaction_id' => $later->id, 'invoice' => $later->invoice, 'total' => 30000, 'paid' => 0,
            'due_date' => now()->addDays(7), 'status' => 'unpaid',
        ]);
        $laterReturn = $this->draft($later, $laterDetail, 1);
        $this->completeReturn($laterReturn);

        $this->assertEquals(20000, $receivable->fresh()->total);
        $this->assertSame(0, $laterReturn->fresh()->refund_amount);
    }

    public function test_user_without_return_permission_cannot_cancel_kitchen_items(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $return = $this->draft($sale, $detail, 1);
        $viewer = $this->userWith(['sales-returns-access']);
        $drafter = $this->userWith(['sales-returns-access', 'sales-returns-create']);

        $this->actingAs($viewer)->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
            'items' => [['transaction_detail_id' => $detail->id, 'qty_return' => 1, 'return_reason' => 'x']],
        ])->assertForbidden();
        // creating the draft is not enough: only completing the return cancels the item
        $this->actingAs($drafter)->post(route('sales-returns.complete', $return))->assertForbidden();

        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    public function test_no_other_route_removes_a_kitchen_item(): void
    {
        // Routes that act on a kitchen item: one that binds a KitchenItem, or lives under kitchen/items. Station and print routes are not items.
        $kitchenRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => $route->signatureParameters(['subClass' => KitchenItem::class]) !== []
            || str_contains($route->uri(), 'kitchen/items'));

        $this->assertNotEmpty($kitchenRoutes);

        foreach ($kitchenRoutes as $route) {
            $this->assertEmpty(array_intersect($route->methods(), ['DELETE', 'PUT', 'PATCH']), $route->uri());
            $this->assertDoesNotMatchRegularExpression('/cancel|delete|destroy|remove/', $route->uri().$route->getName());
        }
    }

    public function test_start_on_a_cancelled_item_says_sudah_dibatalkan(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))
            ->assertSessionHasErrors(['item' => 'Sudah dibatalkan']);
        $this->actingAs($this->cook)->post(route('kitchen.items.ready', $item))
            ->assertSessionHasErrors(['item' => 'Sudah dibatalkan']);

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_return_after_start_cancels_a_preparing_item(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))->assertSessionHasNoErrors();
        $this->assertSame(KitchenItem::STATUS_PREPARING, $item->fresh()->status);

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_cancelled_rows_are_not_in_open_scope(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertFalse(KitchenItem::open()->whereKey($item->id)->exists());
        $this->assertTrue(KitchenItem::onDisplay()->whereKey($item->id)->exists());

        $this->travel(3)->minutes();

        $this->assertFalse(KitchenItem::onDisplay()->whereKey($item->id)->exists());
    }

    public function test_cancelled_item_does_not_block_all_ready(): void
    {
        [$sale, $detail] = $this->sale(table: 'Meja 1');
        $second = $this->line($sale);
        $keep = $this->kitchenItem($sale, $second);
        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $keep))->assertSessionHasNoErrors();
        $this->actingAs($this->cook)->post(route('kitchen.items.ready', $keep))->assertSessionHasNoErrors();

        $this->assertSame(DineOrder::STATUS_READY, $sale->dineOrder->fresh()->status);
    }

    public function test_dine_order_with_every_item_cancelled_is_cancelled(): void
    {
        [$sale, $detail] = $this->sale(table: 'Meja 2');
        $second = $this->line($sale);
        $this->kitchenItem($sale, $second);

        $this->completeReturn($this->draft($sale, $detail, 1));
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $sale->dineOrder->fresh()->status);

        $this->completeReturn($this->draft($sale, $second, 1));
        $this->assertSame(DineOrder::STATUS_CANCELLED, $sale->dineOrder->fresh()->status);
    }

    public function test_dine_order_with_a_served_item_completes_after_the_rest_is_cancelled(): void
    {
        [$sale, $detail] = $this->sale(table: 'Meja 3');
        $this->kitchenItem($sale, $this->line($sale), KitchenItem::STATUS_SERVED);

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertSame(DineOrder::STATUS_COMPLETED, $sale->dineOrder->fresh()->status);
    }

    /** BR-11 (Business, 2026-11-26): returning only the dish cancels its kitchen item, but the order still owes the plain line. */
    public function test_dine_order_with_a_plain_line_left_completes_when_its_dish_is_returned(): void
    {
        [$sale, $detail, $item] = $this->sale(table: 'Meja 4');
        $this->line($sale); // a packaged line: it has no kitchen item

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
        $this->assertSame(DineOrder::STATUS_COMPLETED, $sale->dineOrder->fresh()->status);
    }

    public function test_dine_order_is_cancelled_when_every_line_including_the_plain_one_is_returned_together(): void
    {
        [$sale, $detail, $item] = $this->sale(table: 'Meja 6');
        $drink = $this->line($sale);

        $this->completeReturn($this->draftLines($sale, [[$detail, 1], [$drink, 1]]));

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
        $this->assertSame(DineOrder::STATUS_CANCELLED, $sale->dineOrder->fresh()->status);
    }

    // Pinned (Business decision on #241, 2026-11-26): completed is the end state of an order that was served and paid, so returning
    // its last line later records a refund but does not reopen the order. BR-11 applies to orders not yet completed.
    public function test_a_completed_dine_order_stays_completed_when_its_last_plain_line_is_returned_later(): void
    {
        [$sale, $detail, $item] = $this->sale(table: 'Meja 8');
        $drink = $this->line($sale);

        $this->completeReturn($this->draft($sale, $detail, 1));
        $this->assertSame(DineOrder::STATUS_COMPLETED, $sale->dineOrder->fresh()->status);

        $this->completeReturn($this->draft($sale, $drink, 1));

        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
        $this->assertSame(DineOrder::STATUS_COMPLETED, $sale->dineOrder->fresh()->status);
        $this->assertSame(2, SalesReturn::where('transaction_id', $sale->id)->where('status', 'completed')->count());
    }

    public function test_a_partial_return_of_the_only_line_does_not_cancel_the_dine_order(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2, table: 'Meja 7');

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertSame(1, $item->fresh()->qty);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $sale->dineOrder->fresh()->status);
    }

    public function test_an_already_served_dine_order_stays_served_when_its_ready_items_are_returned(): void
    {
        [$sale, $detail] = $this->sale(table: 'Meja 5', status: KitchenItem::STATUS_READY);
        $sale->dineOrder->update(['status' => DineOrder::STATUS_SERVED, 'served_at' => now()]);

        $this->completeReturn($this->draft($sale, $detail, 1));

        $this->assertSame(DineOrder::STATUS_SERVED, $sale->dineOrder->fresh()->status);
    }

    public function test_a_failing_kitchen_hook_rolls_the_whole_return_back(): void
    {
        [$sale, $detail, $item, $product] = $this->sale(qty: 2);
        $return = $this->draft($sale, $detail, 2);
        $this->mock(KitchenItemService::class)->shouldReceive('cancelForReturn')->andThrow(new RuntimeException('kitchen down'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->cashier)->post(route('sales-returns.complete', $return));
            $this->fail('the return completed although the kitchen hook failed');
        } catch (RuntimeException $exception) {
            $this->assertSame('kitchen down', $exception->getMessage());
        }

        // Nothing moved: the return is still a draft, the kitchen item and the stock are as they were.
        $this->assertSame('draft', $return->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(2, $item->fresh()->qty);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(10, ProductWarehouse::where('product_id', $product->id)->value('stock'));
        $this->assertDatabaseCount('kitchen_cancellations', 0);
    }

    public function test_api_complete_return_also_cancels_the_kitchen_item(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2);
        $return = $this->draft($sale, $detail, 1);

        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson(route('api.sales-returns.complete', $return))->assertOk();
        $this->assertSame(1, $item->fresh()->qty);

        $second = $this->draft($sale, $detail, 1);
        $this->postJson(route('api.sales-returns.complete', $second))->assertOk();
        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach($this->outlet->id, ['is_default' => true]);

        return $user;
    }

    private function product(): Product
    {
        $product = Product::create([
            'title' => 'Menu '.++$this->seq, 'sku' => 'SKU-'.$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 10, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => true,
        ]);
        ProductWarehouse::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'stock' => 10]);

        return $product;
    }

    /** A line on the sale; the sale's qty is the line's, so only the first line sets the sale total. */
    private function line(Transaction $sale, int $qty = 1): TransactionDetail
    {
        $product = $this->product();
        $detail = TransactionDetail::create([
            'transaction_id' => $sale->id, 'product_id' => $product->id, 'qty' => $qty, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000 * $qty, 'discount_total' => 0, 'cost_total' => 5000 * $qty,
        ]);
        $sale->profits()->create(['total' => 5000 * $qty]);

        return $detail;
    }

    private function kitchenItem(Transaction $sale, TransactionDetail $detail, string $status = KitchenItem::STATUS_ACCEPTED): KitchenItem
    {
        return KitchenItem::create([
            'transaction_id' => $sale->id, 'transaction_detail_id' => $detail->id, 'product_id' => $detail->product_id,
            'warehouse_id' => $this->warehouse->id, 'qty' => $detail->qty, 'status' => $status, 'accepted_at' => now(),
        ]);
    }

    /** @return array{Transaction, TransactionDetail, ?KitchenItem, Product} a paid sale of one line, with its kitchen item as intake would create it */
    private function sale(int $qty = 1, string $status = KitchenItem::STATUS_ACCEPTED, bool $withKitchenItem = true, ?string $table = null, string $paymentMethod = 'cash'): array
    {
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'INV-'.++$this->seq,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000 * $qty,
            'payment_method' => $paymentMethod, 'payment_status' => $paymentMethod === 'cash' ? 'paid' : 'unpaid',
        ]);
        $detail = $this->line($sale, $qty);

        if ($table) {
            DineOrder::create([
                'dine_table_id' => DiningTable::create(['name' => $table, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
                'transaction_id' => $sale->id, 'status' => DineOrder::STATUS_ACCEPTED, 'payment_option' => DineOrder::PAY_AT_COUNTER,
                'subtotal' => 10000 * $qty, 'item_count' => 1,
            ]);
        }

        return [$sale->load('dineOrder'), $detail, $withKitchenItem ? $this->kitchenItem($sale, $detail, $status) : null, $detail->product];
    }

    private function draft(Transaction $sale, TransactionDetail $detail, int $qty, bool $restock = true): SalesReturn
    {
        $this->actingAs($this->cashier)->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
            'items' => [['transaction_detail_id' => $detail->id, 'qty_return' => $qty, 'return_reason' => 'Salah pesan', 'restock_to_inventory' => $restock]],
        ])->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    /** @param  list<array{TransactionDetail, int}>  $lines one draft return over several lines of the sale */
    private function draftLines(Transaction $sale, array $lines): SalesReturn
    {
        $this->actingAs($this->cashier)->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
            'items' => array_map(fn (array $line) => [
                'transaction_detail_id' => $line[0]->id, 'qty_return' => $line[1], 'return_reason' => 'Salah pesan', 'restock_to_inventory' => true,
            ], $lines),
        ])->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    private function completeReturn(SalesReturn $return): void
    {
        $this->actingAs($this->cashier)->post(route('sales-returns.complete', $return))->assertSessionHasNoErrors();
    }
}
