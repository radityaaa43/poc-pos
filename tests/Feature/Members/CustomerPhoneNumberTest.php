<?php

namespace Tests\Feature\Members;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerPhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        return tap($user)->givePermissionTo($permissions);
    }

    public function test_phone_number_is_stored_exactly_as_typed(): void
    {
        $user = $this->userWith(['customers-create']);

        $this->actingAs($user)->postJson(route('customers.storeAjax'), [
            'name' => 'Nol Depan',
            'no_telp' => '082842305787',
            'address' => 'Jl. Nol',
        ])->assertSuccessful();

        $this->assertSame('082842305787', Customer::firstOrFail()->no_telp);
    }

    public function test_numbers_that_differ_only_by_the_leading_zero_are_distinct(): void
    {
        $user = $this->userWith(['customers-create']);
        Customer::create(['name' => 'A', 'no_telp' => '08123456789', 'address' => 'x']);

        $this->actingAs($user)->postJson(route('customers.storeAjax'), [
            'name' => 'B',
            'no_telp' => '8123456789',
            'address' => 'y',
        ])->assertSuccessful();

        $this->assertSame(2, Customer::count());
    }

    public function test_member_can_be_saved_with_the_phone_number_the_edit_form_was_filled_with(): void
    {
        $user = $this->userWith(['customers-access', 'customers-edit']);
        $member = Customer::create([
            'name' => 'Member Lama',
            'no_telp' => '08123456789',
            'address' => 'Jl. Lama',
            'is_loyalty_member' => true,
        ]);

        $this->actingAs($user)->get(route('members.edit', $member))
            ->assertInertia(fn (Assert $page) => $page->where('member.no_telp', '08123456789'));

        $this->actingAs($user)->put(route('members.update', $member), [
            'name' => 'Member Lama',
            'no_telp' => '08123456789',
            'address' => 'Jl. Lama',
            'is_loyalty_member' => false,
            'province_id' => '11',
            'regency_id' => '1101',
            'district_id' => '110101',
            'village_id' => '1101012001',
        ])->assertSessionHasNoErrors()->assertRedirect(route('members.show', $member));

        $this->assertFalse($member->fresh()->is_loyalty_member);
    }
}
