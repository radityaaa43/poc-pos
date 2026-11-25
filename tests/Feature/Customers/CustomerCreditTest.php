<?php

namespace Tests\Feature\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
    }

    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return tap(User::factory()->create())->givePermissionTo($permissions);
    }

    public function test_new_customer_may_not_buy_on_credit_by_default(): void
    {
        $this->assertFalse($this->customer()->fresh()->may_buy_on_credit);
    }

    public function test_approver_can_switch_the_flag_and_the_change_is_audited(): void
    {
        $customer = $this->customer();
        $manager = $this->userWith(['customers-credit-approve']);

        $this->actingAs($manager)
            ->patch(route('customers.credit', $customer), ['may_buy_on_credit' => true])
            ->assertRedirect();

        $this->assertTrue($customer->fresh()->may_buy_on_credit);

        $log = AuditLog::where('event', 'customer.credit_updated')->sole();
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame($customer->id, $log->auditable_id);
        $this->assertFalse($log->before['may_buy_on_credit']);
        $this->assertTrue($log->after['may_buy_on_credit']);
    }

    public function test_user_without_the_permission_is_refused_and_nothing_changes(): void
    {
        $customer = $this->customer();
        $cashier = $this->userWith(['customers-access', 'customers-edit']);

        $this->actingAs($cashier)
            ->patchJson(route('customers.credit', $customer), ['may_buy_on_credit' => true]) // JSON: the HTML 403 page needs a Vite build
            ->assertForbidden();

        $this->assertFalse($customer->fresh()->may_buy_on_credit);
        $this->assertSame(0, AuditLog::where('event', 'customer.credit_updated')->count());
    }

    public function test_setting_the_same_value_writes_no_audit_row(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->userWith(['customers-credit-approve']))
            ->patch(route('customers.credit', $customer), ['may_buy_on_credit' => false])
            ->assertRedirect();

        $this->assertSame(0, AuditLog::where('event', 'customer.credit_updated')->count());
    }

    public function test_the_flag_is_required_and_boolean(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->userWith(['customers-credit-approve']))
            ->patch(route('customers.credit', $customer), ['may_buy_on_credit' => 'maybe'])
            ->assertSessionHasErrors('may_buy_on_credit');
    }

    public function test_the_flag_cannot_be_changed_through_the_regular_edit_form(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->userWith(['customers-edit']))
            ->put(route('customers.update', $customer), [
                'name' => 'Budi',
                'no_telp' => '62811',
                'address' => 'Jl. Satu',
                'may_buy_on_credit' => true,
            ]);

        $this->assertFalse($customer->fresh()->may_buy_on_credit);
    }

    public function test_seeded_roles_owner_and_manager_get_the_permission_and_cashier_does_not(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->assertTrue(Role::findByName('super-admin')->hasPermissionTo('customers-credit-approve'));
        $this->assertTrue(Role::findByName('manager')->hasPermissionTo('customers-credit-approve'));
        $this->assertFalse(Role::findByName('cashier')->hasPermissionTo('customers-credit-approve'));
    }

    public function test_release_backfill_flags_customers_who_had_a_receivable_only(): void
    {
        $with = $this->customer();
        $without = Customer::create(['name' => 'Sari', 'no_telp' => '62822', 'address' => 'Jl. Dua']);
        DB::table('receivables')->insert([
            'customer_id' => $with->id,
            'invoice' => 'INV-1',
            'total' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_28_200001_add_may_buy_on_credit_to_customers_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue((bool) DB::table('customers')->where('id', $with->id)->value('may_buy_on_credit'));
        $this->assertFalse((bool) DB::table('customers')->where('id', $without->id)->value('may_buy_on_credit'));
    }
}
