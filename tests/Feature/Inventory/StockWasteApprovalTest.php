<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockWasteService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REQ-011 AC-03, 05, 06, 07, 08, 09, 13: approving, rejecting and cancelling waste.
 */
class StockWasteApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $approver;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Product $beans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->outlet = Outlet::create(['code' => 'MAL', 'name' => 'Malabar']);
        $this->warehouse = Warehouse::create(['code' => 'MAL', 'name' => 'Gudang', 'type' => 'branch', 'is_active' => true, 'sort_order' => 1, 'outlet_id' => $this->outlet->id]);
        $this->staff = $this->userWith('stock-waste-create');
        $this->approver = $this->userWith('stock-waste-approve');
        $this->beans = $this->product('Biji Kopi', stock: 1000, avg: 250);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach($this->outlet->id, ['is_default' => true]);

        return $user;
    }

    private function product(string $title, int $stock, float $avg): Product
    {
        $code = Str::upper(Str::random(8));
        $product = Product::create([
            'title' => $title, 'sku' => $code, 'barcode' => $code, 'buy_price' => 100, 'sell_price' => 0, 'stock' => $stock,
            'image' => 'x.jpg', 'description' => 'x', 'tax_rate' => 0, 'is_raw_material' => true,
            'category_id' => Category::firstOrCreate(['name' => 'Bahan'], ['description' => 'x'])->id,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => $stock, 'avg_cost' => $avg]);

        return $product;
    }

    /** 10 x Rp 250 = Rp 2,500. */
    private function record(int $limit): StockWaste
    {
        Setting::setForOutlet('waste_approval_limit', (string) $limit, $this->outlet);

        return app(StockWasteService::class)->record([
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'damaged',
            'client_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->beans->id, 'qty' => 10]],
        ], $this->staff);
    }

    private function pivotStock(): int
    {
        return (int) $this->beans->warehouses()->where('warehouses.id', $this->warehouse->id)->first()->pivot->stock;
    }

    public function test_a_record_above_the_limit_waits_without_taking_stock(): void
    {
        $waste = $this->record(limit: 0);

        $this->assertSame(StockWaste::STATUS_PENDING, $waste->status);
        $this->assertSame(1000, $this->pivotStock());
    }

    public function test_another_approver_takes_the_stock_at_that_moment(): void
    {
        $waste = $this->record(limit: 0);

        $this->actingAs($this->approver)->post(route('stock-waste.approve', $waste))->assertRedirect();

        $waste->refresh();
        $this->assertSame(StockWaste::STATUS_RECORDED, $waste->status);
        $this->assertSame($this->approver->id, $waste->approved_by);
        $this->assertNotNull($waste->approved_at);
        $this->assertSame(990, $this->pivotStock());
        $this->assertSame(990, $this->beans->fresh()->stock);
    }

    public function test_the_creator_cannot_approve_their_own_record(): void
    {
        $waste = $this->record(limit: 0);
        $this->staff->givePermissionTo('stock-waste-approve');

        $this->actingAs($this->staff)->post(route('stock-waste.approve', $waste))->assertSessionHasErrors('waste');

        $this->assertSame(StockWaste::STATUS_PENDING, $waste->fresh()->status);
        $this->assertSame(1000, $this->pivotStock());
    }

    public function test_reject_needs_a_reason_and_moves_no_stock(): void
    {
        $waste = $this->record(limit: 0);

        $this->actingAs($this->approver)->post(route('stock-waste.reject', $waste), [])->assertSessionHasErrors('reason');
        $this->assertSame(StockWaste::STATUS_PENDING, $waste->fresh()->status);

        $this->actingAs($this->approver)->post(route('stock-waste.reject', $waste), ['reason' => 'Salah input'])->assertRedirect();

        $waste->refresh();
        $this->assertSame(StockWaste::STATUS_REJECTED, $waste->status);
        $this->assertSame(1000, $this->pivotStock());
    }

    public function test_cancel_restores_the_stock_and_its_cost(): void
    {
        $waste = $this->record(limit: 1_000_000); // 2,500 <= limit: recorded at once, stock taken
        $this->assertSame(StockWaste::STATUS_RECORDED, $waste->status);
        $this->assertSame(990, $this->pivotStock());

        $this->actingAs($this->approver)->post(route('stock-waste.cancel', $waste), ['reason' => 'Salah catat'])->assertRedirect();

        $waste->refresh();
        $this->assertSame(StockWaste::STATUS_CANCELLED, $waste->status);
        $this->assertSame(1000, $this->pivotStock());
        $this->assertSame(1000, $this->beans->fresh()->stock);
    }

    public function test_the_actions_need_the_approve_permission(): void
    {
        $waste = $this->record(limit: 0);

        $this->actingAs($this->staff)->post(route('stock-waste.approve', $waste))->assertForbidden();
        $this->actingAs($this->staff)->post(route('stock-waste.reject', $waste), ['reason' => 'x'])->assertForbidden();
    }
}
