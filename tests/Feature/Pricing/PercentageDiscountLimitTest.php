<?php

namespace Tests\Feature\Pricing;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PercentageDiscountLimitTest extends TestCase
{
    use RefreshDatabase;

    private function managerWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        return tap($user)->givePermissionTo($permissions);
    }

    public function test_customer_voucher_rejects_percentage_above_100(): void
    {
        $user = $this->managerWith(['customer-vouchers-access', 'customer-vouchers-create']);
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '08123456789', 'address' => 'Jl. Uji']);
        $payload = [
            'customer_id' => $customer->id,
            'name' => 'Voucher uji',
            'code' => '',
            'discount_type' => 'percentage',
            'discount_value' => 150,
        ];

        $this->actingAs($user)->post(route('customer-vouchers.store'), $payload)
            ->assertSessionHasErrors('discount_value');
        $this->assertDatabaseCount('customer_vouchers', 0);

        $this->actingAs($user)->post(route('customer-vouchers.store'), [...$payload, 'discount_value' => 100])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('customer_vouchers', 1);
    }

    public function test_pricing_rule_rejects_percentage_above_100(): void
    {
        $user = $this->managerWith(['pricing-rules-access', 'pricing-rules-create']);
        $payload = [
            'name' => 'Diskon uji',
            'kind' => 'standard_discount',
            'is_active' => true,
            'priority' => 100,
            'target_type' => 'all',
            'customer_scope' => 'all',
            'discount_type' => 'percentage',
            'discount_value' => 150,
        ];

        $this->actingAs($user)->post(route('pricing-rules.store'), $payload)
            ->assertSessionHasErrors('discount_value');
        $this->assertDatabaseCount('pricing_rules', 0);

        // A fixed amount may exceed 100.
        $this->actingAs($user)->post(route('pricing-rules.store'), [...$payload, 'discount_type' => 'fixed_amount'])
            ->assertSessionHasNoErrors();
    }
}
