<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// #312 (K1): a print device asks the server for the kitchen tickets it has not printed yet, and reports what it printed.
class KitchenTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $cook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['dine-orders-prepare', 'dine-orders-handover', 'dine-orders-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        [$this->outlet, $this->warehouse] = $this->outletWithWarehouse('A');
        $this->cook = $this->userWith('dine-orders-prepare', $this->outlet);
    }

    // AC-01
    public function test_a_paid_order_comes_as_a_ticket_with_no_prices(): void
    {
        $sale = $this->sale('INV-DINE', table: 'Meja 5');
        $item = $this->kitchenItem($sale, acceptedAt: now()->subMinutes(2), qty: 3, note: 'tanpa pedas');
        $device = $this->device();

        $response = $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk();

        $ticket = $response->json('tickets.0');
        $this->assertCount(1, $response->json('tickets'));
        $this->assertSame(['key', 'kind', 'root_id', 'number', 'type', 'unpaid', 'since', 'item_ids', 'items'], array_keys($ticket));
        $this->assertSame('t'.$sale->id, $ticket['key']);
        $this->assertSame('Meja 5', $ticket['number']);
        $this->assertSame('dine_in', $ticket['type']);
        $this->assertFalse($ticket['unpaid']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $ticket['since']);
        $this->assertSame([$item->id], $ticket['item_ids']);
        $this->assertSame(
            [['id' => $item->id, 'title' => $sale->details->first()->product->title, 'qty' => 3, 'note' => 'tanpa pedas']],
            $ticket['items']
        );
        $this->assertStringNotContainsStringIgnoringCase('price', $response->getContent());
        $this->assertStringNotContainsString('grand_total', $response->getContent());
    }

    public function test_a_takeaway_order_uses_the_invoice_as_its_number(): void
    {
        $this->kitchenItem($this->sale('INV-TAKEAWAY', orderType: 'takeaway'), acceptedAt: now()->subMinute());

        $ticket = $this->tickets($this->device())[0];

        $this->assertSame('INV-TAKEAWAY', $ticket['number']);
        $this->assertSame('takeaway', $ticket['type']);
    }

    // AC-02, BR-6
    public function test_only_a_pay_later_order_is_marked_unpaid(): void
    {
        $this->kitchenItem($this->sale('INV-PAID'), acceptedAt: now()->subMinutes(2));
        $this->kitchenItem($this->sale('INV-LATER', paymentStatus: 'unpaid'), acceptedAt: now()->subMinute());

        $tickets = $this->tickets($this->device());

        $this->assertSame(['INV-PAID', 'INV-LATER'], array_column($tickets, 'number'));
        $this->assertSame([false, true], array_column($tickets, 'unpaid'));
    }

    // AC-05, BR-8
    public function test_a_printed_item_is_not_offered_again_but_a_second_device_still_gets_it(): void
    {
        $item = $this->kitchenItem($this->sale('INV-ONCE'), acceptedAt: now()->subMinute());
        $a = $this->device('Dapur 1');
        $b = $this->device('Dapur 2');

        $this->assertCount(1, $this->tickets($a));
        $this->assertCount(1, $this->tickets($b));

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $a), ['item_ids' => [$item->id]])->assertNoContent();

        $this->assertSame([], $this->tickets($a));
        $this->assertCount(1, $this->tickets($b));
    }

    // BR-8
    public function test_confirming_twice_records_one_row(): void
    {
        $item = $this->kitchenItem($this->sale('INV-TWICE'), acceptedAt: now()->subMinute());
        $device = $this->device();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$item->id, $item->id]])->assertNoContent();
        }

        $this->assertDatabaseCount('kitchen_ticket_prints', 1);
        $this->assertDatabaseHas('kitchen_ticket_prints', ['kitchen_print_device_id' => $device->id, 'kitchen_item_id' => $item->id]);
    }

    // #366: the table and column rename keeps what was printed, and the keys that guard it, in both directions.
    public function test_the_print_device_rename_keeps_rows_and_keys_both_ways(): void
    {
        $item = $this->kitchenItem($this->sale('INV-RENAME'), acceptedAt: now()->subMinute());
        $spare = $this->kitchenItem($this->sale('INV-RENAME-2'), acceptedAt: now()->subMinute());
        $device = $this->device('Dapur 1');
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$item->id]])->assertNoContent();
        $migration = require database_path('migrations/2026_12_06_100001_rename_kitchen_print_stations_to_devices.php');

        // The keys behind "one name per outlet" and "printed once per device": a lost one would fail silently.
        // The cascade is run on a throwaway device, so the real row stays for the next step.
        $assertKeys = function (string $devices, string $column) use ($device, $spare) {
            $this->assertTrue(Schema::hasIndex($devices, ['outlet_id', 'name'], 'unique'), "$devices: unique outlet + name");
            $this->assertTrue(Schema::hasIndex('kitchen_ticket_prints', [$column, 'kitchen_item_id'], 'unique'), "kitchen_ticket_prints: unique $column + item");
            $this->assertContains($devices, collect(Schema::getForeignKeys('kitchen_ticket_prints'))->pluck('foreign_table')->all());
            $this->assertContains('outlets', collect(Schema::getForeignKeys($devices))->pluck('foreign_table')->all());

            $temp = DB::table($devices)->insertGetId(['outlet_id' => $device->outlet_id, 'name' => 'Cascade', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('kitchen_ticket_prints')->insert([$column => $temp, 'kitchen_item_id' => $spare->id]);
            DB::table($devices)->where('id', $temp)->delete();
            $this->assertDatabaseMissing('kitchen_ticket_prints', ['kitchen_item_id' => $spare->id]);
        };

        $migration->down();

        $this->assertTrue(Schema::hasTable('kitchen_print_stations'));
        $this->assertFalse(Schema::hasTable('kitchen_print_devices'));
        $this->assertDatabaseHas('kitchen_print_stations', ['id' => $device->id, 'name' => 'Dapur 1']);
        $this->assertDatabaseHas('kitchen_ticket_prints', ['kitchen_print_station_id' => $device->id, 'kitchen_item_id' => $item->id]);
        $assertKeys('kitchen_print_stations', 'kitchen_print_station_id');

        $migration->up();

        $this->assertTrue(Schema::hasTable('kitchen_print_devices'));
        $this->assertFalse(Schema::hasTable('kitchen_print_stations'));
        $this->assertDatabaseHas('kitchen_print_devices', ['id' => $device->id, 'name' => 'Dapur 1']);
        $this->assertDatabaseHas('kitchen_ticket_prints', ['kitchen_print_device_id' => $device->id, 'kitchen_item_id' => $item->id]);
        // The printed item is still known as printed (not offered again); the spare one, never printed by this device, is.
        $this->assertSame(['t'.$spare->transaction_id], array_column($this->tickets($device), 'key'));
        $assertKeys('kitchen_print_devices', 'kitchen_print_device_id');

        // And through the app: deleting the device removes its print log.
        $device->delete();
        $this->assertDatabaseCount('kitchen_ticket_prints', 0);
    }

    // BR-9
    public function test_a_print_does_not_change_the_kitchen_item(): void
    {
        $item = $this->kitchenItem($this->sale('INV-STATUS'), acceptedAt: now()->subMinute());
        $device = $this->device();
        $before = $item->fresh()->getAttributes();

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$item->id]])->assertNoContent();

        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    // BR-7
    public function test_tickets_come_oldest_first_whatever_their_id_order(): void
    {
        $newer = $this->sale('INV-NEWER');
        $older = $this->sale('INV-OLDER');
        $this->kitchenItem($newer, acceptedAt: now()->subMinutes(5));
        $this->kitchenItem($older, acceptedAt: now()->subMinutes(20));

        $this->assertSame(['INV-OLDER', 'INV-NEWER'], array_column($this->tickets($this->device()), 'number'));
    }

    public function test_an_order_gets_one_ticket_with_all_its_open_items(): void
    {
        $sale = $this->sale('INV-TWO');
        $first = $this->kitchenItem($sale, acceptedAt: now()->subMinute());
        $second = $this->kitchenItem($sale, acceptedAt: now()->subMinute(), detail: $this->detail($sale, $this->product('Extra')));

        $tickets = $this->tickets($this->device());

        $this->assertCount(1, $tickets);
        $this->assertSame([$first->id, $second->id], $tickets[0]['item_ids']);
        // A one-station shop prints no station title (REQ-006 decision 8).
        $this->assertArrayNotHasKey('station', $tickets[0]);
    }

    public function test_a_backlog_comes_as_the_oldest_30_whole_orders_per_poll(): void
    {
        $device = $this->device(createdAt: now()->subHours(2));

        // The oldest order has 40 items: a cap on items would cut it in two tickets.
        $big = $this->sale('INV-BIG');
        $this->kitchenItem($big, acceptedAt: now()->subHour());
        for ($i = 1; $i < 40; $i++) {
            $this->kitchenItem($big, acceptedAt: now()->subHour(), detail: $this->detail($big, $this->product("Extra $i")));
        }
        for ($i = 1; $i <= 30; $i++) {
            $this->kitchenItem($this->sale("INV-S$i"), acceptedAt: now()->subMinutes(30)->addSeconds($i));
        }

        $first = $this->tickets($device);

        $this->assertCount(30, $first);
        $this->assertSame('INV-BIG', $first[0]['number']);
        $this->assertCount(40, $first[0]['items']);
        $this->assertSame('INV-S29', $first[29]['number']);

        $ids = array_merge(...array_column($first, 'item_ids'));
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => $ids])->assertNoContent();

        $this->assertSame(['INV-S30'], array_column($this->tickets($device), 'number'));
    }

    public function test_an_item_served_before_the_poll_is_not_offered(): void
    {
        $this->kitchenItem($this->sale('INV-SERVED'), status: KitchenItem::STATUS_SERVED, acceptedAt: now()->subMinute());
        $this->kitchenItem($this->sale('INV-READY'), status: KitchenItem::STATUS_READY, acceptedAt: now()->subMinute());

        $this->assertSame(['INV-READY'], array_column($this->tickets($this->device()), 'number'));
    }

    public function test_an_order_older_than_the_device_is_not_offered(): void
    {
        $this->kitchenItem($this->sale('INV-OLD'), acceptedAt: now()->subHour());
        // A release backfill row has no accepted_at: its created_at counts.
        $this->kitchenItem($this->sale('INV-BACKFILLED'), createdAt: now()->subHour(), backfilled: true);
        $this->kitchenItem($this->sale('INV-NEW'), acceptedAt: now()->subMinutes(10));
        $device = $this->device(createdAt: now()->subMinutes(30));

        $this->assertSame(['INV-NEW'], array_column($this->tickets($device), 'number'));
    }

    public function test_only_the_devices_outlet_is_offered(): void
    {
        [, $warehouseB] = $this->outletWithWarehouse('B');
        $this->kitchenItem($this->sale('INV-HERE'), acceptedAt: now()->subMinute());
        $this->kitchenItem($this->sale('INV-THERE', warehouse: $warehouseB), warehouse: $warehouseB, acceptedAt: now()->subMinute());

        $this->assertSame(['INV-HERE'], array_column($this->tickets($this->device()), 'number'));
    }

    // Security: the device is checked against the active outlet
    public function test_a_device_of_another_outlet_is_not_found(): void
    {
        [$outletB, $warehouseB] = $this->outletWithWarehouse('B');
        $item = $this->kitchenItem($this->sale('INV-THERE', warehouse: $warehouseB), warehouse: $warehouseB, acceptedAt: now()->subMinute());
        $foreign = $this->device('Dapur B', outlet: $outletB);

        $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $foreign))->assertNotFound();
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.prints', $foreign), ['item_ids' => [$item->id]])->assertNotFound();
        $this->assertDatabaseCount('kitchen_ticket_prints', 0);
    }

    public function test_a_device_follows_the_active_outlet(): void
    {
        [$outletB] = $this->outletWithWarehouse('B');
        $this->cook->outlets()->attach($outletB->id);
        $foreign = $this->device('Dapur B', outlet: $outletB);

        $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $foreign))->assertNotFound();
        $this->actingAs($this->cook)->withSession(['active_outlet_id' => $outletB->id])
            ->getJson(route('kitchen.print-devices.tickets', $foreign))->assertOk();
    }

    public function test_ids_outside_the_outlet_and_unknown_ids_are_ignored(): void
    {
        [, $warehouseB] = $this->outletWithWarehouse('B');
        $mine = $this->kitchenItem($this->sale('INV-HERE'), acceptedAt: now()->subMinute());
        $theirs = $this->kitchenItem($this->sale('INV-THERE', warehouse: $warehouseB), warehouse: $warehouseB, acceptedAt: now()->subMinute());
        $device = $this->device();

        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$mine->id, $theirs->id, 999999]])
            ->assertNoContent();

        $this->assertDatabaseCount('kitchen_ticket_prints', 1);
        $this->assertDatabaseHas('kitchen_ticket_prints', ['kitchen_print_device_id' => $device->id, 'kitchen_item_id' => $mine->id]);
    }

    public function test_prints_needs_between_one_and_200_integer_ids(): void
    {
        $device = $this->device();
        $url = route('kitchen.print-devices.prints', $device);

        $this->actingAs($this->cook)->postJson($url, [])->assertJsonValidationErrors('item_ids');
        $this->actingAs($this->cook)->postJson($url, ['item_ids' => []])->assertJsonValidationErrors('item_ids');
        $this->actingAs($this->cook)->postJson($url, ['item_ids' => range(1, 201)])->assertJsonValidationErrors('item_ids');
        $this->actingAs($this->cook)->postJson($url, ['item_ids' => ['abc']])->assertJsonValidationErrors('item_ids.0');
    }

    // AC-07
    public function test_a_device_is_saved_for_the_active_outlet(): void
    {
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => 'Dapur 1'])
            ->assertCreated()
            ->assertJsonPath('name', 'Dapur 1')
            ->assertJsonStructure(['id', 'name']);

        $this->assertDatabaseHas('kitchen_print_devices', ['outlet_id' => $this->outlet->id, 'name' => 'Dapur 1']);
    }

    public function test_the_device_name_is_trimmed_and_required(): void
    {
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => '  Dapur 1  '])->assertCreated();
        $this->assertDatabaseHas('kitchen_print_devices', ['name' => 'Dapur 1']);

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => '   '])->assertJsonValidationErrors('name');
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), [])->assertJsonValidationErrors('name');
        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => str_repeat('a', 61)])->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('kitchen_print_devices', 1);
    }

    // BR-2
    public function test_a_device_name_is_unique_per_outlet(): void
    {
        [$outletB] = $this->outletWithWarehouse('B');
        $this->device('Dapur 1', outlet: $outletB);
        $this->device('Dapur 1');

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => ' Dapur 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Nama perangkat cetak sudah dipakai di outlet ini']);
        $this->assertDatabaseCount('kitchen_print_devices', 2);
    }

    // Two devices create the same name at once: both pass the validation, the unique index stops the second.
    public function test_a_concurrent_duplicate_device_name_gets_the_same_422(): void
    {
        $outlet = $this->outlet;
        KitchenPrintDevice::creating(function () use ($outlet) {
            KitchenPrintDevice::query()->insert(['outlet_id' => $outlet->id, 'name' => 'Dapur 1', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => 'Dapur 1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Nama perangkat cetak sudah dipakai di outlet ini']);
        $this->assertDatabaseCount('kitchen_print_devices', 1);
    }

    public function test_the_same_name_is_free_in_another_outlet(): void
    {
        [$outletB] = $this->outletWithWarehouse('B');
        $this->device('Dapur 1', outlet: $outletB);

        $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.store'), ['name' => 'Dapur 1'])->assertCreated();

        $this->assertDatabaseHas('kitchen_print_devices', ['outlet_id' => $this->outlet->id, 'name' => 'Dapur 1']);
        $this->assertDatabaseCount('kitchen_print_devices', 2);
    }

    public function test_a_user_without_an_outlet_cannot_save_a_device(): void
    {
        $this->actingAs($this->userWith('dine-orders-prepare'))
            ->postJson(route('kitchen.print-devices.store'), ['name' => 'Dapur 1'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('kitchen_print_devices', 0);
    }

    // BR-1
    public function test_users_without_prepare_or_handover_are_refused_on_every_route(): void
    {
        $device = $this->device();
        $stranger = $this->userWith('dine-orders-access', $this->outlet);

        $this->actingAs($stranger)->postJson(route('kitchen.print-devices.store'), ['name' => 'Dapur 2'])->assertForbidden();
        $this->actingAs($stranger)->getJson(route('kitchen.print-devices.tickets', $device))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [1]])->assertForbidden();
        $this->assertDatabaseCount('kitchen_print_devices', 1);
    }

    public function test_either_permission_is_enough(): void
    {
        $device = $this->device();

        $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk();
        $this->actingAs($this->userWith('dine-orders-handover', $this->outlet))
            ->getJson(route('kitchen.print-devices.tickets', $device))
            ->assertOk()
            ->assertExactJson(['tickets' => [], 'cancellations' => []]);
    }

    /** @return array<int, array<string, mixed>> */
    // AC-07 / BR-2 (#345): a device prints only its stations' items, each ticket titled.
    public function test_a_device_serves_only_its_stations_and_titles_the_ticket(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $dapur = KitchenStation::defaultId();

        $sale = $this->sale('INV-SPLIT');
        $barItem = $this->kitchenItem($sale);
        $barItem->update(['kitchen_station_id' => $bar->id]);
        $this->kitchenItem($sale)->update(['kitchen_station_id' => $dapur]);

        $device = $this->device();
        $device->stations()->sync([$bar->id]);

        $tickets = $this->tickets($device);

        $this->assertCount(1, $tickets);
        $this->assertSame('Bar', $tickets[0]['station']);
        $this->assertSame([$barItem->id], $tickets[0]['item_ids']);
    }

    public function test_a_two_station_order_prints_one_ticket_per_station(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $dapur = KitchenStation::defaultId();

        $sale = $this->sale('INV-TWO');
        $barItem = $this->kitchenItem($sale);
        $barItem->update(['kitchen_station_id' => $bar->id]);
        $dapurItem = $this->kitchenItem($sale);
        $dapurItem->update(['kitchen_station_id' => $dapur]);

        $device = $this->device();
        $device->stations()->sync([$bar->id, $dapur]);
        $tickets = collect($this->tickets($device))->keyBy('station');

        $this->assertCount(2, $tickets);
        $this->assertSame([$barItem->id], $tickets['Bar']['item_ids']);
        $this->assertSame([$dapurItem->id], $tickets['Dapur']['item_ids']);
        $this->assertSame('t'.$sale->id.'-s'.$bar->id, $tickets['Bar']['key']);
    }

    // AC-07 (#345): a device with no stations chosen in a split shop prints one titled ticket per station, not one
    // combined untitled ticket.
    public function test_an_all_stations_device_prints_one_titled_ticket_per_station(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $dapur = KitchenStation::defaultId();

        $sale = $this->sale('INV-ALL');
        $barItem = $this->kitchenItem($sale);
        $barItem->update(['kitchen_station_id' => $bar->id]);
        $dapurItem = $this->kitchenItem($sale);
        $dapurItem->update(['kitchen_station_id' => $dapur]);

        $tickets = collect($this->tickets($this->device()))->keyBy('station');

        $this->assertCount(2, $tickets);
        $this->assertSame([$barItem->id], $tickets['Bar']['item_ids']);
        $this->assertSame([$dapurItem->id], $tickets['Dapur']['item_ids']);
        $this->assertSame('t'.$sale->id.'-s'.$bar->id, $tickets['Bar']['key']);
    }

    // AC-07 / BR-2 (#345): a device whose chosen stations were all deleted serves Dapur only, never every station.
    public function test_a_device_whose_stations_are_all_deleted_serves_dapur(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $dapur = KitchenStation::defaultId();
        KitchenStation::create(['name' => 'Panggang']);

        $sale = $this->sale('INV-GONE');
        $barItem = $this->kitchenItem($sale);
        $barItem->update(['kitchen_station_id' => $bar->id]);
        $dapurItem = $this->kitchenItem($sale);
        $dapurItem->update(['kitchen_station_id' => $dapur]);
        $panggangItem = $this->kitchenItem($sale);
        $panggangItem->update(['kitchen_station_id' => KitchenStation::where('name', 'Panggang')->value('id')]);

        $device = $this->device();
        $device->stations()->sync([$bar->id]);
        $bar->delete();

        $tickets = $this->tickets($device);

        // The deleted Bar's item counts as Dapur's; the live Panggang station's item must not print here.
        $this->assertCount(1, $tickets);
        $this->assertSame('Dapur', $tickets[0]['station']);
        $this->assertSame([$barItem->id, $dapurItem->id], $tickets[0]['item_ids']);

        $this->actingAs($this->cook)
            ->getJson(route('kitchen.print-devices.show', $device))
            ->assertOk()
            ->assertJsonPath('station_ids', [$dapur]);
    }

    public function test_a_null_station_item_only_prints_on_a_device_that_serves_dapur(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $dapur = KitchenStation::defaultId();

        $sale = $this->sale('INV-NULL');
        $this->kitchenItem($sale)->update(['kitchen_station_id' => null]);

        $barDevice = $this->device('Bar 1');
        $barDevice->stations()->sync([$bar->id]);
        $this->assertSame([], $this->tickets($barDevice));

        $dapurDevice = $this->device('Dapur 1');
        $dapurDevice->stations()->sync([$dapur]);
        $tickets = $this->tickets($dapurDevice);
        $this->assertCount(1, $tickets);
        $this->assertSame('Dapur', $tickets[0]['station']);
    }

    public function test_a_device_stores_its_stations(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $device = $this->device();

        $this->actingAs($this->cook)
            ->putJson(route('kitchen.print-devices.stations', $device), ['station_ids' => [$bar->id]])
            ->assertOk()
            ->assertJson(['station_ids' => [$bar->id]]);

        $this->assertSame([$bar->id], $device->stations()->pluck('kitchen_stations.id')->map(fn ($id) => (int) $id)->all());
    }

    private function tickets(KitchenPrintDevice $device): array
    {
        return $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk()->json('tickets');
    }

    /** A device named earlier than the sales of the test (created_at is the cut-off). */
    private function device(string $name = 'Dapur 1', ?Outlet $outlet = null, ?Carbon $createdAt = null): KitchenPrintDevice
    {
        $device = new KitchenPrintDevice(['outlet_id' => ($outlet ?? $this->outlet)->id, 'name' => $name]);
        $device->created_at = $createdAt ?? now()->subHour();
        $device->save();

        return $device;
    }

    /** @return array{Outlet, Warehouse} */
    private function outletWithWarehouse(string $code): array
    {
        $outlet = Outlet::create(['code' => 'OUT-'.$code, 'name' => 'Outlet '.$code, 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouse = Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => 'WH-'.$code, 'name' => 'Gudang '.$code, 'type' => 'branch', 'is_active' => true,
        ]);

        return [$outlet, $warehouse];
    }

    private function userWith(string $permission, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        if ($outlet) {
            $user->outlets()->attach($outlet->id, ['is_default' => true]);
        }

        return $user;
    }

    private function product(string $title): Product
    {
        return Product::create([
            'title' => $title, 'sku' => 'SKU-'.++$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => true,
        ]);
    }

    /** A sale with one line, optionally tied to a dine-in table. */
    private function sale(
        string $invoice,
        ?string $table = null,
        ?string $orderType = null,
        string $paymentStatus = 'paid',
        ?Warehouse $warehouse = null,
    ): Transaction {
        $transaction = Transaction::create(array_filter([
            'cashier_id' => $this->cook->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => $invoice,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
            'order_type' => $orderType,
        ], fn ($value) => $value !== null));

        $this->detail($transaction, $this->product('Menu '.$invoice));

        if ($table) {
            DineOrder::create([
                'dine_table_id' => DiningTable::create(['name' => $table, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
                'transaction_id' => $transaction->id,
                'status' => DineOrder::STATUS_ACCEPTED,
                'payment_option' => DineOrder::PAY_AT_COUNTER,
                'subtotal' => 10000,
                'item_count' => 1,
            ]);
        }

        return $transaction->load('details.product');
    }

    private function detail(Transaction $transaction, Product $product): TransactionDetail
    {
        return TransactionDetail::create([
            'transaction_id' => $transaction->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);
    }

    /** One kitchen item, for the sale's first line unless a line is given, as intake would have created it. */
    private function kitchenItem(
        Transaction $sale,
        ?Warehouse $warehouse = null,
        string $status = KitchenItem::STATUS_ACCEPTED,
        ?Carbon $acceptedAt = null,
        ?Carbon $createdAt = null,
        ?string $note = null,
        int $qty = 1,
        bool $backfilled = false,
        ?TransactionDetail $detail = null,
    ): KitchenItem {
        $detail ??= $sale->details->first();
        $item = new KitchenItem([
            'transaction_id' => $sale->id,
            'transaction_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'qty' => $qty,
            'status' => $status,
            'accepted_at' => $backfilled ? null : ($acceptedAt ?? now()),
        ] + ($note ? ['note' => $note] : []));
        if ($createdAt) {
            $item->created_at = $createdAt;
        }
        $item->save();

        return $item;
    }
}
