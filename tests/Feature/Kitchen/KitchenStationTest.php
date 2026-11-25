<?php

namespace Tests\Feature\Kitchen;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\KitchenStationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

// REQ-006 AC-01, AC-03, BR-1, BR-2, BR-4, BR-5: the shop's kitchen stations are managed under products-edit.
class KitchenStationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->manager = User::where('email', 'manager@gmail.com')->first();
        $this->manager->markEmailAsVerified();
        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
    }

    private function dapur(): KitchenStation
    {
        return KitchenStation::where('is_default', true)->firstOrFail();
    }

    private function product(?KitchenStation $station = null): Product
    {
        $n = ++$this->seq;

        return Product::create([
            'title' => "Produk $n", 'sku' => "SKU-KS-$n", 'barcode' => "BC-KS-$n", 'description' => 'x',
            'image' => 'products/t.jpg', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'tax_rate' => 0, 'category_id' => $this->category->id, 'needs_preparation' => true,
            'kitchen_station_id' => $station?->id,
        ]);
    }

    private function kitchenItem(Product $product, KitchenStation $station): KitchenItem
    {
        $n = ++$this->seq;
        $warehouse = Warehouse::first() ?? Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $transaction = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $warehouse->id, 'invoice' => "TRX-KS-$n",
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $detail = $transaction->details()->create(['product_id' => $product->id, 'qty' => 1, 'price' => 10000]);

        return KitchenItem::create([
            'transaction_id' => $transaction->id, 'transaction_detail_id' => $detail->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'kitchen_station_id' => $station->id, 'qty' => 1,
            'status' => KitchenItem::STATUS_PREPARING,
        ]);
    }

    public function test_manager_sees_the_stations_default_first(): void
    {
        KitchenStation::create(['name' => 'Bar']);
        KitchenStation::create(['name' => 'Aneka']);

        $this->actingAs($this->manager)
            ->get(route('settings.kitchen-stations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Settings/KitchenStations')
                ->has('stations', 3)
                ->where('stations.0.name', 'Dapur')
                ->where('stations.0.is_default', true)
                ->where('stations.1.name', 'Aneka')
                ->where('stations.2.name', 'Bar')
                ->has('stations.0', fn (Assert $row) => $row->hasAll(['id', 'name', 'is_default'])));
    }

    public function test_store_adds_a_station_and_logs_it(): void
    {
        $this->actingAs($this->manager)
            ->post(route('settings.kitchen-stations.store'), ['name' => 'Bar'])
            ->assertSessionHasNoErrors();

        $station = KitchenStation::where('name', 'Bar')->firstOrFail();
        $this->assertFalse($station->is_default);

        $log = AuditLog::where('event', 'kitchen_station.created')->sole();
        $this->assertSame('kitchen_stations', $log->module);
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame($station->id, (int) $log->auditable_id);
        $this->assertSame(['name' => 'Bar'], $log->after);
    }

    public function test_store_never_takes_is_default_from_the_request(): void
    {
        $this->actingAs($this->manager)
            ->post(route('settings.kitchen-stations.store'), ['name' => 'Bar', 'is_default' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, KitchenStation::where('is_default', true)->count());
    }

    public function test_store_refuses_a_duplicate_name_ignoring_case_and_spaces(): void
    {
        KitchenStation::create(['name' => 'Bar']);

        foreach (['Bar', 'bar ', 'BAR', ' bar', 'dapur'] as $name) {
            $this->actingAs($this->manager)
                ->post(route('settings.kitchen-stations.store'), ['name' => $name])
                ->assertSessionHasErrors(['name' => __('messages.kitchen_stations.name_taken')]);
        }

        $this->assertSame(2, KitchenStation::count());
        $this->assertSame(0, AuditLog::where('event', 'kitchen_station.created')->count());
    }

    public function test_store_refuses_an_empty_or_blank_name(): void
    {
        foreach (['', '   '] as $name) {
            $this->actingAs($this->manager)
                ->post(route('settings.kitchen-stations.store'), ['name' => $name])
                ->assertSessionHasErrors('name');
        }

        $this->actingAs($this->manager)
            ->post(route('settings.kitchen-stations.store'), ['name' => str_repeat('a', 101)])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, KitchenStation::count());
    }

    public function test_update_renames_keeping_the_id_and_logs_it(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);

        $this->actingAs($this->manager)
            ->put(route('settings.kitchen-stations.update', $bar), ['name' => 'Minuman'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('kitchen_stations', ['id' => $bar->id, 'name' => 'Minuman']);
        $log = AuditLog::where('event', 'kitchen_station.updated')->sole();
        $this->assertSame(['name' => 'Bar'], $log->before);
        $this->assertSame(['name' => 'Minuman'], $log->after);
    }

    public function test_update_refuses_a_name_used_by_another_station(): void
    {
        KitchenStation::create(['name' => 'Bar']);
        $grill = KitchenStation::create(['name' => 'Grill']);

        $this->actingAs($this->manager)
            ->put(route('settings.kitchen-stations.update', $grill), ['name' => ' bar'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Grill', $grill->fresh()->name);
    }

    public function test_update_to_its_own_name_is_allowed_and_logs_nothing(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);

        $this->actingAs($this->manager)
            ->put(route('settings.kitchen-stations.update', $bar), ['name' => 'Bar'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('event', 'kitchen_station.updated')->count());
    }

    public function test_the_default_station_can_be_renamed_and_stays_the_default(): void
    {
        $dapur = $this->dapur();

        $this->actingAs($this->manager)
            ->put(route('settings.kitchen-stations.update', $dapur), ['name' => 'Dapur Utama'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('kitchen_stations', ['id' => $dapur->id, 'name' => 'Dapur Utama', 'is_default' => true]);
    }

    public function test_a_deleted_name_can_be_reused_but_two_live_stations_cannot_share_one(): void
    {
        $old = KitchenStation::create(['name' => 'Bar']);

        $this->actingAs($this->manager)->delete(route('settings.kitchen-stations.destroy', $old))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)
            ->post(route('settings.kitchen-stations.store'), ['name' => 'Bar'])
            ->assertSessionHasNoErrors();

        $new = KitchenStation::where('name', 'Bar')->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSoftDeleted('kitchen_stations', ['id' => $old->id]);

        // The database itself refuses a second live "Bar" (a race that passed the name check).
        $this->expectException(UniqueConstraintViolationException::class);
        KitchenStation::create(['name' => 'Bar']);
    }

    public function test_the_service_turns_a_lost_duplicate_race_into_the_name_error(): void
    {
        KitchenStation::create(['name' => 'Bar']);

        try {
            app(KitchenStationService::class)->create('Bar');
            $this->fail('A duplicate name must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame([__('messages.kitchen_stations.name_taken')], $e->errors()['name']);
        }
    }

    public function test_destroy_moves_its_products_to_the_default_and_soft_deletes(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $grill = KitchenStation::create(['name' => 'Grill']);
        $inBar = $this->product($bar);
        $inGrill = $this->product($grill);
        $unmapped = $this->product();

        $this->actingAs($this->manager)
            ->delete(route('settings.kitchen-stations.destroy', $bar))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('kitchen_stations', ['id' => $bar->id]);
        $this->assertNull($inBar->fresh()->kitchen_station_id);
        $this->assertSame($grill->id, $inGrill->fresh()->kitchen_station_id);
        $this->assertNull($unmapped->fresh()->kitchen_station_id);

        $log = AuditLog::where('event', 'kitchen_station.deleted')->sole();
        $this->assertSame('kitchen_stations', $log->module);
        $this->assertSame(['name' => 'Bar'], $log->before);
        $this->assertSame(['products_moved' => 1], $log->meta);
    }

    public function test_destroy_refuses_the_default_station(): void
    {
        $dapur = $this->dapur();

        $this->actingAs($this->manager)
            ->delete(route('settings.kitchen-stations.destroy', $dapur))
            ->assertSessionHasErrors(['station' => __('messages.kitchen_stations.default_locked')]);

        $this->assertNotSoftDeleted('kitchen_stations', ['id' => $dapur->id]);
        $this->assertSame(0, AuditLog::where('event', 'kitchen_station.deleted')->count());
    }

    public function test_destroy_leaves_kitchen_items_alone_and_their_station_still_resolves(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $item = $this->kitchenItem($this->product($bar), $bar);

        $this->actingAs($this->manager)->delete(route('settings.kitchen-stations.destroy', $bar))->assertSessionHasNoErrors();

        $item = $item->fresh();
        $this->assertSame($bar->id, $item->kitchen_station_id);
        $this->assertSame(KitchenItem::STATUS_PREPARING, $item->status);
        $this->assertSame('Bar', $item->kitchenStation->name);
    }

    public function test_a_second_delete_changes_nothing(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $inBar = $this->product($bar);

        $this->actingAs($this->manager)->delete(route('settings.kitchen-stations.destroy', $bar))->assertSessionHasNoErrors();
        // The station is mapped again between the two clicks: the second call must not touch it.
        $inBar->forceFill(['kitchen_station_id' => $bar->id])->save();
        $this->actingAs($this->manager)->delete(route('settings.kitchen-stations.destroy', $bar))->assertNotFound();

        $this->assertSame($bar->id, $inBar->fresh()->kitchen_station_id);
        $this->assertSame(1, AuditLog::where('event', 'kitchen_station.deleted')->count());

        // A caller that already holds the model (a lost race) gets the same answer from the service.
        $this->expectException(ModelNotFoundException::class);
        app(KitchenStationService::class)->delete($bar);
    }

    public function test_a_failed_audit_rolls_the_whole_delete_back(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $inBar = $this->product($bar);
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new RuntimeException('audit down'));

        try {
            app(KitchenStationService::class)->delete($bar);
            $this->fail('The audit failure must surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }

        $this->assertNotSoftDeleted('kitchen_stations', ['id' => $bar->id]);
        $this->assertSame($bar->id, $inBar->fresh()->kitchen_station_id);
        $this->assertSame(0, (int) $bar->fresh()->deleted_key);
    }

    public function test_users_without_products_edit_get_403_on_every_route(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);

        $this->actingAs($this->cashier)->get(route('settings.kitchen-stations.index'))->assertForbidden();
        $this->actingAs($this->cashier)->post(route('settings.kitchen-stations.store'), ['name' => 'Grill'])->assertForbidden();
        $this->actingAs($this->cashier)->put(route('settings.kitchen-stations.update', $bar), ['name' => 'Grill'])->assertForbidden();
        $this->actingAs($this->cashier)->delete(route('settings.kitchen-stations.destroy', $bar))->assertForbidden();

        $this->assertSame(['Dapur', 'Bar'], KitchenStation::orderBy('id')->pluck('name')->all());
    }
}
