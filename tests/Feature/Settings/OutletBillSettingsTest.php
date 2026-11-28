<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BillSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// REQ-010 AC-01..04: the bill settings (Tagihan) of an outlet.
class OutletBillSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['service_charge_rate', 'tax_kind', 'pb1_rate', 'cash_rounding_unit'];

    private const DEFAULTS = ['service_charge_rate' => 0.0, 'tax_kind' => 'ppn', 'pb1_rate' => 10.0, 'cash_rounding_unit' => 0];

    private const CHOSEN = ['service_charge_rate' => 5, 'tax_kind' => 'pb1', 'pb1_rate' => 10.5, 'cash_rounding_unit' => 500];

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

    private function saveOnWeb(User $user, Outlet $outlet, array $extra)
    {
        return $this->withSession($this->recentlyConfirmedSession())->actingAs($user)
            ->put(route('settings.outlets.update', $outlet), $this->payload($outlet, $extra));
    }

    private function confirmedApiUser(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'])->assertOk();
    }

    private function settings(Outlet $outlet): array
    {
        return app(BillSettingsService::class)->get($outlet);
    }

    private function storedRows(): int
    {
        return Setting::whereIn('key', self::KEYS)->count();
    }

    private function audits()
    {
        return AuditLog::where('event', 'outlet.bill_settings_updated');
    }

    public function test_defaults_reproduce_today_and_no_rows_exist(): void
    {
        $outlet = $this->outlet();

        $this->assertSame(self::DEFAULTS, $this->settings($outlet));
        $this->assertSame(self::DEFAULTS, app(BillSettingsService::class)->get(null));
        $this->assertSame(0, $this->storedRows());
    }

    public static function refusedValues(): array
    {
        return [
            'negative service charge' => ['service_charge_rate', -1],
            'service charge above 100' => ['service_charge_rate', 101],
            'service charge not a number' => ['service_charge_rate', 'abc'],
            'service charge with 3 decimals' => ['service_charge_rate', 5.125],
            'unknown tax kind' => ['tax_kind', 'vat'],
            'negative PB1 rate' => ['pb1_rate', -0.5],
            'PB1 rate above 100' => ['pb1_rate', 100.01],
            'unknown rounding unit' => ['cash_rounding_unit', 250],
            'rounding to 1000' => ['cash_rounding_unit', 1000],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_rejects_out_of_range_values_on_web(string $field, mixed $value): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        $this->saveOnWeb($editor, $outlet, array_merge(self::CHOSEN, [$field => $value, 'pay_later_enabled' => true]))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Setting::where('outlet_id', $outlet->id)->count());
        $this->assertSame(0, AuditLog::where('module', 'outlets')->count());
    }

    #[DataProvider('refusedValues')]
    public function test_rejects_out_of_range_values_on_api(string $field, mixed $value): void
    {
        $outlet = $this->outlet();
        $this->confirmedApiUser($this->userWith(['outlets-update'], $outlet));

        $this->putJson("/api/v1/settings/outlets/{$outlet->id}", $this->payload($outlet, [$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, $this->storedRows());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_refusal_is_worded_in_both_languages(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        foreach (['id', 'en'] as $locale) {
            $editor->forceFill(['locale' => $locale])->save();

            $this->saveOnWeb($editor, $outlet, ['service_charge_rate' => 101])
                ->assertSessionHasErrors(['service_charge_rate' => trans('messages.outlet_bill.service_charge_rate', [], $locale)]);
        }
        $this->assertNotSame(
            trans('messages.outlet_bill.service_charge_rate', [], 'id'),
            trans('messages.outlet_bill.service_charge_rate', [], 'en'),
        );
    }

    public function test_boundaries_are_accepted(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        $this->saveOnWeb($editor, $outlet, ['service_charge_rate' => 100, 'tax_kind' => 'pb1', 'pb1_rate' => 0, 'cash_rounding_unit' => 100])
            ->assertSessionHasNoErrors();
        $this->assertSame(
            ['service_charge_rate' => 100.0, 'tax_kind' => 'pb1', 'pb1_rate' => 0.0, 'cash_rounding_unit' => 100],
            $this->settings($outlet),
        );

        $this->saveOnWeb($editor, $outlet, ['service_charge_rate' => 0, 'pb1_rate' => 100, 'cash_rounding_unit' => 0])
            ->assertSessionHasNoErrors();
        $this->assertSame(
            ['service_charge_rate' => 0.0, 'tax_kind' => 'pb1', 'pb1_rate' => 100.0, 'cash_rounding_unit' => 0],
            $this->settings($outlet),
        );
    }

    public function test_absent_and_null_fields_are_unchanged(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);
        $this->saveOnWeb($editor, $outlet, self::CHOSEN)->assertSessionHasNoErrors();
        $saved = $this->settings($outlet);

        $this->saveOnWeb($editor, $outlet, [])->assertSessionHasNoErrors();
        $this->saveOnWeb($editor, $outlet, array_fill_keys(self::KEYS, null))->assertSessionHasNoErrors();

        $this->assertSame($saved, $this->settings($outlet));
        $this->assertSame(1, $this->audits()->count());
    }

    public function test_unchanged_save_writes_no_audit(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        // The edit form always submits all four values, so saving the defaults must stay silent.
        $this->saveOnWeb($editor, $outlet, self::DEFAULTS)->assertSessionHasNoErrors();
        $this->assertSame(0, $this->storedRows());
        $this->assertSame(0, $this->audits()->count());

        $this->saveOnWeb($editor, $outlet, ['service_charge_rate' => 5])->assertSessionHasNoErrors();
        $this->saveOnWeb($editor, $outlet, ['service_charge_rate' => '5.00'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->audits()->count());
        $this->assertSame(1, $this->storedRows());
    }

    public function test_change_is_audited_with_old_and_new(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        $this->saveOnWeb($editor, $outlet, ['service_charge_rate' => 7.5] + self::DEFAULTS)->assertSessionHasNoErrors();

        $log = $this->audits()->sole();
        $this->assertSame('outlets', $log->module);
        $this->assertSame($editor->id, $log->user_id);
        $this->assertSame($outlet->id, $log->auditable_id);
        $this->assertNotNull($log->created_at);
        $this->assertSame(['service_charge_rate'], array_keys($log->before));
        $this->assertSame(['service_charge_rate'], array_keys($log->after));
        $this->assertEquals(0, $log->before['service_charge_rate']);
        $this->assertEquals(7.5, $log->after['service_charge_rate']);
        $this->assertSame(1, $this->storedRows());
        $this->assertSame(0, Setting::whereIn('key', self::KEYS)->whereNull('outlet_id')->count());
    }

    public function test_several_keys_one_audit_row(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);

        $this->saveOnWeb($editor, $outlet, self::CHOSEN)->assertSessionHasNoErrors();

        $log = $this->audits()->sole();
        $this->assertEqualsCanonicalizing(self::KEYS, array_keys($log->before));
        $this->assertEquals(self::DEFAULTS, $log->before);
        $this->assertEquals(self::CHOSEN, $log->after);
    }

    public function test_saved_settings_do_not_touch_existing_sales(): void
    {
        $outlet = $this->outlet();
        $editor = $this->userWith(['outlets-update'], $outlet);
        $sale = Transaction::create([
            'cashier_id' => $editor->id,
            'warehouse_id' => $outlet->warehouses()->value('id'),
            'invoice' => 'TRX-BILL-1',
            'cash' => 11100, 'change' => 0, 'discount' => 0, 'grand_total' => 11100,
            'tax_rate' => 11, 'tax_total' => 1100, 'payment_method' => 'cash', 'payment_status' => 'paid',
        ])->fresh();
        $before = $sale->getAttributes();

        $this->saveOnWeb($editor, $outlet, self::CHOSEN)->assertSessionHasNoErrors();

        $this->assertSame($before, $sale->fresh()->getAttributes());
    }

    // BR-2: a sale can keep the bill rules it was computed with; #450 writes them.
    public function test_sale_keeps_the_bill_values_it_is_given(): void
    {
        $values = ['service_charge' => 500, 'tax_kind' => 'pb1', 'tax_base' => 10500];
        $bare = ['cashier_id' => User::factory()->create()->id, 'cash' => 11550, 'change' => 0, 'discount' => 0, 'grand_total' => 11550];

        $sale = Transaction::create($bare + $values + ['invoice' => 'TRX-BILL-2'])->fresh();
        $earlier = Transaction::create($bare + ['invoice' => 'TRX-BILL-3'])->fresh();

        $this->assertSame($values, $sale->only(array_keys($values)));
        $this->assertSame(['service_charge' => 0, 'tax_kind' => null, 'tax_base' => null], $earlier->only(array_keys($values)));
    }

    public function test_only_outlets_update_can_change_bill_settings(): void
    {
        $outlet = $this->outlet();
        $viewer = $this->userWith(['outlets-access', 'dashboard-access'], $outlet);

        $this->saveOnWeb($viewer, $outlet, self::CHOSEN)->assertForbidden();

        $this->assertSame(0, $this->storedRows());
        $this->assertSame(0, $this->audits()->count());
    }

    public function test_api_needs_the_outlets_update_ability(): void
    {
        $outlet = $this->outlet();
        Sanctum::actingAs($this->userWith(['outlets-access'], $outlet), ['outlets-access']);

        $this->putJson("/api/v1/settings/outlets/{$outlet->id}", $this->payload($outlet, self::CHOSEN))
            ->assertForbidden();

        $this->assertSame(0, $this->storedRows());
    }

    public function test_other_outlet_user_gets_404(): void
    {
        $outlet = $this->outlet('AAA');
        $stranger = $this->userWith(['outlets-update'], $this->outlet('BBB'));

        $this->saveOnWeb($stranger, $outlet, self::CHOSEN)->assertNotFound();

        $this->assertSame(0, $this->storedRows());
    }

    public function test_api_update_changes_and_audits(): void
    {
        $outlet = $this->outlet();
        $this->confirmedApiUser($this->userWith(['outlets-update'], $outlet));

        $this->putJson("/api/v1/settings/outlets/{$outlet->id}", $this->payload($outlet, self::CHOSEN))
            ->assertOk();

        $this->assertEquals(self::CHOSEN, $this->settings($outlet));
        $this->assertEquals(self::CHOSEN, $this->audits()->sole()->after);
    }

    public function test_global_rows_are_ignored(): void
    {
        // Deleting an outlet nulls outlet_id on its rows; they must not become every other outlet's settings.
        Setting::set('service_charge_rate', '9');
        Setting::set('tax_kind', 'pb1');
        Setting::set('pb1_rate', '3');
        Setting::set('cash_rounding_unit', '500');

        $this->assertSame(self::DEFAULTS, $this->settings($this->outlet()));
    }

    public function test_outlet_index_and_api_list_expose_bill_settings(): void
    {
        $set = $this->outlet('AAA');
        $unset = $this->outlet('BBB');
        Setting::setForOutlet('tax_kind', 'pb1', $set);
        Setting::setForOutlet('cash_rounding_unit', '100', $set);
        $viewer = $this->userWith(['outlets-access'], $set);
        $viewer->outlets()->attach($unset->id);
        $expected = [
            'AAA' => ['service_charge_rate' => 0, 'tax_kind' => 'pb1', 'pb1_rate' => 10, 'cash_rounding_unit' => 100],
            'BBB' => ['service_charge_rate' => 0, 'tax_kind' => 'ppn', 'pb1_rate' => 10, 'cash_rounding_unit' => 0],
        ];

        $this->withoutVite()->actingAs($viewer)->get(route('settings.outlets.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Settings/Outlets/Index')
                ->where('outlets', fn ($outlets) => collect($outlets)->pluck('bill_settings', 'code')->toArray() == $expected));

        Sanctum::actingAs($viewer, ['outlets-access']);
        $listed = $this->getJson('/api/v1/settings/outlets')->assertOk()->json('data');
        $this->assertEquals($expected, collect($listed)->pluck('bill_settings', 'code')->all());
    }
}
