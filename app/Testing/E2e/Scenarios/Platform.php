<?php

namespace App\Testing\E2e\Scenarios;

use App\Http\Controllers\TourController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Scenarios of the platform administration group (e2e folders 10-19): own users, the password reset
 * link, the empty-install state for the setup wizard, a low-stock product.
 */
class Platform
{
    /** Settings the setup wizard overwrites; restored by platform.setup-restore. */
    private const WIZARD_SETTINGS = ['store_name', 'store_address', 'store_phone', 'store_email', 'store_business_type'];

    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // A user of its own, so a step can change its name, password or access without touching the
            // shared role users. --arg=role (default manager), email, name, tours=none|done (default done).
            'platform.user' => function (array $options): array {
                $role = $options['role'] ?? 'manager';
                $outlet = Outlet::where('code', E2eSeeder::OUTLET_CODE)->firstOrFail();
                $pusat = Outlet::where('code', 'PUSAT')->first();

                $user = User::create([
                    'name' => $options['name'] ?? 'Platform '.Str::upper(Str::random(5)),
                    'email' => $options['email'] ?? 'platform-'.Str::lower(Str::random(8)).'@e2e.test',
                    'password' => Hash::make($options['password'] ?? E2eSeeder::PASSWORD),
                    'completed_tours' => ($options['tours'] ?? 'done') === 'none' ? null : TourController::TOURS,
                ]);
                $user->markEmailAsVerified();
                $user->assignRole($role);

                $outlets = $role === 'super-admin' && $pusat ? [$outlet, $pusat] : [$outlet];
                $user->outlets()->sync(collect($outlets)->mapWithKeys(
                    fn (Outlet $o) => [$o->id => ['is_default' => $o->is($outlet)]]
                )->all());

                return ['id' => $user->id, 'email' => $user->email, 'name' => $user->name];
            },

            // A role named --arg=name with the permissions in --arg=permissions (comma separated names).
            'platform.role' => function (array $options): array {
                $role = Role::create(['name' => $options['name'], 'guard_name' => 'web']);
                $role->givePermissionTo(array_filter(explode(',', $options['permissions'] ?? '')));

                return ['role_id' => $role->id, 'name' => $role->name];
            },

            // What the database holds for --arg=email, to assert a save that the page does not show.
            'platform.user-state' => function (array $options): array {
                $user = User::where('email', $options['email'] ?? '')->firstOrFail();

                return ['completed_tours' => $user->completed_tours, 'locale' => $user->locale, 'name' => $user->name];
            },

            // The newest password reset link in the mail log (MAIL_MAILER=log) for --arg=email.
            'platform.reset-link' => function (array $options): array {
                $files = glob(storage_path('logs/*.log')) ?: [];
                usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
                $log = $files === [] ? '' : (string) file_get_contents($files[0]);
                $log = quoted_printable_decode(substr($log, -400000));

                $email = rawurlencode($options['email'] ?? '');
                preg_match_all('#/reset-password/([A-Za-z0-9]+)\?email='.preg_quote($email, '#').'#', $log, $matches);
                $token = end($matches[1]);
                if (! $token) {
                    throw new \RuntimeException('No reset link in the mail log for '.($options['email'] ?? ''));
                }

                return ['token' => $token, 'path' => "/reset-password/{$token}?email={$email}"];
            },

            // Makes the app look freshly installed (the wizard shows again) and remembers how to undo it.
            'platform.setup-pending' => function (array $options): array {
                $backup = [
                    'settings' => collect(self::WIZARD_SETTINGS)->mapWithKeys(fn ($key) => [$key => Setting::get($key)])->all(),
                    'max' => [
                        'users' => (int) User::max('id'),
                        'outlets' => (int) Outlet::max('id'),
                        'warehouses' => (int) Warehouse::max('id'),
                        'categories' => (int) Category::max('id'),
                    ],
                ];
                Setting::set('e2e_setup_backup', json_encode($backup));
                Setting::set('app_setup_completed', false);

                return ['pending' => true];
            },

            // Puts the baseline back after the wizard ran: setting values, and what the wizard created.
            'platform.setup-restore' => function (array $options): array {
                $backup = json_decode((string) Setting::get('e2e_setup_backup'), true);
                if (! $backup) {
                    throw new \RuntimeException('platform.setup-pending has not run.');
                }

                DB::transaction(function () use ($backup) {
                    $userIds = User::where('id', '>', $backup['max']['users'])->pluck('id');
                    DB::table('user_outlets')->whereIn('user_id', $userIds)->delete();
                    DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
                    User::whereIn('id', $userIds)->delete();

                    $warehouseIds = Warehouse::where('id', '>', $backup['max']['warehouses'])->pluck('id');
                    DB::table('product_warehouse')->whereIn('warehouse_id', $warehouseIds)->delete();
                    Warehouse::whereIn('id', $warehouseIds)->delete();
                    Outlet::where('id', '>', $backup['max']['outlets'])->delete();
                    Category::where('id', '>', $backup['max']['categories'])->delete();

                    foreach ($backup['settings'] as $key => $value) {
                        Setting::set($key, $value ?? '');
                    }
                    Setting::set('app_setup_completed', true);
                });

                return ['restored' => true];
            },

            // A product that is low on stock in the E2E warehouse (stock 2, minimum 5): the bell lists it.
            'platform.low-stock-product' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $category = Category::where('name', 'E2E Kategori')->firstOrFail();

                $product = DB::transaction(function () use ($options, $warehouse, $category) {
                    $product = Product::create([
                        'category_id' => $category->id,
                        'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                        'title' => $options['title'] ?? 'Produk Menipis '.Str::upper(Str::random(5)),
                        'buy_price' => 5000,
                        'sell_price' => 10000,
                        'stock' => 2,
                        'min_stock' => 5,
                        'tax_type' => 'exclusive',
                        'tax_rate' => 0,
                    ]);
                    $warehouse->products()->attach($product->id, ['stock' => 2]);

                    return $product;
                });

                return ['product_id' => $product->id, 'title' => $product->title];
            },

            // A customer named --arg=name for the export step; platform.drop-customer removes it again.
            'platform.customer' => function (array $options): array {
                $customer = Customer::create(['name' => $options['name'], 'no_telp' => '081200000001', 'address' => 'Jl. Impor 1']);

                return ['customer_id' => $customer->id];
            },

            'platform.drop-customer' => function (array $options): array {
                Customer::where('name', $options['name'])->delete();

                return ['dropped' => true];
            },

            // Removes a product made by platform.low-stock-product so the bell stays empty for other steps.
            'platform.drop-product' => function (array $options): array {
                $product = Product::findOrFail($options['product_id']);
                DB::table('product_notification_reads')->where('product_id', $product->id)->delete();
                DB::table('product_warehouse')->where('product_id', $product->id)->delete();
                $product->delete();

                return ['dropped' => true];
            },
        ];
    }
}
