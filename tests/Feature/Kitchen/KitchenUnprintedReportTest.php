<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\KitchenStation;
use App\Models\KitchenTicketPrint;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// #555: the unprinted kitchen tickets report, read from kitchen_items and the print log.
class KitchenUnprintedReportTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-05-10';

    private User $user;

    private User $cook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        foreach (['reports-access', 'dine-orders-prepare'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        [$this->outlet, $this->warehouse] = $this->outletWithWarehouse('A');
        $this->user = $this->userWith('reports-access', $this->outlet);
        $this->cook = $this->userWith('dine-orders-prepare', $this->outlet);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_a_ticket_no_device_printed_is_listed_with_number_station_device_and_time(): void
    {
        $station = $this->station('Bar');
        $this->device('Dapur 1');
        $this->kitchenItem($this->sale('INV-ONE'), acceptedAt: $this->at('10:00'), station: $station->id);

        $props = $this->page();

        $ticket = $props['tickets'][0];
        $this->assertSame(['key', 'date', 'time', 'number', 'type', 'station', 'devices', 'items'], array_keys($ticket));
        $this->assertSame('INV-ONE', $ticket['number']);
        $this->assertSame('Bar', $ticket['station']);
        $this->assertSame(['Dapur 1'], $ticket['devices']);
        $this->assertSame(1, $ticket['items']);
        $this->assertSame(self::DAY, $ticket['date']);
        $this->assertStringStartsWith(self::DAY.'T10:00:00', $ticket['time']);
        $this->assertSame(1, $props['total']);
        $this->assertSame([['date' => self::DAY, 'count' => 1]], $props['days']);
        $this->assertFalse($props['truncated']);
    }

    public function test_a_ticket_printed_later_through_coba_lagi_leaves_the_report(): void
    {
        $device = $this->device();
        $item = $this->kitchenItem($this->sale('INV-RETRY'), acceptedAt: $this->at('10:00'));

        $this->assertSame(1, $this->page()['total']);

        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$item->id]])
            ->assertNoContent();

        $this->assertSame(0, $this->page()['total']);
    }

    public function test_a_reprint_never_counts_because_it_writes_no_row(): void
    {
        $device = $this->device();
        $printed = $this->kitchenItem($this->sale('INV-PRINTED'), acceptedAt: $this->at('10:00'));
        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.prints', $device), ['item_ids' => [$printed->id]])
            ->assertNoContent();
        $stillUnprinted = $this->kitchenItem($this->sale('INV-UNPRINTED'), acceptedAt: $this->at('10:00'));

        // Cetak ulang is client-side and records nothing, so it can neither add a row nor hide an unprinted ticket.
        $this->assertFalse(Route::has('kitchen.print-devices.reprints'));
        $this->assertSame(1, KitchenTicketPrint::where('kitchen_item_id', $printed->id)->count());
        $this->assertSame(['INV-UNPRINTED'], array_column($this->page()['tickets'], 'number'));
        $this->assertSame(0, KitchenTicketPrint::where('kitchen_item_id', $stillUnprinted->id)->count());
    }

    public function test_a_ticket_printed_on_any_device_is_not_listed(): void
    {
        $first = $this->device('Dapur 1');
        $this->device('Dapur 2');
        $item = $this->kitchenItem($this->sale('INV-ANY'), acceptedAt: $this->at('10:00'));

        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.prints', $first), ['item_ids' => [$item->id]])
            ->assertNoContent();

        $this->assertSame(0, $this->page()['total']);
    }

    public function test_a_ticket_younger_than_thirty_seconds_is_not_listed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-29 12:00:00'));
        $this->device();
        $this->kitchenItem($this->sale('INV-29S'), acceptedAt: now()->subSeconds(29));
        $this->kitchenItem($this->sale('INV-31S'), acceptedAt: now()->subSeconds(31));

        $props = $this->page(['start_date' => '2026-11-29', 'end_date' => '2026-11-29']);

        $this->assertSame(['INV-31S'], array_column($props['tickets'], 'number'));
    }

    public function test_items_from_before_the_first_device_are_not_listed(): void
    {
        $this->device('Dapur 1', createdAt: $this->at('12:00'));
        $this->kitchenItem($this->sale('INV-BEFORE'), acceptedAt: $this->at('10:00'));
        $this->kitchenItem($this->sale('INV-AFTER'), acceptedAt: $this->at('13:00'));

        $this->assertSame(['INV-AFTER'], array_column($this->page()['tickets'], 'number'));
    }

    public function test_an_outlet_without_a_print_device_has_no_unprinted_tickets(): void
    {
        $this->kitchenItem($this->sale('INV-NODEVICE'), acceptedAt: $this->at('10:00'));

        $this->assertSame(0, $this->page()['total']);
    }

    public function test_a_cancelled_item_is_not_listed(): void
    {
        $this->device();
        $this->kitchenItem($this->sale('INV-CANCELLED'), status: KitchenItem::STATUS_CANCELLED, acceptedAt: $this->at('10:00'));

        $this->assertSame(0, $this->page()['total']);
    }

    public function test_a_served_item_that_never_printed_is_still_listed(): void
    {
        $this->device();
        $this->kitchenItem($this->sale('INV-SERVED'), status: KitchenItem::STATUS_SERVED, acceptedAt: $this->at('10:00'));

        $this->assertSame(['INV-SERVED'], array_column($this->page()['tickets'], 'number'));
    }

    public function test_the_created_at_fallback_only_meets_a_row_without_accepted_at(): void
    {
        $this->device('Dapur 1', createdAt: $this->at('05-09 00:00'));
        // Legacy (release-carried): no accepted_at, so created_at dates it.
        $this->kitchenItem($this->sale('INV-LEGACY'), createdAt: $this->at('10:00'), backfilled: true);
        // New row accepted on a different day than it was created: accepted_at wins.
        $this->kitchenItem($this->sale('INV-ACCEPTED'), acceptedAt: $this->at('10:00'), createdAt: $this->at('05-09 10:00'));
        $this->kitchenItem($this->sale('INV-NEXTDAY'), acceptedAt: Carbon::parse('2026-05-11 10:00:00'));

        $this->assertEqualsCanonicalizing(['INV-LEGACY', 'INV-ACCEPTED'], array_column($this->page()['tickets'], 'number'));
    }

    public function test_one_row_per_order_and_station(): void
    {
        $this->device();
        $bar = $this->station('Bar');
        $grill = $this->station('Grill');
        $order = $this->sale('INV-TWOSTATIONS');
        $this->kitchenItem($order, acceptedAt: $this->at('10:00'), station: $bar->id);
        $this->kitchenItem($order, acceptedAt: $this->at('10:00'), station: $grill->id, detail: $this->detail($order, $this->product('Menu 2')));

        $tickets = collect($this->page()['tickets'])->keyBy('station');

        $this->assertEqualsCanonicalizing(['Bar', 'Grill'], $tickets->keys()->all());
        $this->assertSame(['INV-TWOSTATIONS', 'INV-TWOSTATIONS'], $tickets->pluck('number')->all());
        $this->assertSame([1, 1], $tickets->pluck('items')->all());
    }

    public function test_an_item_without_a_station_is_listed_under_dapur(): void
    {
        $this->device();
        $this->kitchenItem($this->sale('INV-NOSTATION'), acceptedAt: $this->at('10:00'));

        $this->assertSame('Dapur', $this->page()['tickets'][0]['station']);
        // defaultId() would have created the station; a report GET must not write.
        $this->assertSame(0, KitchenStation::where('is_default', true)->count());
    }

    public function test_an_add_on_is_its_own_row_under_the_original_number(): void
    {
        $this->device();
        $original = $this->sale('INV-ORIGINAL');
        $addOn = $this->sale('INV-ADDON', parent: $original, orderType: 'in_store');
        $this->kitchenItem($original, acceptedAt: $this->at('10:00'));
        $this->kitchenItem($addOn, acceptedAt: $this->at('10:05'));

        $tickets = $this->page()['tickets'];

        $this->assertCount(2, $tickets);
        $this->assertSame(['INV-ORIGINAL', 'INV-ORIGINAL'], array_column($tickets, 'number'));
    }

    public function test_days_follow_the_app_timezone(): void
    {
        $this->useJakarta();
        $this->device('Dapur 1', createdAt: Carbon::parse('2026-11-24 00:00:00'));
        $this->kitchenItem($this->sale('INV-LATE'), acceptedAt: Carbon::parse('2026-11-24 23:30:00'));
        $this->kitchenItem($this->sale('INV-EARLY'), acceptedAt: Carbon::parse('2026-11-25 00:30:00'));

        $props = $this->page(['start_date' => '2026-11-24', 'end_date' => '2026-11-25']);

        $this->assertSame(
            [['date' => '2026-11-24', 'count' => 1], ['date' => '2026-11-25', 'count' => 1]],
            $props['days'],
        );
        $this->assertSame(1, $this->page(['start_date' => '2026-11-24', 'end_date' => '2026-11-24'])['total']);
        $this->assertSame(1, $this->page(['start_date' => '2026-11-25', 'end_date' => '2026-11-25'])['total']);
    }

    public function test_another_outlets_tickets_and_devices_never_appear(): void
    {
        [$otherOutlet, $otherWarehouse] = $this->outletWithWarehouse('B');
        $this->user->outlets()->attach($otherOutlet->id);
        $this->device('Dapur A');
        $this->device('Dapur B', outlet: $otherOutlet);
        $this->kitchenItem($this->sale('INV-A'), acceptedAt: $this->at('10:00'));
        $this->kitchenItem($this->sale('INV-B', warehouse: $otherWarehouse), acceptedAt: $this->at('10:00'));

        $props = $this->page(['outlet_id' => $this->outlet->id]);

        $this->assertSame([$this->outlet->id], [$props['filters']['outlet_id']]);
        $this->assertSame(['INV-A'], array_column($props['tickets'], 'number'));
        $this->assertSame([['Dapur A']], array_column($props['tickets'], 'devices'));
        $this->assertStringNotContainsString('Dapur B', json_encode($props));
        $this->assertStringNotContainsString('INV-B', json_encode($props));
    }

    public function test_an_outlet_outside_the_users_outlets_is_refused(): void
    {
        [$other] = $this->outletWithWarehouse('C');

        $this->actingAs($this->user)
            ->getJson(route('reports.kitchen-unprinted.index', ['outlet_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outlet_id');
    }

    public function test_the_list_is_capped_but_the_day_counts_are_exact(): void
    {
        $this->device();
        for ($i = 0; $i < 505; $i++) {
            $this->kitchenItem($this->sale('INV-CAP-'.$i), acceptedAt: $this->at('10:00'));
        }

        $props = $this->page();

        $this->assertCount(500, $props['tickets']);
        $this->assertTrue($props['truncated']);
        $this->assertSame(505, $props['total']);
        $this->assertSame([['date' => self::DAY, 'count' => 505]], $props['days']);
    }

    public function test_the_report_requires_reports_access(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reports.kitchen-unprinted.index'))
            ->assertForbidden();
    }

    private function page(array $query = []): array
    {
        $query += ['start_date' => self::DAY, 'end_date' => self::DAY];

        $props = [];
        $this->actingAs($this->user)
            ->get(route('reports.kitchen-unprinted.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function useJakarta(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
    }

    /** A Carbon on the test day; "HH:MM" keeps the day, "m-d HH:MM" moves it. */
    private function at(string $time): Carbon
    {
        return Carbon::parse(str_contains($time, '-') ? '2026-'.$time : self::DAY.' '.$time);
    }

    private function station(string $name): KitchenStation
    {
        return KitchenStation::create(['name' => $name]);
    }

    private function device(string $name = 'Dapur 1', ?Outlet $outlet = null, ?Carbon $createdAt = null): KitchenPrintDevice
    {
        $device = new KitchenPrintDevice(['outlet_id' => ($outlet ?? $this->outlet)->id, 'name' => $name]);
        $device->created_at = $createdAt ?? $this->at('00:00');
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

    /** A sale with one line, optionally an add-on of another transaction. */
    private function sale(
        string $invoice,
        ?Warehouse $warehouse = null,
        ?string $orderType = null,
        ?Transaction $parent = null,
    ): Transaction {
        $transaction = Transaction::create(array_filter([
            'cashier_id' => $this->user->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => $invoice,
            'parent_transaction_id' => $parent?->id,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'order_type' => $orderType,
        ], fn ($value) => $value !== null));

        $this->detail($transaction, $this->product('Menu '.$invoice));

        return $transaction->load('details.product');
    }

    private function detail(Transaction $transaction, Product $product): TransactionDetail
    {
        return TransactionDetail::create([
            'transaction_id' => $transaction->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);
    }

    private function kitchenItem(
        Transaction $sale,
        ?Warehouse $warehouse = null,
        string $status = KitchenItem::STATUS_ACCEPTED,
        ?Carbon $acceptedAt = null,
        ?Carbon $createdAt = null,
        bool $backfilled = false,
        ?TransactionDetail $detail = null,
        ?int $station = null,
    ): KitchenItem {
        $detail ??= $sale->details->first();
        $item = new KitchenItem([
            'transaction_id' => $sale->id,
            'transaction_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'kitchen_station_id' => $station,
            'qty' => 1,
            'status' => $status,
            'accepted_at' => $backfilled ? null : ($acceptedAt ?? now()),
        ]);
        if ($createdAt) {
            $item->created_at = $createdAt;
        }
        $item->save();

        return $item;
    }
}
