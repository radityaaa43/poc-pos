<?php

namespace Tests\Feature\SalesReturn;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\SalesReturn;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Stock effects of a sales return, web and API (#486). */
class ReturnStockTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = ['sales-returns-access', 'sales-returns-create', 'sales-returns-complete'];
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo($permissions);
        $this->warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Gudang 1', 'is_active' => true]);
        CashierShift::create([
            'user_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => 'open',
        ]);
    }

    public static function channels(): array
    {
        return ['web' => ['web'], 'api' => ['api']];
    }

    /**
     * The first completion commits while the second waits for the sale lock. Under MySQL's REPEATABLE READ the second
     * one's snapshot is older than that commit, so only locking reads taken after the sale lock see it. SQLite cannot
     * show the stale read: the test asserts the locks.
     */
    #[DataProvider('channels')]
    public function test_second_completion_sees_the_first_under_a_stale_snapshot(string $channel): void
    {
        $product = $this->product(stock: 10);
        $sale = $this->sale([[$product, 2]]);
        $first = $this->draft($sale, [2]);
        $second = $this->draft($sale, [2]);
        $this->assertCompleted($this->complete($first, $channel), $channel);

        $this->logLocks();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->complete($second, $channel);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql));
        DB::disableQueryLog();

        $this->assertRefused($response, $channel, 'Ada item retur yang melebihi sisa qty yang bisa diretur.');
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame(12, $product->fresh()->stock, 'restocked once');
        $this->assertSame(12, $this->warehouseStock($product), 'restocked once');
        $this->assertSame(1, StockMutation::where('reference_type', 'sales_return')->count());

        $saleLock = $queries->search(fn (string $sql) => preg_match('/^select \* from [`"]transactions[`"] where [`"]transactions[`"]\.[`"]id[`"] = \?.*for update/', $sql) === 1);
        $this->assertIsInt($saleLock);
        // Only the controller's queries come after the sale lock: the reads of the sale's completed returns.
        $checks = $queries->slice($saleLock)->filter(fn (string $sql) => preg_match('/sum\(qty_return\)|^select .* from [`"]sales_returns[`"] where .*[`"]status[`"] = \?/', $sql) === 1);
        $this->assertNotEmpty($checks);

        foreach ($checks as $sql) {
            $this->assertStringContainsString('for update', $sql);
        }
    }

    /** Milk is also sold on its own line, so one ingredient is restocked by two lines of the same return. */
    #[DataProvider('channels')]
    public function test_returned_recipe_menu_restocks_components(string $channel): void
    {
        [$menu, $beans, $milk] = $this->recipeMenu();
        $sale = $this->sale([[$menu, 3], [$milk, 5]]);
        $return = $this->draft($sale, [2, 1]);

        $this->logLocks();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertCompleted($this->complete($return, $channel), $channel);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql));
        DB::disableQueryLog();

        // 2 menus x 20 beans and x 150 milk, plus 1 milk of its own line.
        $this->assertSame(540, $beans->fresh()->stock);
        $this->assertSame(140, $this->warehouseStock($beans));
        $this->assertSame(2301, $milk->fresh()->stock);
        $this->assertSame(1101, $this->warehouseStock($milk));
        $this->assertSame(0, $menu->fresh()->stock, 'a recipe menu holds no stock of its own');
        $this->assertSame(0, $this->warehouseStock($menu));

        // A component records its warehouse stock, as its sale did; the plain line records the total, as before.
        $mutations = StockMutation::where('reference_type', 'sales_return')->orderBy('id')->get()
            ->map(fn (StockMutation $m) => [$m->product_id, $m->warehouse_id, $m->mutation_type, $m->qty, $m->stock_before, $m->stock_after])
            ->all();
        $this->assertEquals([
            [$beans->id, $this->warehouse->id, 'in', 40, 100, 140],
            [$milk->id, $this->warehouse->id, 'in', 300, 800, 1100],
            [$milk->id, $this->warehouse->id, 'in', 1, 2300, 2301],
        ], $mutations);

        // Checkout's lock order: the sale, then warehouse rows by product id, then products, then the first write.
        $position = fn (string $pattern) => $queries->search(fn (string $sql) => preg_match($pattern, $sql) === 1);
        $order = [
            $position('/^select \* from [`"]transactions[`"] where [`"]transactions[`"]\.[`"]id[`"] = \?.*for update/'),
            $position('/^select \* from [`"]product_warehouse[`"] where .*order by [`"]product_id[`"] asc.*for update/'),
            $position('/^select \* from [`"]products[`"] where .*order by [`"]id[`"] asc.*for update/'),
            $position('/^update [`"](product_warehouse|products)[`"]/'),
        ];
        $this->assertNotContains(false, $order);
        $this->assertSame(collect($order)->sort()->values()->all(), $order);
    }

    #[DataProvider('channels')]
    public function test_unticked_restock_changes_nothing(string $channel): void
    {
        [$menu, $beans, $milk] = $this->recipeMenu();
        $return = $this->draft($this->sale([[$menu, 3]]), [2], restock: false);

        $this->assertCompleted($this->complete($return, $channel), $channel);

        foreach ([[$menu, 0, 0], [$beans, 500, 100], [$milk, 2000, 800]] as [$product, $total, $inWarehouse]) {
            $this->assertSame($total, $product->fresh()->stock);
            $this->assertSame($inWarehouse, $this->warehouseStock($product));
        }
        $this->assertSame(0, StockMutation::where('reference_type', 'sales_return')->count());
    }

    /** @return array{Product, Product, Product} a recipe menu and its components: 20 beans and 150 milk each */
    private function recipeMenu(): array
    {
        $menu = $this->product(stock: 0, composite: true);
        $beans = $this->product(stock: 500, warehouseStock: 100);
        $milk = $this->product(stock: 2000, warehouseStock: 800);
        $menu->components()->attach([$beans->id => ['qty' => 20], $milk->id => ['qty' => 150]]);

        return [$menu, $beans, $milk];
    }

    /** SQLite drops the lock clause: write it as a comment, so the query log shows which reads lock. */
    private function logLocks(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::connection()->setQueryGrammar(new class(DB::connection()) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return '/* for update */';
            }
        });
    }

    /** A product with `$stock` in total and `$warehouseStock` (default: the same) in the sale's warehouse. */
    private function product(int $stock, ?int $warehouseStock = null, bool $composite = false): Product
    {
        $category = Category::firstOrCreate(['name' => 'Retur'], ['description' => 'Retur', 'image' => 'category.png']);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk '.Str::upper(Str::random(5)),
            'description' => 'Produk uji.',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => $stock,
            'tax_rate' => 0,
            'is_composite' => $composite,
        ]);

        ProductWarehouse::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => $warehouseStock ?? $stock,
        ]);

        return $product;
    }

    /** @param  list<array{Product, int}>  $lines  product and sold qty */
    private function sale(array $lines, ?Warehouse $warehouse = null): Transaction
    {
        $total = array_sum(array_map(fn (array $line) => 2000 * $line[1], $lines));
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => $total,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => $total,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        foreach ($lines as [$product, $qty]) {
            $sale->details()->create(['product_id' => $product->id, 'qty' => $qty, 'price' => 2000 * $qty]);
        }

        return $sale;
    }

    /** @param  list<int>  $quantities  per sale line, in line order */
    private function returnPayload(Transaction $sale, array $quantities, bool $restock = true): array
    {
        return [
            'return_type' => 'refund_cash',
            'items' => $sale->details()->orderBy('id')->get()->values()->map(fn ($detail, int $index) => [
                'transaction_detail_id' => $detail->id,
                'qty_return' => $quantities[$index],
                'return_reason' => 'Salah pesan',
                'restock_to_inventory' => $restock,
            ])->all(),
        ];
    }

    private function draft(Transaction $sale, array $quantities, bool $restock = true): SalesReturn
    {
        $this->actingAs($this->cashier)
            ->post(route('sales-returns.store', $sale), $this->returnPayload($sale, $quantities, $restock))
            ->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    private function complete(SalesReturn $return, string $channel): TestResponse
    {
        if ($channel === 'web') {
            return $this->actingAs($this->cashier)->post(route('sales-returns.complete', $return));
        }

        Sanctum::actingAs($this->cashier, ['*']);

        return $this->postJson(route('api.sales-returns.complete', $return));
    }

    private function assertCompleted(TestResponse $response, string $channel): void
    {
        $channel === 'web' ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertOk();
    }

    private function assertRefused(TestResponse $response, string $channel, string $message): void
    {
        $channel === 'web'
            ? $response->assertSessionHasErrors(['sales_return' => $message])
            : $response->assertUnprocessable()->assertJsonPath('errors.sales_return.0', $message);
    }

    private function warehouseStock(Product $product): int
    {
        return (int) ProductWarehouse::where(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id])->value('stock');
    }
}
