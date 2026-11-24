<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Profit;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\InventoryCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * REQ-002 AC-13 / AC-14: every sale line freezes its HPP (cost_total) from the selling
 * warehouse's average cost, whichever path created it.
 */
class FrozenSaleLineCostTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $plain;

    private Product $multi;

    private Product $recipe;

    private Unit $dus;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close', 'dine-orders-process', 'profits-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close', 'dine-orders-process']);

        $this->warehouse = Warehouse::create(['code' => 'WH-HPP', 'name' => 'Gudang HPP', 'status' => 'active']);
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);

        // Reference buy_price differs from the warehouse average on purpose: the average must win.
        $this->plain = $this->makeProduct($category, 'Plain', buyPrice: 5000, avg: 4000);
        $this->multi = $this->makeProduct($category, 'Multi', buyPrice: 500, avg: 300);
        $this->dus = Unit::create(['code' => 'DUS-HPP', 'name' => 'Dus', 'symbol' => 'dus']);
        $this->multi->units()->attach($this->dus->id, [
            'is_base' => false,
            'conversion_factor' => 12,
            'buy_price' => 6000,
            'sell_price' => 12000,
        ]);

        $componentA = $this->makeProduct($category, 'Bahan A', buyPrice: 150, avg: 100);
        $componentB = $this->makeProduct($category, 'Bahan B', buyPrice: 300, avg: 250);
        $this->recipe = $this->makeProduct($category, 'Menu Resep', buyPrice: 0, avg: null, composite: true);
        $this->recipe->components()->attach([
            $componentA->id => ['qty' => 2],
            $componentB->id => ['qty' => 3],
        ]);

        app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 0,
            notes: null,
            warehouseId: $this->warehouse->id,
        );
    }

    private function makeProduct(Category $category, string $title, int $buyPrice, ?int $avg, bool $composite = false): Product
    {
        self::$seq++;
        $product = Product::create([
            'title' => $title,
            'sku' => 'SKU-HPP-'.self::$seq,
            'barcode' => 'BC-HPP-'.self::$seq,
            'image' => '',
            'description' => '',
            'buy_price' => $buyPrice,
            'sell_price' => 10000,
            'stock' => 1000,
            'tax_rate' => 0,
            'category_id' => $category->id,
            'is_composite' => $composite,
        ]);

        if (! $composite) {
            $product->warehouses()->attach($this->warehouse->id, ['stock' => 1000, 'avg_cost' => $avg]);
        }

        return $product;
    }

    /** Sold qty 2 of each: plain 2 x 4000, multi 2 dus x 12 x 300, recipe 2 x (2 x 100 + 3 x 250). */
    private function assertFrozenCosts(Transaction $transaction): void
    {
        $costs = $transaction->details()->pluck('cost_total', 'product_id');

        $this->assertSame(8000, (int) $costs[$this->plain->id]);
        $this->assertSame(7200, (int) $costs[$this->multi->id]);
        $this->assertSame(1900, (int) $costs[$this->recipe->id]);
    }

    private function sellOnWeb(): Transaction
    {
        $this->addCarts();

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['discount' => 0, 'grand_total' => 0, 'cash' => 1000000, 'change' => 0])
            ->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function addCarts(): void
    {
        foreach ([[$this->plain, null, 1], [$this->multi, $this->dus, 12], [$this->recipe, null, 1]] as [$product, $unit, $factor]) {
            Cart::create([
                'cashier_id' => $this->cashier->id,
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $product->id,
                'unit_id' => $unit?->id,
                'conversion_factor' => $factor,
                'qty' => 2,
                'price' => 20000,
            ]);
        }
    }

    public function test_web_pos_sale_freezes_cost_from_warehouse_average(): void
    {
        $transaction = $this->sellOnWeb();

        $this->assertFrozenCosts($transaction);
        // Profit = net line price - cost_total, summed over the lines.
        $this->assertSame((int) $transaction->details()->sum('price') - 17100, (int) $transaction->profits()->sum('total'));
    }

    public function test_pos_api_checkout_freezes_cost_from_warehouse_average(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $this->addCarts();

        $this->postJson(route('api.pos.checkout'), ['cash' => 1000000])->assertSuccessful();

        $this->assertFrozenCosts(Transaction::latest('id')->firstOrFail());
    }

    public function test_offline_sync_freezes_cost_from_warehouse_average(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/transactions/sync', ['transactions' => [[
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'items' => [
                ['product_id' => $this->plain->id, 'qty' => 2, 'unit_id' => null],
                ['product_id' => $this->multi->id, 'qty' => 2, 'unit_id' => $this->dus->id],
                ['product_id' => $this->recipe->id, 'qty' => 2, 'unit_id' => null],
            ],
            'cash' => 1000000,
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'synced');

        $this->assertFrozenCosts(Transaction::latest('id')->firstOrFail());
    }

    public function test_accepted_dine_order_freezes_cost_from_warehouse_average(): void
    {
        $table = DiningTable::create(['name' => 'Meja 1', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        $this->post(route('dine-order.store', $table->token), [
            'payment_option' => 'pay_at_counter',
            'items' => [
                ['product_id' => $this->plain->id, 'qty' => 2],
                ['product_id' => $this->multi->id, 'unit_id' => $this->dus->id, 'qty' => 2],
                ['product_id' => $this->recipe->id, 'qty' => 2],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->cashier)
            ->post(route('dine-orders.accept', DineOrder::firstOrFail()))
            ->assertSessionHasNoErrors();

        $this->assertFrozenCosts(Transaction::latest('id')->firstOrFail());
    }

    public function test_past_sale_profit_does_not_change_after_a_later_receipt_at_another_price(): void
    {
        $transaction = $this->sellOnWeb();
        $profitBefore = (int) Profit::where('transaction_id', $transaction->id)->sum('total');
        $costsBefore = $transaction->details()->pluck('cost_total', 'product_id')->all();

        // A later receipt at a much higher price moves the warehouse average.
        app(InventoryCostService::class)->receive($this->plain, $this->warehouse->id, 1000, 9000);
        $this->assertNotEquals(4000.0, (float) ProductWarehouse::where(['product_id' => $this->plain->id, 'warehouse_id' => $this->warehouse->id])->value('avg_cost'));

        $this->assertSame($costsBefore, $transaction->details()->pluck('cost_total', 'product_id')->all());

        $admin = User::factory()->create();
        $admin->givePermissionTo('profits-access');
        $this->actingAs($admin)
            ->get(route('reports.profits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.profit_total', $profitBefore));
    }

    public function test_backfill_estimates_existing_lines_from_reference_buy_price(): void
    {
        $transaction = $this->sellOnWeb();

        $migration = require database_path('migrations/2026_11_25_700001_add_cost_total_to_transaction_details_table.php');
        $migration->down();
        $migration->up();

        $costs = $transaction->details()->pluck('cost_total', 'product_id');
        $this->assertSame(10000, (int) $costs[$this->plain->id]); // 5000 x 2
        $this->assertSame(12000, (int) $costs[$this->multi->id]); // 500 x 2 dus x 12
        $this->assertSame(2400, (int) $costs[$this->recipe->id]); // (150 x 2 + 300 x 3) x 2
    }
}
