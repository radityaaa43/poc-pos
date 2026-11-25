<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQ-003 AC-01: a product can be flagged "needs preparation" with an optional prep target in minutes.
 */
class ProductPreparationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $admin = User::where('email', 'arya@gmail.com')->first();
        $admin->markEmailAsVerified();
        $this->actingAs($admin);

        $this->category = Category::create(['name' => 'Menu', 'image' => '', 'description' => '']);
        Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'barcode' => 'BC-PREP-1',
            'sku' => 'SKU-PREP-1',
            'title' => 'Nasi Goreng',
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
            'barcode' => 'BC-PREP-2',
            'sku' => 'SKU-PREP-2',
            'title' => 'Produk Lama',
            'description' => '-',
            'buy_price' => 100,
            'sell_price' => 200,
            'stock' => 0,
            'tax_rate' => 0,
        ], $attributes));
    }

    public function test_store_saves_needs_preparation_and_target(): void
    {
        $this->post(route('products.store'), $this->payload(['needs_preparation' => true, 'prep_target_minutes' => 7]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-PREP-1', 'needs_preparation' => true, 'prep_target_minutes' => 7]);
    }

    public function test_store_defaults_to_not_needing_preparation(): void
    {
        $this->post(route('products.store'), $this->payload())->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-PREP-1', 'needs_preparation' => false, 'prep_target_minutes' => null]);
    }

    public function test_store_drops_the_target_when_not_flagged(): void
    {
        $this->post(route('products.store'), $this->payload(['prep_target_minutes' => 7]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-PREP-1', 'needs_preparation' => false, 'prep_target_minutes' => null]);
    }

    public function test_store_allows_a_flagged_product_without_a_target(): void
    {
        $this->post(route('products.store'), $this->payload(['needs_preparation' => true]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-PREP-1', 'needs_preparation' => true, 'prep_target_minutes' => null]);
    }

    public function test_store_rejects_a_zero_or_negative_target(): void
    {
        // 65536 is past the smallint column, which SQLite would accept but MySQL rejects with a 500.
        foreach ([0, -3, 65536] as $minutes) {
            $this->post(route('products.store'), $this->payload(['needs_preparation' => true, 'prep_target_minutes' => $minutes]))
                ->assertSessionHasErrors('prep_target_minutes');
        }

        $this->assertDatabaseCount('products', 0);
    }

    public function test_update_saves_needs_preparation_and_target(): void
    {
        $product = $this->product();

        $this->put(route('products.update', $product), $this->payload(['barcode' => 'BC-PREP-2', 'sku' => 'SKU-PREP-2', 'needs_preparation' => true, 'prep_target_minutes' => 12]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'needs_preparation' => true, 'prep_target_minutes' => 12]);
    }

    public function test_update_clears_the_target_when_unflagged(): void
    {
        $product = $this->product(['needs_preparation' => true, 'prep_target_minutes' => 12]);

        $this->put(route('products.update', $product), $this->payload(['barcode' => 'BC-PREP-2', 'sku' => 'SKU-PREP-2']))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'needs_preparation' => false, 'prep_target_minutes' => null]);
    }

    public function test_update_rejects_a_zero_target(): void
    {
        $product = $this->product();

        $this->put(route('products.update', $product), $this->payload(['barcode' => 'BC-PREP-2', 'sku' => 'SKU-PREP-2', 'needs_preparation' => true, 'prep_target_minutes' => 0]))
            ->assertSessionHasErrors('prep_target_minutes');
    }

    public function test_release_backfill_flags_composite_products_only(): void
    {
        $composite = $this->product(['barcode' => 'BC-PREP-3', 'sku' => 'SKU-PREP-3', 'is_composite' => true]);
        $plain = $this->product();

        $migration = require database_path('migrations/2026_11_28_100001_add_preparation_to_products_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue((bool) $composite->fresh()->needs_preparation);
        $this->assertFalse((bool) $plain->fresh()->needs_preparation);
    }
}
