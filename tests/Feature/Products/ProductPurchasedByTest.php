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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A product ticked "Bahan baku" must say who buys it (central or outlet), like the Raw Materials form.
 */
class ProductPurchasedByTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Pilih siapa yang membeli bahan ini.';

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $admin = User::where('email', 'arya@gmail.com')->first();
        $admin->markEmailAsVerified();
        $this->actingAs($admin);

        $this->category = Category::create(['name' => 'Bahan', 'image' => '', 'description' => '']);
        Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'barcode' => 'BC-PB-1',
            'sku' => 'SKU-PB-1',
            'title' => 'Biji Kopi',
            'category_id' => $this->category->id,
            'buy_price' => 250,
            'sell_price' => 0,
            'stock' => 10,
            'tax_rate' => 0,
        ], $overrides);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'barcode' => 'BC-PB-2',
            'sku' => 'SKU-PB-2',
            'title' => 'Produk Lama',
            'description' => '-',
            'buy_price' => 100,
            'sell_price' => 200,
            'stock' => 0,
            'tax_rate' => 0,
        ], $attributes));
    }

    public function test_store_rejects_a_raw_material_without_purchased_by(): void
    {
        $this->post(route('products.store'), $this->payload(['is_raw_material' => true]))
            ->assertSessionHasErrors(['purchased_by' => self::MESSAGE]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_rejects_an_unknown_purchased_by(): void
    {
        $this->post(route('products.store'), $this->payload(['is_raw_material' => true, 'purchased_by' => 'supplier']))
            ->assertSessionHasErrors('purchased_by');
    }

    public function test_store_saves_a_raw_material_with_purchased_by(): void
    {
        $this->post(route('products.store'), $this->payload(['is_raw_material' => true, 'purchased_by' => 'central']))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-PB-1', 'is_raw_material' => true, 'purchased_by' => 'central']);
    }

    public function test_store_keeps_purchased_by_null_for_a_plain_product_even_if_sent(): void
    {
        $this->post(route('products.store'), $this->payload(['purchased_by' => 'outlet']))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'SKU-PB-1', 'is_raw_material' => false, 'purchased_by' => null]);
    }

    public function test_update_rejects_ticking_raw_material_without_purchased_by(): void
    {
        $product = $this->product();

        $this->put(route('products.update', $product), $this->payload(['barcode' => 'BC-PB-2', 'sku' => 'SKU-PB-2', 'is_raw_material' => true]))
            ->assertSessionHasErrors(['purchased_by' => self::MESSAGE]);

        $this->assertFalse($product->fresh()->is_raw_material);
    }

    public function test_update_saves_a_raw_material_with_purchased_by(): void
    {
        $product = $this->product();

        $this->put(route('products.update', $product), $this->payload(['barcode' => 'BC-PB-2', 'sku' => 'SKU-PB-2', 'is_raw_material' => true, 'purchased_by' => 'outlet']))
            ->assertRedirect(route('products.index'));

        $this->assertSame('outlet', $product->fresh()->purchased_by);
    }

    public function test_update_clears_purchased_by_when_raw_material_is_unticked(): void
    {
        $product = $this->product(['is_raw_material' => true, 'purchased_by' => 'central']);

        $this->put(route('products.update', $product), $this->payload(['barcode' => 'BC-PB-2', 'sku' => 'SKU-PB-2', 'is_raw_material' => false, 'purchased_by' => 'central']))
            ->assertRedirect(route('products.index'));

        $this->assertNull($product->fresh()->purchased_by);
    }

    private function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Biji Kopi',
            'barcode' => 'BC-PB-API',
            'category_id' => $this->category->id,
            'buy_price' => 250,
            'sell_price' => 0,
        ], $overrides);
    }

    public function test_api_store_rejects_a_raw_material_without_purchased_by(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/products', $this->apiPayload(['is_raw_material' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchased_by');
    }

    public function test_api_store_saves_a_raw_material_with_purchased_by(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/products', $this->apiPayload(['is_raw_material' => true, 'purchased_by' => 'central']))
            ->assertCreated();

        $this->assertDatabaseHas('products', ['barcode' => 'BC-PB-API', 'purchased_by' => 'central']);
    }

    public function test_api_store_keeps_purchased_by_null_for_a_plain_product(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/products', $this->apiPayload(['purchased_by' => 'outlet']))->assertCreated();

        $this->assertDatabaseHas('products', ['barcode' => 'BC-PB-API', 'purchased_by' => null]);
    }

    public function test_api_update_requires_purchased_by_when_ticking_raw_material(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $product = $this->product();

        $this->putJson("/api/v1/products/{$product->id}", ['is_raw_material' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchased_by');

        $this->putJson("/api/v1/products/{$product->id}", ['is_raw_material' => true, 'purchased_by' => 'outlet'])->assertOk();
        $this->assertSame('outlet', $product->fresh()->purchased_by);
    }

    public function test_api_update_clears_purchased_by_when_unticked_but_leaves_it_on_other_edits(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $product = $this->product(['is_raw_material' => true, 'purchased_by' => 'central']);

        $this->putJson("/api/v1/products/{$product->id}", ['title' => 'Baru'])->assertOk();
        $this->assertSame('central', $product->fresh()->purchased_by);

        $this->putJson("/api/v1/products/{$product->id}", ['is_raw_material' => false])->assertOk();
        $this->assertNull($product->fresh()->purchased_by);
    }
}
