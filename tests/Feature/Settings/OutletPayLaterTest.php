<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OutletPayLaterTest extends TestCase
{
    use RefreshDatabase;

    private function outlet(string $code = 'MAL'): Outlet
    {
        $outlet = Outlet::create(['code' => $code, 'name' => 'Outlet '.$code, 'is_sales_enabled' => true]);
        Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => $code, 'name' => $code,
            'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);

        return $outlet;
    }

    private function userWith(array $permissions, Outlet $outlet): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $user = tap(User::factory()->create())->givePermissionTo($permissions);
        $user->markEmailAsVerified();
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        return $user;
    }

    private function payload(Outlet $outlet, array $extra = []): array
    {
        return ['name' => $outlet->name, 'code' => $outlet->code, 'is_sales_enabled' => true] + $extra;
    }

    private function payLaterSale(Outlet $outlet, User $cashier, string $method = 'pay_later'): Transaction
    {
        return Transaction::create([
            'cashier_id' => $cashier->id,
            'warehouse_id' => $outlet->warehouses()->value('id'),
            'invoice' => 'TRX-'.uniqid(),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'grand_total' => 10000,
            'payment_method' => $method,
            'payment_status' => $method === 'pay_later' ? 'unpaid' : 'paid',
        ]);
    }

    public function test_release_enables_outlets_with_pay_later_sales_only(): void
    {
        $with = $this->outlet('AAA');
        $without = $this->outlet('BBB');
        $cashier = $this->userWith([], $with);
        $this->payLaterSale($with, $cashier);
        $this->payLaterSale($without, $cashier, 'cash');

        $migration = require database_path('migrations/2026_11_30_100001_enable_pay_later_for_outlets_with_pay_later_sales.php');
        $migration->down();
        $this->assertSame(0, Setting::where('key', 'pay_later_enabled')->count());

        $migration->up();

        $this->assertTrue(Setting::getBoolForOutlet('pay_later_enabled', $with));
        $this->assertFalse(Setting::getBoolForOutlet('pay_later_enabled', $without));
        $this->assertSame(1, Setting::where('key', 'pay_later_enabled')->count());
        $this->assertSame(0, Setting::where('key', 'pay_later_enabled')->whereNull('outlet_id')->count());
    }

    public function test_only_outlets_update_can_change_pay_later(): void
    {
        $outlet = $this->outlet();
        $viewer = $this->userWith(['outlets-access'], $outlet);
        $editor = $this->userWith(['outlets-access', 'outlets-update'], $outlet);

        $this->withSession($this->recentlyConfirmedSession())->actingAs($viewer)
            ->putJson(route('settings.outlets.update', $outlet), $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertForbidden();
        $this->assertFalse(Setting::getBoolForOutlet('pay_later_enabled', $outlet));

        $this->withSession($this->recentlyConfirmedSession())->actingAs($editor)
            ->put(route('settings.outlets.update', $outlet), $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertRedirect();
        $this->assertTrue(Setting::getBoolForOutlet('pay_later_enabled', $outlet));
        $this->assertSame(0, Setting::where('key', 'pay_later_enabled')->whereNull('outlet_id')->count());
    }

    public function test_change_is_audited_with_before_and_after(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        $this->withSession($this->recentlyConfirmedSession())->actingAs($editor)
            ->put(route('settings.outlets.update', $outlet), $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertRedirect();

        $log = AuditLog::where('event', 'outlet.pay_later_updated')->sole();
        $this->assertSame('outlets', $log->module);
        $this->assertSame($editor->id, $log->user_id);
        $this->assertSame($outlet->id, $log->auditable_id);
        $this->assertFalse($log->before['pay_later_enabled']);
        $this->assertTrue($log->after['pay_later_enabled']);
    }

    public function test_unchanged_value_or_omitted_field_writes_no_audit_row(): void
    {
        $outlet = $this->outlet();
        Setting::setForOutlet('pay_later_enabled', '1', $outlet);
        $editor = $this->userWith(['outlets-update'], $outlet);

        $this->withSession($this->recentlyConfirmedSession())->actingAs($editor)
            ->put(route('settings.outlets.update', $outlet), $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertRedirect();
        $this->withSession($this->recentlyConfirmedSession())->actingAs($editor)
            ->put(route('settings.outlets.update', $outlet), $this->payload($outlet))
            ->assertRedirect();

        $this->assertSame(0, AuditLog::where('event', 'outlet.pay_later_updated')->count());
        $this->assertTrue(Setting::getBoolForOutlet('pay_later_enabled', $outlet));
    }

    public function test_api_update_changes_and_audits_the_setting(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);
        Sanctum::actingAs($editor, ['*']);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'])->assertOk();

        $this->putJson("/api/v1/settings/outlets/{$outlet->id}", $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertOk();

        $this->assertTrue(Setting::getBoolForOutlet('pay_later_enabled', $outlet));
        $log = AuditLog::where('event', 'outlet.pay_later_updated')->sole();
        $this->assertFalse($log->before['pay_later_enabled']);
        $this->assertTrue($log->after['pay_later_enabled']);

        $this->putJson("/api/v1/settings/outlets/{$outlet->id}", $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'outlet.pay_later_updated')->count());
    }

    public function test_api_update_needs_the_outlets_update_ability(): void
    {
        $outlet = $this->outlet();
        $viewer = $this->userWith(['outlets-access'], $outlet);
        Sanctum::actingAs($viewer, ['outlets-access']);

        $this->putJson("/api/v1/settings/outlets/{$outlet->id}", $this->payload($outlet, ['pay_later_enabled' => true]))
            ->assertForbidden();
        $this->assertFalse(Setting::getBoolForOutlet('pay_later_enabled', $outlet));
    }

    public function test_outlet_index_exposes_pay_later_enabled_per_outlet(): void
    {
        $on = $this->outlet('AAA');
        $off = $this->outlet('BBB');
        Setting::setForOutlet('pay_later_enabled', '1', $on);
        $viewer = $this->userWith(['outlets-access'], $on);
        $viewer->outlets()->attach($off->id);

        $this->withoutVite()->actingAs($viewer)->get(route('settings.outlets.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Settings/Outlets/Index')
                ->where('outlets', fn ($outlets) => collect($outlets)->pluck('pay_later_enabled', 'code')->all() === ['AAA' => true, 'BBB' => false]));
    }

    public function test_turning_setting_off_keeps_open_bills(): void
    {
        $outlet = $this->outlet();
        Setting::setForOutlet('pay_later_enabled', '1', $outlet);
        $editor = $this->userWith(['outlets-update'], $outlet);
        $sale = $this->payLaterSale($outlet, $editor);
        $receivable = Receivable::create([
            'transaction_id' => $sale->id, 'invoice' => $sale->invoice,
            'total' => 10000, 'paid' => 0, 'status' => 'unpaid',
        ]);

        $this->withSession($this->recentlyConfirmedSession())->actingAs($editor)
            ->put(route('settings.outlets.update', $outlet), $this->payload($outlet, ['pay_later_enabled' => false]))
            ->assertRedirect();

        $this->assertFalse(Setting::getBoolForOutlet('pay_later_enabled', $outlet));
        $sale = $sale->fresh();
        $this->assertSame('pay_later', $sale->payment_method);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertSame('unpaid', $receivable->fresh()->status);
        $this->assertEquals(10000, $receivable->fresh()->total);
    }
}
