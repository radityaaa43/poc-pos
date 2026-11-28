<?php

namespace Tests\Feature\DineIn\Concerns;

use App\Models\Cart;
use App\Models\Category;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Testing\TestResponse;

/**
 * The seeded cashier with an open shift in one outlet, a table there and a product (10000, stock 100),
 * and the POS table order calls (#426). Call setUpPosTable() from setUp().
 */
trait MakesPosTableOrders
{
    private User $cashier;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private DiningTable $table;

    private Product $nasi;

    protected function setUpPosTable(): void
    {
        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create([
            'outlet_id' => $this->outlet->id, 'code' => 'WH-1', 'name' => 'Gudang 1', 'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->cashier->outlets()->sync([$this->outlet->id => ['is_default' => true]]);
        $this->table = $this->tableNamed('Meja 7');
        $this->nasi = $this->product('Nasi goreng', 10000, ['needs_preparation' => true]);

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
    }

    private function tableNamed(string $name, ?Outlet $outlet = null): DiningTable
    {
        $area = DineArea::create(['outlet_id' => ($outlet ?? $this->outlet)->id, 'name' => 'Area '.$name, 'is_active' => true]);

        return DiningTable::create(['dine_area_id' => $area->id, 'name' => $name, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
    }

    private function product(string $title, int $price, array $overrides = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'Kategori'], ['image' => 'categories/t.jpg', 'description' => 'x']);
        $product = Product::create($overrides + [
            'title' => $title, 'sku' => "SKU-{$title}", 'barcode' => "BC-{$title}", 'buy_price' => 2000, 'sell_price' => $price,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0, 'category_id' => $category->id,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 100]);

        return $product;
    }

    private function cart(?Product $product = null, int $qty = 2, array $overrides = []): Cart
    {
        $product ??= $this->nasi;

        return Cart::create($overrides + [
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'product_id' => $product->id,
            'qty' => $qty, 'price' => $qty * (int) $product->sell_price,
        ]);
    }

    private function send(array $payload = []): TestResponse
    {
        return $this->actingAs($this->cashier)->post(route('transactions.table-orders.store'), $payload + [
            'dine_table_id' => $this->table->id, 'payment_option' => DineOrder::PAY_AT_COUNTER,
        ]);
    }

    /** A cart of 2 x Nasi goreng sent to the table, accepted. */
    private function sentOrder(array $payload = []): DineOrder
    {
        $this->cart();
        $this->send($payload)->assertSessionHasNoErrors();

        return DineOrder::latest('id')->firstOrFail();
    }
}
