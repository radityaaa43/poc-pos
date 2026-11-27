<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * REQ-006 AC-02, BR-3: a product that needs preparation is mapped to one kitchen station; unmapped means the default.
 */
class ProductKitchenStationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private KitchenStation $bar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $admin = User::where('email', 'arya@gmail.com')->first();
        $admin->markEmailAsVerified();
        $this->actingAs($admin);

        $this->category = Category::create(['name' => 'Menu', 'image' => '', 'description' => '']);
        Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $this->bar = KitchenStation::create(['name' => 'Bar']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'barcode' => 'BC-KS-1',
            'sku' => 'SKU-KS-1',
            'title' => 'Es Teh',
            'category_id' => $this->category->id,
            'buy_price' => 100,
            'sell_price' => 200,
            'stock' => 10,
            'tax_rate' => 0,
        ], $overrides);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'barcode' => 'BC-KS-2',
            'sku' => 'SKU-KS-2',
            'title' => 'Produk Lama',
            'description' => '-',
            'buy_price' => 100,
            'sell_price' => 200,
            'stock' => 0,
            'tax_rate' => 0,
        ], $attributes));
    }

    private function updatePayload(array $overrides = []): array
    {
        return $this->payload(array_merge(['barcode' => 'BC-KS-2', 'sku' => 'SKU-KS-2'], $overrides));
    }

    public function test_store_saves_the_chosen_station_of_a_flagged_product(): void
    {
        $this->post(route('products.store'), $this->payload(['needs_preparation' => true, 'kitchen_station_id' => $this->bar->id]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-KS-1', 'needs_preparation' => true, 'kitchen_station_id' => $this->bar->id]);
    }

    public function test_store_without_a_choice_leaves_a_flagged_product_unmapped(): void
    {
        $this->post(route('products.store'), $this->payload(['needs_preparation' => true]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-KS-1', 'needs_preparation' => true, 'kitchen_station_id' => null]);
    }

    public function test_store_drops_the_station_when_not_flagged_even_if_one_is_sent(): void
    {
        $this->post(route('products.store'), $this->payload(['kitchen_station_id' => $this->bar->id]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-KS-1', 'needs_preparation' => false, 'kitchen_station_id' => null]);
    }

    public function test_store_rejects_a_station_that_does_not_exist(): void
    {
        $this->post(route('products.store'), $this->payload(['needs_preparation' => true, 'kitchen_station_id' => 9999]))
            ->assertSessionHasErrors('kitchen_station_id');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_rejects_a_deleted_station(): void
    {
        $this->bar->delete();

        $this->post(route('products.store'), $this->payload(['needs_preparation' => true, 'kitchen_station_id' => $this->bar->id]))
            ->assertSessionHasErrors('kitchen_station_id');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_update_saves_and_changes_the_station(): void
    {
        $grill = KitchenStation::create(['name' => 'Grill']);
        $product = $this->product(['needs_preparation' => true, 'kitchen_station_id' => $this->bar->id]);

        $this->put(route('products.update', $product), $this->updatePayload(['needs_preparation' => true, 'kitchen_station_id' => $grill->id]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'kitchen_station_id' => $grill->id]);
    }

    public function test_update_clears_the_station_when_unflagged_and_it_does_not_come_back(): void
    {
        $product = $this->product(['needs_preparation' => true, 'kitchen_station_id' => $this->bar->id]);

        $this->put(route('products.update', $product), $this->updatePayload())
            ->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'needs_preparation' => false, 'kitchen_station_id' => null]);

        // Flagged again without a choice: Dapur (null), not the station it had before.
        $this->put(route('products.update', $product), $this->updatePayload(['needs_preparation' => true]))
            ->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'needs_preparation' => true, 'kitchen_station_id' => null]);
    }

    public function test_update_rejects_a_forged_or_deleted_station(): void
    {
        $product = $this->product(['needs_preparation' => true]);
        $gone = KitchenStation::create(['name' => 'Gone']);
        $gone->delete();

        foreach ([9999, $gone->id] as $id) {
            $this->put(route('products.update', $product), $this->updatePayload(['needs_preparation' => true, 'kitchen_station_id' => $id]))
                ->assertSessionHasErrors('kitchen_station_id');
        }

        $this->assertNull($product->fresh()->kitchen_station_id);
    }

    public function test_create_and_edit_pages_list_the_active_stations(): void
    {
        $gone = KitchenStation::create(['name' => 'Gone']);
        $gone->delete();
        $product = $this->product(['needs_preparation' => true, 'kitchen_station_id' => $this->bar->id]);

        $this->get(route('products.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Products/Create')
                ->has('kitchen_stations', 2)
                ->where('kitchen_stations.0.name', 'Dapur')
                ->where('kitchen_stations.0.is_default', true)
                ->where('kitchen_stations.1.name', 'Bar')
                ->has('kitchen_stations.0', fn (Assert $row) => $row->hasAll(['id', 'name', 'is_default'])));

        $this->get(route('products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Products/Edit')
                ->has('kitchen_stations', 2)
                ->where('product.kitchen_station_id', $this->bar->id));
    }
}
