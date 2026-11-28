<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Setting;
use App\Models\StockMutation;
use App\Models\StockWaste;
use App\Models\StockWasteMove;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REQ-011 AC-01, 02, 03, 04, 13: recording waste of raw materials and recipe menus.
 */
class StockWasteRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Warehouse $other;

    private Product $beans;

    private Product $milk;

    private Product $latte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);
        $this->warehouse = $this->warehouseOf($this->outlet, 'MAL');
        $this->other = $this->warehouseOf(Outlet::create(['code' => 'PUT', 'name' => 'Puter']), 'PUT');
        $this->staff = $this->userWith('stock-waste-create');

        $this->beans = $this->product('Biji Kopi', ['is_raw_material' => true], stock: 1000, avg: 250);
        $this->milk = $this->product('Susu', ['is_raw_material' => true], stock: 2000, avg: 20);
        $this->latte = $this->menu('Latte', [[$this->beans, 18], [$this->milk, 150]]);
    }

    private function warehouseOf(Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => "Gudang $code", 'type' => 'branch', 'is_active' => true, 'sort_order' => 1, 'outlet_id' => $outlet->id]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach($this->outlet->id, ['is_default' => true]);

        return $user;
    }

    private function product(string $title, array $attributes = [], ?int $stock = null, ?float $avg = null): Product
    {
        $code = Str::upper(Str::random(8));
        $product = Product::create([
            'title' => $title, 'sku' => $code, 'barcode' => $code, 'buy_price' => 100, 'sell_price' => 0, 'stock' => $stock ?? 0,
            'image' => 'x.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => Category::firstOrCreate(['name' => 'Bahan'], ['description' => 'x'])->id,
            ...$attributes,
        ]);
        if ($stock !== null) {
            $product->warehouses()->attach($this->warehouse->id, ['stock' => $stock, 'avg_cost' => $avg]);
        }

        return $product;
    }

    /** @param  array<int, array{0: Product, 1: float}>  $recipe */
    private function menu(string $title, array $recipe): Product
    {
        $menu = $this->product($title, ['is_composite' => true]);
        foreach ($recipe as [$component, $qty]) {
            $menu->components()->attach($component->id, ['qty' => $qty]);
        }

        return $menu;
    }

    /** @param  array<int, array{0: Product, 1: int|float}>  $lines */
    private function record(array $lines, array $overrides = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->staff)->post(route('stock-waste.store'), [
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'damaged',
            'note' => 'Tumpah',
            'client_key' => (string) Str::uuid(),
            'lines' => array_map(fn (array $l) => ['product_id' => $l[0]->id, 'qty' => $l[1]], $lines),
            ...$overrides,
        ]);
    }

    /** Puts every record of the test on the path that takes the stock at once. */
    private function raiseLimit(): void
    {
        Setting::setForOutlet('waste_approval_limit', 10_000_000, $this->outlet);
    }

    /** The warehouse stock, after checking the invariant products.stock = sum of the warehouse rows. */
    private function stock(Product $product): int
    {
        $this->assertSame((int) ProductWarehouse::where('product_id', $product->id)->sum('stock'), $product->fresh()->stock);

        return (int) ProductWarehouse::where(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id])->value('stock');
    }

    private function assertRefused(TestResponse $response, string $field, string $key, array $replace = []): void
    {
        $response->assertSessionHasErrors([$field => __("messages.waste.$key", $replace)]);
        $this->assertDatabaseCount('stock_wastes', 0);
        $this->assertDatabaseCount('stock_waste_lines', 0);
        $this->assertDatabaseCount('stock_mutations', 0);
    }

    public function test_plain_waste_is_recorded_and_takes_stock_from_both_tables(): void
    {
        // A second warehouse holds the same material, so products.stock is not the pivot figure.
        $this->beans->warehouses()->attach($this->other->id, ['stock' => 500, 'avg_cost' => 900]);
        $this->beans->update(['stock' => 1500]);

        $this->record([[$this->beans, 100]])->assertSessionHasNoErrors()->assertRedirect(route('stock-waste.index'));

        $waste = StockWaste::with('moves', 'lines')->sole();
        $this->assertSame(['recorded', 25000, 'damaged', 'Tumpah', $this->staff->id], [$waste->status, $waste->total_value, $waste->reason, $waste->note, $waste->created_by]);
        $this->assertNotNull($waste->recorded_at);
        $this->assertSame([[$this->beans->id, 100, 250.0, 25000]], $waste->moves->map(fn ($m) => [$m->product_id, $m->qty, (float) $m->unit_cost, $m->value])->all());
        $this->assertSame($waste->lines->sole()->id, $waste->moves->sole()->line_id);
        $this->assertSame(900, $this->stock($this->beans));
        $this->assertSame(1400, $this->beans->fresh()->stock);
        $this->assertSame(250.0, (float) ProductWarehouse::where(['product_id' => $this->beans->id, 'warehouse_id' => $this->warehouse->id])->value('avg_cost'));

        $mutation = StockMutation::sole();
        $this->assertSame(['stock_waste', $waste->id, 'out', 100, 1000, 900], [$mutation->reference_type, (int) $mutation->reference_id, $mutation->mutation_type, (int) $mutation->qty, (int) $mutation->stock_before, (int) $mutation->stock_after]);
        $this->assertSame(1, AuditLog::where('event', 'stock.adjusted')->count());
        $this->assertSame('recorded', AuditLog::where('event', 'stock_waste.recorded')->sole()->meta['outcome']);
    }

    public function test_menu_waste_takes_the_recipe_ingredients_and_sums_their_cost(): void
    {
        $this->record([[$this->latte, 2]])->assertSessionHasNoErrors();

        // 36 g x 250 + 300 ml x 20
        $this->assertSame(15000, StockWaste::sole()->total_value);
        $this->assertSame([9000, 6000], StockWasteMove::orderBy('id')->pluck('value')->all());
        $this->assertSame([964, 1700], [$this->stock($this->beans), $this->stock($this->milk)]);
    }

    public function test_plain_waste_above_stock_is_refused_naming_item_and_stock_left(): void
    {
        $this->raiseLimit();

        $this->assertRefused($this->record([[$this->beans, 1001]]), 'stock', 'insufficient', ['item' => 'Biji Kopi', 'left' => 1000]);
        $this->assertSame(1000, $this->stock($this->beans));
    }

    public function test_menu_waste_is_refused_when_a_component_is_short(): void
    {
        $this->raiseLimit();

        $this->assertRefused($this->record([[$this->latte, 14]]), 'stock', 'insufficient', ['item' => 'Susu', 'left' => 2000]);
        $this->assertSame([1000, 2000], [$this->stock($this->beans), $this->stock($this->milk)]);
    }

    public function test_demand_is_summed_per_product_across_lines(): void
    {
        $this->raiseLimit();

        // 990 g typed plus 18 g inside a latte is 1008 g of 1000.
        $this->assertRefused($this->record([[$this->beans, 990], [$this->latte, 1]]), 'stock', 'insufficient', ['item' => 'Biji Kopi', 'left' => 1000]);
    }

    public function test_item_without_a_warehouse_row_is_refused_as_zero_stock(): void
    {
        $sugar = $this->product('Gula', ['is_raw_material' => true]);

        $this->assertRefused($this->record([[$sugar, 1]]), 'stock', 'insufficient', ['item' => 'Gula', 'left' => 0]);
    }

    public function test_value_equal_to_the_limit_is_recorded(): void
    {
        $this->record([[$this->beans, 400]])->assertSessionHasNoErrors();

        $this->assertSame(['recorded', 100000], [StockWaste::sole()->status, StockWaste::sole()->total_value]);
        $this->assertSame(600, $this->stock($this->beans));
    }

    public function test_value_over_the_limit_is_pending_and_stock_untouched(): void
    {
        $this->record([[$this->beans, 401]])->assertSessionHasNoErrors()->assertSessionHas('success', __('messages.waste.pending'));

        $waste = StockWaste::sole();
        $this->assertSame(['pending', 100250, null], [$waste->status, $waste->total_value, $waste->recorded_at]);
        $this->assertDatabaseCount('stock_waste_lines', 1);
        $this->assertDatabaseCount('stock_waste_moves', 0);
        $this->assertDatabaseCount('stock_mutations', 0);
        $this->assertSame(1000, $this->stock($this->beans));
        $this->assertSame('pending', AuditLog::where('event', 'stock_waste.recorded')->sole()->meta['outcome']);
    }

    public function test_a_short_pending_waste_is_refused_too(): void
    {
        $this->assertRefused($this->record([[$this->beans, 5000]]), 'stock', 'insufficient', ['item' => 'Biji Kopi', 'left' => 1000]);
    }

    public function test_the_outlet_limit_overrides_the_default(): void
    {
        Setting::setForOutlet('waste_approval_limit', 1000, $this->outlet);

        $this->record([[$this->beans, 5]])->assertSessionHasNoErrors();

        $this->assertSame('pending', StockWaste::sole()->status);
    }

    public function test_a_component_rounding_to_zero_is_skipped(): void
    {
        $menu = $this->menu('Taburan', [[$this->beans, 0.2], [$this->milk, 10]]);

        $this->record([[$menu, 2]])->assertSessionHasNoErrors();

        $this->assertSame([$this->milk->id], StockWasteMove::pluck('product_id')->all());
        $this->assertSame(400, StockWaste::sole()->total_value);
        $this->assertSame(1000, $this->stock($this->beans));
    }

    public function test_a_menu_with_nothing_to_take_is_refused(): void
    {
        $menu = $this->menu('Aroma', [[$this->beans, 0.2]]);

        $this->assertRefused($this->record([[$menu, 1]]), 'lines.0.qty', 'nothing_to_take', ['item' => 'Aroma']);
    }

    public function test_a_retail_product_is_refused_on_the_server(): void
    {
        $retail = $this->product('Botol', stock: 5);

        $this->assertRefused($this->record([[$this->beans, 1], [$retail, 1]]), 'lines.1.product_id', 'item_not_allowed', ['item' => 'Botol']);
    }

    public function test_a_menu_without_a_recipe_is_refused(): void
    {
        $this->assertRefused($this->record([[$this->menu('Kosong', []), 1]]), 'lines.0.product_id', 'no_recipe', ['item' => 'Kosong']);
    }

    public function test_cost_falls_back_to_central_then_buy_price_and_rounds_to_rupiah(): void
    {
        $central = $this->warehouseOf(Outlet::create(['code' => 'HQ', 'name' => 'Pusat', 'is_sales_enabled' => false]), 'HQ');
        $sugar = $this->product('Gula', ['is_raw_material' => true], stock: 10);
        $sugar->warehouses()->attach($central->id, ['stock' => 0, 'avg_cost' => 33.3333]);
        $salt = $this->product('Garam', ['is_raw_material' => true, 'buy_price' => 7], stock: 10);

        $this->record([[$sugar, 7], [$salt, 3]])->assertSessionHasNoErrors();

        $this->assertSame([233, 21], StockWasteMove::orderBy('id')->pluck('value')->all());
        $this->assertSame(254, StockWaste::sole()->total_value);
    }

    public function test_the_same_client_key_records_once(): void
    {
        $key = ['client_key' => (string) Str::uuid()];

        $this->record([[$this->beans, 10]], $key)->assertSessionHasNoErrors();
        $this->record([[$this->beans, 10]], $key)->assertSessionHasNoErrors()->assertRedirect(route('stock-waste.index'));

        $this->assertDatabaseCount('stock_wastes', 1);
        $this->assertDatabaseCount('stock_waste_moves', 1);
        $this->assertSame(990, $this->stock($this->beans));
    }

    public static function invalidInput(): array
    {
        $line = fn (mixed $qty) => ['lines' => [['qty' => $qty]]];

        return [
            'zero qty' => [$line(0), 'lines.0.qty'],
            'negative qty' => [$line(-1), 'lines.0.qty'],
            'decimal qty' => [$line(1.5), 'lines.0.qty'],
            'qty above a million' => [$line(1000001), 'lines.0.qty'],
            'no lines' => [['lines' => []], 'lines'],
            '51 lines' => [['lines' => array_fill(0, 51, ['qty' => 1])], 'lines'],
            'unknown reason' => [['reason' => 'stolen'], 'reason'],
            'long note' => [['note' => str_repeat('a', 501)], 'note'],
            'no client key' => [['client_key' => null], 'client_key'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(array $input, string $field): void
    {
        if (isset($input['lines'])) {
            $input['lines'] = array_map(fn (array $line) => [...$line, 'product_id' => $this->beans->id], $input['lines']);
        }

        $this->record([[$this->beans, 1]], $input)->assertSessionHasErrors($field);

        $this->assertDatabaseCount('stock_wastes', 0);
    }

    public function test_an_inactive_warehouse_is_refused(): void
    {
        $this->warehouse->update(['is_active' => false]);

        $this->record([[$this->beans, 1]])->assertSessionHasErrors('warehouse_id');
        $this->assertDatabaseCount('stock_wastes', 0);
    }

    public function test_a_user_cannot_record_in_a_warehouse_outside_their_outlets(): void
    {
        $this->record([[$this->beans, 1]], ['warehouse_id' => $this->other->id])->assertForbidden();

        $this->assertDatabaseCount('stock_wastes', 0);
    }

    public function test_a_user_without_the_create_permission_gets_403(): void
    {
        $nobody = $this->userWith();
        $this->record([[$this->beans, 1]], user: $nobody)->assertForbidden();
        $this->get(route('stock-waste.index'))->assertForbidden();
        $this->get(route('stock-waste.create'))->assertForbidden();

        $approver = $this->userWith('stock-waste-approve');
        $this->record([[$this->beans, 1]], user: $approver)->assertForbidden();
        $this->get(route('stock-waste.create'))->assertForbidden();
        $this->get(route('stock-waste.index'))->assertOk();
        $this->assertDatabaseCount('stock_wastes', 0);
    }

    public function test_the_create_page_offers_only_raw_materials_and_recipes_with_their_costs(): void
    {
        $this->product('Botol', stock: 5);
        $this->menu('Kosong', []);

        $this->actingAs($this->staff)->get(route('stock-waste.create'))->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/StockWaste/Create')
            ->where('warehouseId', $this->warehouse->id)
            ->has('warehouses', 1)
            ->has('items', 3)
            ->where('items.0.title', 'Biji Kopi')
            ->where('items.0.stock', 1000)
            ->where('items.0.parts', [[250, 1]])
            ->where('items.1.title', 'Latte')
            ->where('items.1.stock', null)
            ->where('items.1.parts', [[250, 18], [20, 150]]));
    }

    public function test_the_list_is_limited_to_the_users_warehouses(): void
    {
        $this->record([[$this->beans, 1]])->assertSessionHasNoErrors();
        StockWaste::create(['warehouse_id' => $this->other->id, 'status' => 'pending', 'reason' => 'other']);

        $this->actingAs($this->staff)->get(route('stock-waste.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/StockWaste/Index')
            ->has('wastes.data', 1)
            ->where('wastes.data.0.warehouse.id', $this->warehouse->id));
        $this->get(route('stock-waste.index', ['status' => 'pending']))->assertInertia(fn (Assert $page) => $page->has('wastes.data', 0));
    }

    public static function waste(): array
    {
        // 401 g is above the limit: a pending record has lines but no moves.
        return ['recorded' => [10], 'pending' => [401]];
    }

    #[DataProvider('waste')]
    public function test_a_product_with_waste_history_cannot_be_deleted(int $qty): void
    {
        $this->record([[$this->beans, $qty]])->assertSessionHasNoErrors();
        $admin = $this->userWith('products-delete', 'products-access');
        $message = __('messages.waste.product_in_use');
        // The recipe refusal would answer first on the raw-material screen.
        $this->latte->components()->detach($this->beans->id);

        $this->actingAs($admin)->delete(route('products.destroy', $this->beans))->assertSessionHasErrors(['delete' => $message]);
        $this->delete(route('raw-materials.destroy', $this->beans))->assertSessionHasErrors(['delete' => $message]);
        Sanctum::actingAs($admin, ['*']);
        $this->deleteJson(route('api.products.destroy', $this->beans))->assertUnprocessable()->assertJsonValidationErrors('delete');

        $this->assertModelExists($this->beans);
    }

    public function test_an_ingredient_that_only_left_inside_a_menu_cannot_be_deleted(): void
    {
        $this->record([[$this->latte, 1]])->assertSessionHasNoErrors();
        $this->latte->components()->detach($this->milk->id);

        $this->actingAs($this->userWith('products-delete', 'products-access'))
            ->delete(route('raw-materials.destroy', $this->milk))
            ->assertSessionHasErrors(['delete' => __('messages.waste.product_in_use')]);
    }

    public function test_a_warehouse_with_a_pending_waste_counts_as_history(): void
    {
        $empty = $this->warehouseOf($this->outlet, 'EMPTY');
        $access = app(OutletAccessService::class);
        $this->assertFalse($access->hasWarehouseHistory($empty));

        StockWaste::create(['warehouse_id' => $empty->id, 'status' => 'pending', 'reason' => 'other']);

        $this->assertTrue($access->hasWarehouseHistory($empty));
    }
}
