<?php

namespace Tests\Feature\Modifiers;

use App\Models\SalesReturn;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

// REQ-008 AC-14, decision 14 (#420): a return gives back the whole line with its options.
class ModifierReturnTest extends ModifierSaleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier->givePermissionTo(['sales-returns-access', 'sales-returns-create', 'sales-returns-complete']);
    }

    public function test_return_refunds_the_whole_line_and_restocks_the_recipe_as_sold(): void
    {
        $syrup = $this->product('Sirup', 0, 10, ['is_raw_material' => true]);
        $this->sell(['Large', 'Boba'], 3);
        // Changed, then deleted, since the sale: the return reads the sale's copy, never the recipe of today.
        $this->option('Large')->components()->sync([$this->milk->id => ['qty' => 5], $syrup->id => ['qty' => 1]]);
        $this->option('Large')->delete();
        $this->option('Boba')->delete();

        $this->post(route('sales-returns.complete', $this->draft(1)))->assertSessionHasNoErrors();

        // One portion is menu 20000 + Large 5000 + Boba 3000.
        $this->assertSame(28000, (int) SalesReturn::sole()->refund_amount);
        $this->assertSame([[48, 48], [6, 6], [8, 8], [10, 10]], $this->stocks($this->menu, $this->milk, $this->boba, $syrup));
        $mutation = StockMutation::where('product_id', $this->milk->id)->where('reference_type', 'sales_return')->sole();
        $this->assertSame(
            ['in', 2, 4, 6, $this->warehouse->id, SalesReturn::sole()->id],
            [$mutation->mutation_type, (int) $mutation->qty, (int) $mutation->stock_before, (int) $mutation->stock_after, (int) $mutation->warehouse_id, (int) $mutation->reference_id],
        );
    }

    public function test_return_without_restock_leaves_option_stock_taken(): void
    {
        $this->sell(['Large', 'Boba'], 2);

        $this->post(route('sales-returns.complete', $this->draft(2, restock: false)))->assertSessionHasNoErrors();

        $this->assertSame([[48, 48], [6, 6], [8, 8]], $this->stocks($this->menu, $this->milk, $this->boba));
        $this->assertSame(0, StockMutation::where('reference_type', 'sales_return')->count());
    }

    public function test_api_return_skips_a_deleted_ingredient_and_restocks_the_rest(): void
    {
        $this->sell(['Large', 'Boba'], 2);
        $this->option('Boba')->delete();
        $this->boba->delete();
        $draft = $this->draft(2);
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson(route('api.sales-returns.complete', $draft))->assertOk()
            ->assertJsonPath('data.items.0.modifiers.0.option_name', 'Large')
            ->assertJsonMissingPath('data.items.0.modifiers.0.components');

        $this->assertSame([[50, 50], [10, 10]], $this->stocks($this->menu, $this->milk));
    }

    // Two lines share the milk: each mutation starts where the one before it ended.
    public function test_lines_sharing_an_ingredient_record_a_continuous_stock_history(): void
    {
        $this->add(['Large']);
        $this->sell(['Large', 'Jelly']);
        $details = Transaction::sole()->details()->orderBy('id')->get();

        $this->post(route('sales-returns.complete', $this->draft(1, details: $details->all())))->assertSessionHasNoErrors();

        $this->assertSame([[6, 8], [8, 10]], StockMutation::where('product_id', $this->milk->id)->where('reference_type', 'sales_return')
            ->orderBy('id')->get()->map(fn (StockMutation $row) => [(int) $row->stock_before, (int) $row->stock_after])->all());
    }

    // SQLite shows the order only; that a second transaction waits on these rows is MySQL behaviour.
    public function test_return_locks_every_stock_row_in_product_order_before_restocking(): void
    {
        // Boba is chosen before Large here, so the lines themselves meet the higher product id first.
        $this->add(['Regular', 'Boba']);
        $this->sell(['Large']);
        $draft = $this->draft(1, details: Transaction::sole()->details()->orderBy('id')->get()->all());
        DB::enableQueryLog();

        $this->post(route('sales-returns.complete', $draft))->assertSessionHasNoErrors();

        $queries = collect(DB::getQueryLog())
            // MySQL quotes names with backticks, SQLite with double quotes.
            ->map(fn (array $query) => ['query' => strtr($query['query'], '`', '"')] + $query)
            ->takeUntil(fn (array $query) => str_starts_with($query['query'], 'update "product'))
            ->values();
        $ids = [$this->menu->id, $this->milk->id, $this->boba->id];
        $warehouse = $queries->search(fn (array $query) => str_starts_with($query['query'], 'select * from "product_warehouse"')
            && str_contains($query['query'], 'order by "product_id" asc'));
        $products = $queries->search(fn (array $query) => str_starts_with($query['query'], 'select * from "products"')
            && str_contains($query['query'], 'order by "id" asc'));

        $this->assertNotFalse($warehouse);
        $this->assertNotFalse($products);
        $this->assertLessThan($products, $warehouse);
        $this->assertSame($ids, array_slice($queries[$warehouse]['bindings'], 1));
    }

    public function test_return_page_and_api_list_the_sold_options_and_note(): void
    {
        $this->sell(['Large', 'Boba'], 1, 'es sedikit');
        $this->option('Large')->update(['name' => 'Jumbo']);
        $draft = $this->draft(1);

        $this->get(route('sales-returns.show', $draft))->assertInertia(fn (Assert $page) => $page
            ->where('transaction.details.0.modifiers.0.option_name', 'Large')
            ->where('transaction.details.0.modifiers.1.price_delta', 3000)
            ->where('transaction.details.0.note', 'es sedikit')
            ->where('salesReturn.items.0.modifiers.0.option_name', 'Large')
            ->missing('transaction.details.0.modifiers.0.components'));

        Sanctum::actingAs($this->cashier, ['*']);
        $this->getJson(route('api.sales-returns.show', $draft))->assertOk()
            ->assertJsonPath('data.items.0.modifiers.1.option_name', 'Boba')
            ->assertJsonPath('data.items.0.note', 'es sedikit')
            ->assertJsonMissingPath('data.items.0.modifiers.0.components');
    }

    private function sell(array $options, int $qty = 1, ?string $note = null): void
    {
        $this->add($options, $qty, $note)->assertSessionHasNoErrors();
        $this->checkout()->assertSessionHasNoErrors();
    }

    /** @param  list<TransactionDetail>|null  $details  defaults to the sale's only line */
    private function draft(int $qty, bool $restock = true, ?array $details = null): SalesReturn
    {
        $sale = Transaction::sole();
        $this->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
            'items' => array_map(fn (TransactionDetail $detail) => [
                'transaction_detail_id' => $detail->id,
                'qty_return' => $qty,
                'return_reason' => 'Salah pesan',
                'restock_to_inventory' => $restock,
            ], $details ?? [$sale->details()->sole()]),
        ])->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    /** @return list<array{0: int, 1: int}> */
    private function stocks(object ...$products): array
    {
        return array_map(fn ($product) => $this->stockOf($product), $products);
    }
}
