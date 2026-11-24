<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseUnitTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->category = Category::create([
            'name' => 'Bahan',
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);
    }

    private function createProduct(int $buyPrice = 5000): Product
    {
        return Product::create([
            'category_id' => $this->category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Bahan Uji',
            'description' => 'Deskripsi uji.',
            'buy_price' => $buyPrice,
            'sell_price' => 10000,
            'stock' => 0,
            'tax_rate' => 0,
        ]);
    }

    private function unitRows(): array
    {
        $gram = Unit::where('code', 'GRAM')->firstOrFail();
        $kg = Unit::where('code', 'KG')->firstOrFail();

        return [
            ['unit_id' => $gram->id, 'is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5, 'sell_price' => 10, 'is_purchase_default' => false],
            ['unit_id' => $kg->id, 'is_base' => false, 'conversion_factor' => 1000, 'buy_price' => 4800, 'sell_price' => 9500, 'is_purchase_default' => true],
        ];
    }

    private function storePayload(array $units): array
    {
        return [
            'image' => UploadedFile::fake()->image('product.png'),
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Tepung',
            'category_id' => $this->category->id,
            'buy_price' => 5,
            'sell_price' => 10,
            'stock' => 0,
            'tax_rate' => 0,
            'units' => $units,
        ];
    }

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    public function test_gram_and_ml_units_exist(): void
    {
        $this->assertDatabaseHas('units', ['code' => 'GRAM', 'name' => 'Gram', 'symbol' => 'g']);
        $this->assertDatabaseHas('units', ['code' => 'ML', 'name' => 'Mililiter', 'symbol' => 'ml']);
    }

    public function test_unit_migration_is_idempotent(): void
    {
        $this->migration('2026_11_24_000001_add_gram_and_ml_units')->up();

        $this->assertSame(1, Unit::where('code', 'GRAM')->count());
        $this->assertSame(1, Unit::where('code', 'ML')->count());
    }

    public function test_unit_migration_down_keeps_units_in_use(): void
    {
        $product = $this->createProduct();
        $product->units()->attach(Unit::where('code', 'GRAM')->first()->id, [
            'is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5, 'sell_price' => 10,
        ]);

        $this->migration('2026_11_24_000001_add_gram_and_ml_units')->down();

        $this->assertDatabaseHas('units', ['code' => 'GRAM']);
        $this->assertDatabaseMissing('units', ['code' => 'ML']);
    }

    public function test_data_fix_reprices_pack_rows_priced_per_piece(): void
    {
        $pcs = Unit::where('code', 'PCS')->firstOrFail();
        $karton = Unit::where('code', 'KARTON')->firstOrFail();
        $box = Unit::where('code', 'BOX')->firstOrFail();
        $dus = Unit::where('code', 'DUS')->firstOrFail();

        $product = $this->createProduct(5000);
        $product->units()->attach($pcs->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000]);
        // copied the base price onto the pack
        $product->units()->attach($karton->id, ['is_base' => false, 'conversion_factor' => 24, 'buy_price' => 5000, 'sell_price' => 240000]);
        // copied an older, lower base price
        $product->units()->attach($box->id, ['is_base' => false, 'conversion_factor' => 12, 'buy_price' => 4000, 'sell_price' => 120000]);
        // already priced per pack: must stay
        $product->units()->attach($dus->id, ['is_base' => false, 'conversion_factor' => 24, 'buy_price' => 115000, 'sell_price' => 240000]);

        // a non-base row with factor 1 is not a pack: stays even at or below the base price
        $gram = Unit::where('code', 'GRAM')->firstOrFail();
        $product->units()->attach($gram->id, ['is_base' => false, 'conversion_factor' => 1, 'buy_price' => 4000, 'sell_price' => 10000]);

        // a base row is never repriced, whatever its factor or price
        $other = $this->createProduct(5000);
        $other->units()->attach($pcs->id, ['is_base' => true, 'conversion_factor' => 12, 'buy_price' => 3000, 'sell_price' => 10000]);

        $price = fn (Product $p, Unit $unit) => $p->units()->whereKey($unit->id)->first()->pivot->buy_price;
        $assertFixed = function () use ($price, $product, $other, $pcs, $karton, $box, $dus, $gram) {
            $this->assertSame(5000, $price($product, $pcs));
            $this->assertSame(120000, $price($product, $karton));
            $this->assertSame(60000, $price($product, $box));
            $this->assertSame(115000, $price($product, $dus));
            $this->assertSame(4000, $price($product, $gram));
            $this->assertSame(3000, $price($other, $pcs));
        };

        $migration = $this->migration('2026_11_24_000003_fix_per_pack_buy_price_in_product_units');
        $migration->up();
        $assertFixed();

        // running it again must not compound the repricing
        $migration->up();
        $assertFixed();
    }

    public function test_store_persists_buy_price_and_purchase_default_per_unit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($this->unitRows()))
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();
        $kg = Unit::where('code', 'KG')->firstOrFail();

        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $kg->id, 'buy_price' => 4800, 'is_purchase_default' => true,
        ]);
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => Unit::where('code', 'GRAM')->first()->id, 'buy_price' => 5, 'is_purchase_default' => false,
        ]);
    }

    public function test_store_rejects_two_purchase_defaults(): void
    {
        $units = $this->unitRows();
        $units[0]['is_purchase_default'] = true;

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($units))
            ->assertSessionHasErrors('units');

        $this->assertDatabaseMissing('products', ['title' => 'Tepung']);
    }

    public function test_update_persists_purchase_default_and_rejects_two(): void
    {
        $product = $this->createProduct(5);
        $update = fn (array $units) => $this->actingAs($this->admin)->put(route('products.update', $product), [
            'barcode' => $product->barcode,
            'sku' => $product->sku,
            'title' => $product->title,
            'category_id' => $this->category->id,
            'buy_price' => 5,
            'sell_price' => 10,
            'tax_rate' => 0,
            'units' => $units,
        ]);

        $units = $this->unitRows();
        $units[0]['is_purchase_default'] = true;
        $units[1]['is_purchase_default'] = false;
        $update($units)->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $units[0]['unit_id'], 'is_purchase_default' => true,
        ]);
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $units[1]['unit_id'], 'buy_price' => 4800, 'is_purchase_default' => false,
        ]);

        $units[1]['is_purchase_default'] = true;
        $update($units)->assertSessionHasErrors('units');
    }

    public function test_blank_unit_buy_price_defaults_to_base_price_times_conversion(): void
    {
        $units = $this->unitRows();
        $units[1]['buy_price'] = '';

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($units))
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();

        // 1 kg = 1000 g at Rp 5/g → Rp 5.000 per kg, never the per-gram price.
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $units[1]['unit_id'], 'buy_price' => 5000,
        ]);
    }

    public function test_blank_unit_sell_price_defaults_to_base_price_times_conversion(): void
    {
        $units = $this->unitRows();
        $units[1]['sell_price'] = '';

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($units))
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();

        // 1 kg = 1000 g at Rp 10/g → Rp 10.000 per kg, never the per-gram price (#40).
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $units[1]['unit_id'], 'sell_price' => 10000,
        ]);
    }

    public function test_an_explicit_unit_sell_price_is_kept_as_typed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($this->unitRows()))
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();

        // a discounted pack price (9.500 instead of 10 × 1000) stays as typed
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $this->unitRows()[1]['unit_id'], 'sell_price' => 9500,
        ]);
    }

    public function test_blank_buy_price_uses_the_base_fallback_factor_when_no_row_is_flagged_base(): void
    {
        $kg = Unit::where('code', 'KG')->firstOrFail();
        $gram = Unit::where('code', 'GRAM')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload([
                ['unit_id' => $kg->id, 'is_base' => false, 'conversion_factor' => 1000, 'buy_price' => '', 'sell_price' => 9500],
                ['unit_id' => $gram->id, 'is_base' => false, 'conversion_factor' => 1, 'buy_price' => 5, 'sell_price' => 10],
            ]))
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();

        // the first row becomes the base (factor 1), so its blank price is the product's, not × 1000
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $kg->id, 'is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5,
        ]);
    }

    public function test_purchase_default_flags_sent_as_form_data_strings_are_counted_explicitly(): void
    {
        $units = array_map(
            fn ($u) => ['is_base' => $u['is_base'] ? '1' : '0', 'is_purchase_default' => '0'] + $u,
            $this->unitRows()
        );

        $units[0]['is_purchase_default'] = '1';
        $units[1]['is_purchase_default'] = '1';
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($units))
            ->assertSessionHasErrors('units');
        $this->assertDatabaseMissing('products', ['title' => 'Tepung']);

        $units[0]['is_purchase_default'] = '0';
        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($units))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();
        $this->assertDatabaseHas('product_units', [
            'product_id' => $product->id, 'unit_id' => $units[1]['unit_id'], 'is_purchase_default' => true,
        ]);
    }

    public function test_purchase_default_is_optional(): void
    {
        $units = array_map(fn ($u) => array_diff_key($u, ['is_purchase_default' => 1]), $this->unitRows());

        $this->actingAs($this->admin)
            ->post(route('products.store'), $this->storePayload($units))
            ->assertRedirect(route('products.index'));

        $product = Product::where('title', 'Tepung')->firstOrFail();
        $this->assertDatabaseMissing('product_units', ['product_id' => $product->id, 'is_purchase_default' => true]);
    }
}
