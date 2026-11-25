<?php

namespace Tests\Feature\Pricing;

use App\Models\PriceList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PriceListShowPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_detail_needs_the_price_lists_permission(): void
    {
        Permission::firstOrCreate(['name' => 'price-lists-access', 'guard_name' => 'web']);
        $list = PriceList::create(['name' => 'Grosir', 'slug' => 'grosir', 'customer_scope' => 'all', 'is_active' => true, 'priority' => 10]);

        $this->actingAs(User::factory()->create())
            ->get(route('price-lists.show', $list))
            ->assertForbidden();

        $allowed = User::factory()->create();
        $allowed->givePermissionTo('price-lists-access');
        $this->actingAs($allowed)
            ->get(route('price-lists.show', $list))
            ->assertOk();
    }
}
