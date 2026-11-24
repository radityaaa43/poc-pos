<?php

namespace Tests\Feature\E2e;

use App\Http\Controllers\TourController;
use App\Models\CashierShift;
use App\Models\Product;
use App\Models\User;
use App\Testing\E2e\E2eGuard;
use App\Testing\E2e\E2eServiceProvider;
use App\Testing\E2e\Scenarios;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class E2eGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_e2e_provider_and_commands_are_absent_outside_the_e2e_environment(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertArrayNotHasKey(E2eServiceProvider::class, app()->getLoadedProviders());
        $this->assertArrayNotHasKey('e2e:reset', Artisan::all());
        $this->assertArrayNotHasKey('e2e:arrange', Artisan::all());
    }

    public function test_guard_refuses_outside_e2e_or_on_a_non_e2e_database(): void
    {
        $this->assertNotNull(E2eGuard::refusal('local', 'point_of_sales_e2e'));
        $this->assertNotNull(E2eGuard::refusal('production', 'point_of_sales_e2e'));
        $this->assertNotNull(E2eGuard::refusal('e2e', 'point_of_sales'));
        $this->assertNotNull(E2eGuard::refusal('e2e', 'pos_review_1'));
        $this->assertNotNull(E2eGuard::refusal('e2e', ':memory:'));
        $this->assertNotNull(E2eGuard::refusal('e2e', null));
        $this->assertNotNull(E2eGuard::refusal('e2e', 'x`; DROP DATABASE y; --_e2e'));
        $this->assertNotNull(E2eGuard::refusal('e2e', "point_of_sales_e2e\n"));
        $this->assertNull(E2eGuard::refusal('e2e', 'point_of_sales_e2e'));
        $this->assertNotNull(E2eGuard::check(), 'the testing environment must be refused');
    }

    public function test_seeder_refuses_outside_e2e_and_testing(): void
    {
        foreach (['local', 'production'] as $environment) {
            $this->app['env'] = $environment;

            try {
                app(E2eSeeder::class)->run();
                $this->fail("E2eSeeder ran in the '{$environment}' environment.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("APP_ENV is '{$environment}'", $e->getMessage());
            }
        }

        $this->assertSame(0, User::where('email', 'like', '%@e2e.test')->count());
    }

    public function test_seeder_builds_the_baseline(): void
    {
        $this->seed([DatabaseSeeder::class, E2eSeeder::class]);
        $this->seed(E2eSeeder::class); // idempotent

        $this->assertSame(count(E2eSeeder::USERS), User::where('email', 'like', '%@e2e.test')->count());
        $this->assertSame(3, Product::where('barcode', 'like', 'E2E-%')->count());

        $cashier = User::where('email', 'cashier@e2e.test')->first();
        $this->assertTrue($cashier->hasRole('cashier'));
        $this->assertSame(count(TourController::TOURS), count($cashier->completed_tours));
        $this->assertSame(1, $cashier->outlets()->count());
        $this->assertTrue(User::where('email', 'superadmin@e2e.test')->first()->isSuperAdmin());
    }

    public function test_arrange_scenarios_create_data_and_are_repeatable(): void
    {
        $this->seed([DatabaseSeeder::class, E2eSeeder::class]);
        $registry = Scenarios::registry();

        $registry['open-shift'](['cash' => '50000']);
        $second = $registry['open-shift']([]);
        $this->assertSame(1, CashierShift::open()->count());
        $this->assertSame($second['shift_id'], CashierShift::open()->value('id'));

        $created = $registry['product'](['stock' => '7', 'title' => 'Arranged']);
        $this->assertSame(7, Product::find($created['product_id'])->stock);
        $this->assertSame(7, (int) Product::find($created['product_id'])->warehouses()->sum('product_warehouse.stock'));
    }
}
