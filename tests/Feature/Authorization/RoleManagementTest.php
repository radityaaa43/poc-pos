<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// The Roles page posts permission ids (RoleRequest: exists:permissions,id), not names.
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (['roles-access', 'roles-create', 'roles-update'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['roles-access', 'roles-create', 'roles-update']);

        return $user;
    }

    public function test_creating_a_role_with_permission_ids_succeeds_and_is_audited_by_name(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'products-access', 'guard_name' => 'web']);

        $this->actingAs($this->admin())
            ->withSession($this->recentlyConfirmedSession())
            ->post(route('roles.store'), ['name' => 'stock-clerk', 'selectedPermission' => [$permission->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(Role::findByName('stock-clerk')->hasPermissionTo('products-access'));
        $audit = AuditLog::where('event', 'role.created')->firstOrFail();
        $this->assertSame(['products-access'], $audit->after['permissions']);
    }

    public function test_updating_role_permissions_with_ids_succeeds_and_is_audited_by_name(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'products-access', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'stock-clerk', 'guard_name' => 'web']);

        $this->actingAs($this->admin())
            ->withSession($this->recentlyConfirmedSession())
            ->put(route('roles.update', $role->id), ['name' => 'stock-clerk', 'selectedPermission' => [$permission->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['products-access'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertSame(
            ['products-access'],
            AuditLog::where('event', 'role.permission_changed')->firstOrFail()->after['permissions'],
        );
    }
}
