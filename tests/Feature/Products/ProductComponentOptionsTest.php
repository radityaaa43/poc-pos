<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// AC-18: the recipe form needs each component's stock unit and raw-material flag, raw materials first.
class ProductComponentOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $category = Category::create(['name' => 'Bahan', 'description' => 'Uji', 'image' => 'c.png']);
        $make = fn (string $title, bool $raw, ?string $unitCode, array $extra = []) => tap(
            Product::create(array_merge([
                'category_id' => $category->id,
                'image' => 'p.png',
                'barcode' => 'B-'.$title,
                'sku' => 'S-'.$title,
                'title' => $title,
                'description' => 'x',
                'buy_price' => 100,
                'sell_price' => 200,
                'stock' => 10,
                'tax_rate' => 0,
                'is_raw_material' => $raw,
            ], $extra)),
            fn (Product $p) => $unitCode && $p->units()->attach(Unit::where('code', $unitCode)->value('id'), [
                'is_base' => true, 'conversion_factor' => 1, 'buy_price' => 100, 'sell_price' => 200,
            ])
        );

        $make('Air Mineral', false, null);
        $make('Susu', true, 'ML');
        $make('Biji Kopi', true, 'GRAM');
        $this->recipe = $make('Americano', false, null, ['is_composite' => true]);
    }

    private function assertOptions(Assert $page): void
    {
        $page->has('products', 3)
            ->where('products.0.title', 'Biji Kopi')
            ->where('products.0.is_raw_material', true)
            ->where('products.0.unit_symbol', 'g')
            ->where('products.1.title', 'Susu')
            ->where('products.1.unit_symbol', 'ml')
            ->where('products.2.title', 'Air Mineral')
            ->where('products.2.is_raw_material', false)
            ->where('products.2.unit_symbol', null);
    }

    public function test_create_page_lists_raw_materials_first_with_base_unit_symbol(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Products/Create')->tap($this->assertOptions(...)));
    }

    public function test_edit_page_lists_raw_materials_first_with_base_unit_symbol(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.edit', $this->recipe))
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Products/Edit')->tap($this->assertOptions(...)));
    }
}
