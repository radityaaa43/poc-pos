<?php

namespace Database\Seeders;

use App\Http\Controllers\TourController;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic baseline for the Playwright suite. Runs after DatabaseSeeder (roles, PUSAT).
 * The user list is mirrored by tests/e2e/support/roles.js.
 */
class E2eSeeder extends Seeder
{
    public const OUTLET_CODE = 'E2E';

    public const WAREHOUSE_CODE = 'WH-E2E';

    public const PASSWORD = 'password';

    public const WHATSAPP_URL = 'http://wa.e2e.test';

    public const MIDTRANS_SERVER_KEY = 'SB-Mid-server-e2e';

    public const XENDIT_CALLBACK_TOKEN = 'e2e-xendit-callback-token';

    /** email => [name, role] */
    public const USERS = [
        'superadmin@e2e.test' => ['E2E Super Admin', 'super-admin'],
        'manager@e2e.test' => ['E2E Manager', 'manager'],
        'cashier@e2e.test' => ['E2E Cashier', 'cashier'],
        'opname@e2e.test' => ['E2E Opname', 'stock-opnames-access'],
        'dineorders@e2e.test' => ['E2E Dine Orders', 'dine-orders-access'],
    ];

    public function run(): void
    {
        $outlet = Outlet::updateOrCreate(
            ['code' => self::OUTLET_CODE],
            ['name' => 'Outlet E2E', 'is_active' => true, 'is_sales_enabled' => true],
        );

        $warehouse = Warehouse::updateOrCreate(
            ['code' => self::WAREHOUSE_CODE],
            ['outlet_id' => $outlet->id, 'name' => 'Gudang E2E', 'type' => 'branch', 'is_active' => true, 'sort_order' => 1],
        );

        Setting::set('app_setup_completed', true);
        Setting::set('wa_service_url', self::WHATSAPP_URL);
        Setting::set('wa_enabled', '1');

        $this->seedUsers($outlet);
        $this->seedProducts($warehouse);
        $this->seedPaymentSettings();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedUsers(Outlet $outlet): void
    {
        $pusat = Outlet::where('code', 'PUSAT')->first();

        foreach (self::USERS as $email => [$name, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make(self::PASSWORD),
                    // Every tour done, otherwise a driver.js overlay intercepts clicks.
                    'completed_tours' => TourController::TOURS,
                ],
            );
            $user->markEmailAsVerified();
            $user->syncRoles([$role]);

            $outlets = $role === 'super-admin' && $pusat ? [$outlet, $pusat] : [$outlet];
            $user->outlets()->sync(collect($outlets)->mapWithKeys(
                fn (Outlet $o) => [$o->id => ['is_default' => $o->is($outlet)]]
            )->all());
        }
    }

    private function seedProducts(Warehouse $warehouse): void
    {
        $category = Category::firstOrCreate(['name' => 'E2E Kategori'], ['description' => 'Kategori data uji e2e']);

        DB::transaction(function () use ($category, $warehouse) {
            foreach ([1 => 10000, 2 => 15000, 3 => 25000] as $n => $price) {
                $product = Product::firstOrCreate(
                    ['barcode' => "E2E-00{$n}"],
                    [
                        'category_id' => $category->id,
                        'title' => "Produk E2E {$n}",
                        'buy_price' => $price / 2,
                        'sell_price' => $price,
                        'stock' => 50,
                        'tax_type' => 'exclusive',
                        'tax_rate' => 0,
                    ],
                );

                if ($product->wasRecentlyCreated) {
                    $warehouse->products()->attach($product->id, ['stock' => 50]);
                }
            }
        });
    }

    private function seedPaymentSettings(): void
    {
        PaymentSetting::forOutletOrCreate(null)->update([
            'midtrans_enabled' => true,
            'midtrans_server_key' => self::MIDTRANS_SERVER_KEY,
            'midtrans_client_key' => 'SB-Mid-client-e2e',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xnd_development_e2e',
            'xendit_public_key' => 'xnd_public_development_e2e',
            'xendit_callback_token' => self::XENDIT_CALLBACK_TOKEN,
        ]);
    }
}
