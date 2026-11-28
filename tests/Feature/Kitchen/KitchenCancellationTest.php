<?php

namespace Tests\Feature\Kitchen;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenCancellation;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KitchenTicketService;
use App\Services\StockMutationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

// #332 (K3b): a completed sales return writes an event per cancelled kitchen line, and a print device that printed the
// original asks for the DIBATALKAN ticket and reports it printed.
class KitchenCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $cook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        [$this->outlet, $this->warehouse] = $this->outletWithWarehouse('A');
        $this->cashier = $this->userWith(['sales-returns-access', 'sales-returns-create', 'sales-returns-complete', 'transactions-access']);
        $this->cook = $this->userWith(['dine-orders-prepare']);
        CashierShift::create([
            'user_id' => $this->cashier->id, 'opened_by' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash' => 100000, 'expected_cash' => 100000, 'status' => 'open',
        ]);
    }

    // AC-04, BR-1
    public function test_completed_return_writes_an_event_with_the_cancelled_qty(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2);
        $return = $this->draft($sale, [[$detail, 2]]);

        $this->completeReturn($return);

        $this->assertDatabaseCount('kitchen_cancellations', 1);
        $this->assertDatabaseHas('kitchen_cancellations', [
            'kitchen_item_id' => $item->id, 'sales_return_id' => $return->id, 'qty_cancelled' => 2,
        ]);
        $this->assertSame(KitchenItem::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_partial_return_event_qty_is_the_returned_qty(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 3);

        $this->completeReturn($this->draft($sale, [[$detail, 1]]));

        $this->assertSame([1], KitchenCancellation::where('kitchen_item_id', $item->id)->pluck('qty_cancelled')->all());
        $this->assertSame(2, $item->fresh()->qty);
    }

    public function test_second_partial_return_is_a_second_event(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 3);

        $first = $this->draft($sale, [[$detail, 1]]);
        $this->completeReturn($first);
        $second = $this->draft($sale, [[$detail, 2]]);
        $this->completeReturn($second);

        $events = KitchenCancellation::where('kitchen_item_id', $item->id)->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $events->pluck('sales_return_id')->all());
        $this->assertSame([1, 2], $events->pluck('qty_cancelled')->all());
    }

    public function test_event_qty_never_exceeds_what_the_item_still_had(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2);
        $item->update(['qty' => 1]); // the kitchen item is lower than the sale line

        $this->completeReturn($this->draft($sale, [[$detail, 2]]));

        $this->assertDatabaseHas('kitchen_cancellations', ['kitchen_item_id' => $item->id, 'qty_cancelled' => 1]);
    }

    // #241 BR-5
    public function test_returning_a_served_item_writes_no_event(): void
    {
        [$sale, $detail] = $this->sale(status: KitchenItem::STATUS_SERVED);

        $this->completeReturn($this->draft($sale, [[$detail, 1]]));

        $this->assertDatabaseCount('kitchen_cancellations', 0);
    }

    public function test_a_return_line_without_a_kitchen_item_writes_no_event(): void
    {
        [$sale, $detail] = $this->sale(withKitchenItem: false);

        $this->completeReturn($this->draft($sale, [[$detail, 1]]));

        $this->assertDatabaseCount('kitchen_cancellations', 0);
    }

    // BR-2
    public function test_draft_return_writes_no_event_row(): void
    {
        [$sale, $detail] = $this->sale(qty: 2);

        $return = $this->draft($sale, [[$detail, 2]]);
        $this->actingAs($this->cashier)->patch(route('sales-returns.update', $return), [
            'return_type' => 'refund_cash',
            'items' => [['transaction_detail_id' => $detail->id, 'qty_return' => 1, 'return_reason' => 'Salah pesan', 'restock_to_inventory' => true]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('draft', $return->fresh()->status);
        $this->assertDatabaseCount('kitchen_cancellations', 0);
    }

    // TC-3: the event commits with the return or not at all (the failure comes after cancelForReturn has run)
    public function test_a_failed_completion_leaves_no_event(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 2);
        $return = $this->draft($sale, [[$detail, 2]]);
        $this->mock(StockMutationService::class)->shouldReceive('recordSalesReturnRestock')->andThrow(new RuntimeException('stock down'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->cashier)->post(route('sales-returns.complete', $return));
            $this->fail('the return completed although the restock failed');
        } catch (RuntimeException $exception) {
            $this->assertSame('stock down', $exception->getMessage());
        }

        $this->assertDatabaseCount('kitchen_cancellations', 0);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(2, $item->fresh()->qty);
    }

    // AC-04, TC-4
    public function test_cancellations_are_listed_once_the_original_was_printed_here(): void
    {
        [$sale, $detail, $item] = $this->sale(qty: 3, table: 'Meja 5');
        $device = $this->device();
        $this->printed($item, $device);
        $return = $this->draft($sale, [[$detail, 2]]);
        $this->completeReturn($return);

        $response = $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk();

        $ticket = $response->json('cancellations.0');
        $event = KitchenCancellation::firstOrFail();
        $this->assertCount(1, $response->json('cancellations'));
        $this->assertSame(['key', 'number', 'type', 'unpaid', 'since', 'cancellation_ids', 'items'], array_keys($ticket));
        $this->assertSame('c'.$return->id.'t'.$sale->id, $ticket['key']);
        $this->assertSame('Meja 5', $ticket['number']);
        $this->assertSame('dine_in', $ticket['type']);
        $this->assertFalse($ticket['unpaid']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $ticket['since']);
        $this->assertSame([$event->id], $ticket['cancellation_ids']);
        $this->assertSame([['id' => $event->id, 'title' => $detail->product->title, 'qty' => 2, 'note' => null]], $ticket['items']);
        $this->assertStringNotContainsStringIgnoringCase('price', json_encode($ticket));
        $this->assertStringNotContainsString('grand_total', json_encode($ticket));
    }

    public function test_a_takeaway_cancellation_uses_the_invoice_as_its_number(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $device = $this->device();
        $this->printed($item, $device);
        $this->completeReturn($this->draft($sale, [[$detail, 1]]));

        $this->assertSame($sale->invoice, $this->cancellations($device)[0]['number']);
    }

    // AC-07 / BR-4 (#345): DIBATALKAN follows the stations the device serves, like the original ticket.
    public function test_a_cancellation_prints_only_on_the_device_that_serves_its_station(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $dapur = KitchenStation::defaultId();

        [$sale, $barDetail, $barItem] = $this->sale();
        $barItem->update(['kitchen_station_id' => $bar->id]);
        $dapurItem = $this->kitchenItem($sale, $this->line($sale));
        $dapurItem->update(['kitchen_station_id' => $dapur]);

        $barDevice = $this->device('Bar 1');
        $barDevice->stations()->sync([$bar->id]);
        $dapurDevice = $this->device('Dapur 1');
        $dapurDevice->stations()->sync([$dapur]);

        // Both originals were printed by their own device, as K1's confirm does.
        $this->printed($barItem, $barDevice);
        $this->printed($dapurItem, $dapurDevice);

        $this->completeReturn($this->draft($sale, [[$barDetail, 1]]));

        $barTickets = $this->cancellations($barDevice);
        $this->assertCount(1, $barTickets);
        $this->assertSame([$barDetail->product->title], array_column($barTickets[0]['items'], 'title'));
        $this->assertSame([], $this->cancellations($dapurDevice));
    }

    // BR-1 / J3.2: several lines of one return, only the printed ones
    public function test_several_lines_of_one_return_form_one_ticket_and_only_printed_lines_appear(): void
    {
        [$sale, $first, $firstItem] = $this->sale();
        $second = $this->line($sale, 2);
        $secondItem = $this->kitchenItem($sale, $second);
        $third = $this->line($sale, 1);
        $this->kitchenItem($sale, $third); // never printed at this device
        $device = $this->device();
        $this->printed($firstItem, $device);
        $this->printed($secondItem, $device);

        $return = $this->draft($sale, [[$first, 1], [$second, 1], [$third, 1]]);
        $this->completeReturn($return);

        $tickets = $this->cancellations($device);
        $this->assertCount(1, $tickets);
        $this->assertSame('c'.$return->id.'t'.$sale->id, $tickets[0]['key']);
        $this->assertSame(
            [$first->product->title, $second->product->title],
            array_column($tickets[0]['items'], 'title')
        );
        $this->assertCount(2, $tickets[0]['cancellation_ids']);
    }

    // BR-4
    public function test_cancellation_not_listed_for_a_device_without_the_original_record(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $printer = $this->device('Dapur 1');
        $other = $this->device('Dapur 2');
        $this->printed($item, $printer);
        $this->completeReturn($this->draft($sale, [[$detail, 1]]));

        $this->assertCount(1, $this->cancellations($printer));
        $this->assertSame([], $this->cancellations($other));
    }

    // BR-4 + grace: a cancel older than the print by more than the grace is already on the printed ticket's lowered qty
    public function test_event_older_than_the_original_print_is_not_listed_but_one_inside_the_grace_is(): void
    {
        $this->travelTo(now()->startOfSecond());
        $device = $this->device();
        $printedAt = now()->subMinutes(10);
        [, , $late] = $this->sale();
        [, , $inside] = $this->sale();
        [, , $edge] = $this->sale();
        [, , $after] = $this->sale();
        foreach ([$late, $inside, $edge, $after] as $item) {
            $this->printed($item, $device, $printedAt);
        }
        $this->cancel($late, 1, $printedAt->copy()->subSeconds(KitchenTicketService::CANCEL_GRACE_SECONDS + 1));
        $this->cancel($inside, 1, $printedAt->copy()->subSeconds(KitchenTicketService::CANCEL_GRACE_SECONDS - 1));
        $this->cancel($edge, 1, $printedAt->copy()->subSeconds(KitchenTicketService::CANCEL_GRACE_SECONDS));
        $this->cancel($after, 1, $printedAt->copy()->addMinute());

        $listed = collect($this->cancellations($device))->flatMap(fn (array $t) => array_column($t['items'], 'id'))->all();

        $ids = fn (KitchenItem ...$items) => KitchenCancellation::whereIn('kitchen_item_id', array_map(fn ($i) => $i->id, $items))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([...$ids($inside), ...$ids($edge), ...$ids($after)], $listed);
        $this->assertNotContains($ids($late)[0], $listed);
    }

    // BR-5
    public function test_events_older_than_12_hours_are_not_listed(): void
    {
        $this->travelTo(now()->startOfSecond());
        $device = $this->device();
        [, , $old] = $this->sale();
        [, , $recent] = $this->sale();
        $this->printed($old, $device, now()->subHours(20));
        $this->printed($recent, $device, now()->subHours(20));
        $this->cancel($old, 1, now()->subHours(KitchenTicketService::CANCEL_WINDOW_HOURS)->subMinute());
        $recentEvent = $this->cancel($recent, 1, now()->subHours(KitchenTicketService::CANCEL_WINDOW_HOURS)->addMinute());

        $this->assertSame([$recentEvent->id], collect($this->cancellations($device))->pluck('cancellation_ids')->flatten()->all());
    }

    // BR-3
    public function test_cancellation_confirm_is_idempotent(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $device = $this->device();
        $this->printed($item, $device);
        $this->completeReturn($this->draft($sale, [[$detail, 1]]));
        $event = KitchenCancellation::firstOrFail();
        $before = $item->fresh()->getAttributes();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->cook)
                ->postJson(route('kitchen.print-devices.cancellation-prints', $device), ['cancellation_ids' => [$event->id, $event->id]])
                ->assertNoContent();
        }

        $this->assertDatabaseCount('kitchen_cancellation_prints', 1);
        $this->assertDatabaseHas('kitchen_cancellation_prints', ['kitchen_print_device_id' => $device->id, 'kitchen_cancellation_id' => $event->id]);
        $this->assertSame([], $this->cancellations($device));
        $this->assertSame($before, $item->fresh()->getAttributes()); // a ticket changes no item and no stock
    }

    public function test_two_devices_each_print_a_cancel_once(): void
    {
        [$sale, $detail, $item] = $this->sale();
        $a = $this->device('Dapur 1');
        $b = $this->device('Dapur 2');
        $this->printed($item, $a);
        $this->printed($item, $b);
        $this->completeReturn($this->draft($sale, [[$detail, 1]]));
        $event = KitchenCancellation::firstOrFail();

        $this->assertCount(1, $this->cancellations($a));
        $this->assertCount(1, $this->cancellations($b));

        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.cancellation-prints', $a), ['cancellation_ids' => [$event->id]])->assertNoContent();

        $this->assertSame([], $this->cancellations($a));
        $this->assertCount(1, $this->cancellations($b));
    }

    public function test_a_poll_lists_at_most_the_oldest_thirty_tickets(): void
    {
        $this->travelTo(now()->startOfSecond());
        $device = $this->device();
        $events = [];
        foreach (range(1, 31) as $n) {
            [, , $item] = $this->sale();
            $this->printed($item, $device, now()->subHours(2));
            $events[] = $this->cancel($item, 1, now()->subMinutes(100 - $n));
        }

        $first = $this->cancellations($device);

        $this->assertCount(30, $first);
        $this->assertSame($events[0]->id, $first[0]['cancellation_ids'][0]);
        $this->actingAs($this->cook)->postJson(
            route('kitchen.print-devices.cancellation-prints', $device),
            ['cancellation_ids' => collect($first)->pluck('cancellation_ids')->flatten()->all()]
        )->assertNoContent();
        $this->assertSame([[$events[30]->id]], array_column($this->cancellations($device), 'cancellation_ids'));
    }

    // Security: scope by outlet
    public function test_cancellations_of_another_outlet_are_not_listed_and_their_ids_are_ignored(): void
    {
        [$outletB, $warehouseB] = $this->outletWithWarehouse('B');
        [, , $theirItem] = $this->sale(warehouse: $warehouseB);
        $deviceB = $this->device('Dapur B', $outletB);
        $this->printed($theirItem, $deviceB);
        $theirs = $this->cancel($theirItem, 1, now());
        $deviceA = $this->device();

        // not listed for outlet A's device, even if it had printed the item
        $this->printed($theirItem, $deviceA);
        $this->assertSame([], $this->cancellations($deviceA));

        // and outlet A's confirm of a foreign id writes nothing; an unknown id is ignored too
        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.cancellation-prints', $deviceA), ['cancellation_ids' => [$theirs->id, 999999]])
            ->assertNoContent();
        $this->assertDatabaseCount('kitchen_cancellation_prints', 0);
    }

    public function test_a_device_of_another_outlet_is_not_found(): void
    {
        [$outletB] = $this->outletWithWarehouse('B');
        $foreign = $this->device('Dapur B', $outletB);

        $this->actingAs($this->cook)
            ->postJson(route('kitchen.print-devices.cancellation-prints', $foreign), ['cancellation_ids' => [1]])
            ->assertNotFound();
        $this->assertDatabaseCount('kitchen_cancellation_prints', 0);
    }

    public function test_users_without_prepare_or_handover_are_refused(): void
    {
        $device = $this->device();

        $this->actingAs($this->userWith(['transactions-access']))
            ->postJson(route('kitchen.print-devices.cancellation-prints', $device), ['cancellation_ids' => [1]])
            ->assertForbidden();
        $this->actingAs($this->userWith(['dine-orders-handover']))
            ->postJson(route('kitchen.print-devices.cancellation-prints', $device), ['cancellation_ids' => [1]])
            ->assertNoContent();
    }

    public function test_the_confirm_needs_one_to_two_hundred_integer_ids(): void
    {
        $device = $this->device();
        $post = fn (array $body) => $this->actingAs($this->cook)->postJson(route('kitchen.print-devices.cancellation-prints', $device), $body);

        $post([])->assertJsonValidationErrors('cancellation_ids');
        $post(['cancellation_ids' => []])->assertJsonValidationErrors('cancellation_ids');
        $post(['cancellation_ids' => range(1, 201)])->assertJsonValidationErrors('cancellation_ids');
        $post(['cancellation_ids' => ['x']])->assertJsonValidationErrors('cancellation_ids.0');
        $post(['cancellation_ids' => range(1, 200)])->assertNoContent();
    }

    // TC-1: both tables go away and come back, with the keys that guard them
    public function test_the_migrations_round_trip(): void
    {
        $prints = require database_path('migrations/2026_12_07_100002_create_kitchen_cancellation_prints_table.php');
        $events = require database_path('migrations/2026_12_07_100001_create_kitchen_cancellations_table.php');

        $prints->down();
        $events->down();

        $this->assertFalse(Schema::hasTable('kitchen_cancellation_prints'));
        $this->assertFalse(Schema::hasTable('kitchen_cancellations'));

        $events->up();
        $prints->up();

        $this->assertTrue(Schema::hasColumns('kitchen_cancellations', ['id', 'kitchen_item_id', 'sales_return_id', 'qty_cancelled', 'created_at']));
        $this->assertFalse(Schema::hasColumn('kitchen_cancellations', 'updated_at'));
        $this->assertTrue(Schema::hasIndex('kitchen_cancellation_prints', ['kitchen_print_device_id', 'kitchen_cancellation_id'], 'unique'));
        $this->assertEqualsCanonicalizing(
            ['kitchen_print_devices', 'kitchen_cancellations'],
            collect(Schema::getForeignKeys('kitchen_cancellation_prints'))->pluck('foreign_table')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['kitchen_items', 'sales_returns'],
            collect(Schema::getForeignKeys('kitchen_cancellations'))->pluck('foreign_table')->all()
        );

        // The cascade: a deleted item takes its events and their prints with it.
        [, , $item] = $this->sale();
        $device = $this->device();
        $event = $this->cancel($item, 1, now());
        DB::table('kitchen_cancellation_prints')->insert(['kitchen_print_device_id' => $device->id, 'kitchen_cancellation_id' => $event->id]);
        $item->delete();
        $this->assertDatabaseCount('kitchen_cancellations', 0);
        $this->assertDatabaseCount('kitchen_cancellation_prints', 0);
    }

    /** @return array<int, array<string, mixed>> */
    private function cancellations(KitchenPrintDevice $device): array
    {
        return $this->actingAs($this->cook)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk()->json('cancellations');
    }

    /** A device named earlier than the sales of the test. */
    private function device(string $name = 'Dapur 1', ?Outlet $outlet = null): KitchenPrintDevice
    {
        $device = new KitchenPrintDevice(['outlet_id' => ($outlet ?? $this->outlet)->id, 'name' => $name]);
        $device->created_at = now()->subDay();
        $device->save();

        return $device;
    }

    /** The original ticket was printed (what K1's confirm writes), at a time the test picks. */
    private function printed(KitchenItem $item, KitchenPrintDevice $device, ?Carbon $at = null): void
    {
        DB::table('kitchen_ticket_prints')->insert([
            'kitchen_print_device_id' => $device->id, 'kitchen_item_id' => $item->id, 'printed_at' => $at ?? now()->subMinutes(5),
        ]);
    }

    /** An event written directly, with the time the test picks, on a completed return of the item's sale. */
    private function cancel(KitchenItem $item, int $qty, Carbon $at): KitchenCancellation
    {
        $return = SalesReturn::create([
            'code' => 'SR-T-'.++$this->seq, 'transaction_id' => $item->transaction_id, 'status' => 'completed', 'total_return_amount' => 0,
        ]);
        $event = new KitchenCancellation(['kitchen_item_id' => $item->id, 'sales_return_id' => $return->id, 'qty_cancelled' => $qty]);
        $event->created_at = $at;
        $event->save();

        return $event;
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

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach($this->outlet->id, ['is_default' => true]);

        return $user;
    }

    private function product(Warehouse $warehouse): Product
    {
        $product = Product::create([
            'title' => 'Menu '.++$this->seq, 'sku' => 'SKU-'.$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 10, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => true,
        ]);
        ProductWarehouse::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock' => 10]);

        return $product;
    }

    private function line(Transaction $sale, int $qty = 1): TransactionDetail
    {
        $product = $this->product(Warehouse::findOrFail($sale->warehouse_id));
        $detail = TransactionDetail::create([
            'transaction_id' => $sale->id, 'product_id' => $product->id, 'qty' => $qty, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000 * $qty, 'discount_total' => 0, 'cost_total' => 5000 * $qty,
        ]);
        $sale->profits()->create(['total' => 5000 * $qty]);

        return $detail->load('product');
    }

    private function kitchenItem(Transaction $sale, TransactionDetail $detail, string $status = KitchenItem::STATUS_ACCEPTED): KitchenItem
    {
        return KitchenItem::create([
            'transaction_id' => $sale->id, 'transaction_detail_id' => $detail->id, 'product_id' => $detail->product_id,
            'warehouse_id' => $sale->warehouse_id, 'qty' => $detail->qty, 'status' => $status, 'accepted_at' => now(),
        ]);
    }

    /** @return array{Transaction, TransactionDetail, ?KitchenItem} a paid sale of one line, with its kitchen item as intake would create it */
    private function sale(int $qty = 1, string $status = KitchenItem::STATUS_ACCEPTED, bool $withKitchenItem = true, ?string $table = null, ?Warehouse $warehouse = null): array
    {
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->id, 'invoice' => 'INV-'.++$this->seq,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000 * $qty,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $detail = $this->line($sale, $qty);

        if ($table) {
            DineOrder::create([
                'dine_table_id' => DiningTable::create(['name' => $table, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
                'transaction_id' => $sale->id, 'status' => DineOrder::STATUS_ACCEPTED, 'payment_option' => DineOrder::PAY_AT_COUNTER,
                'subtotal' => 10000 * $qty, 'item_count' => 1,
            ]);
        }

        return [$sale, $detail, $withKitchenItem ? $this->kitchenItem($sale, $detail, $status) : null];
    }

    /** @param  list<array{TransactionDetail, int}>  $lines one draft return over lines of the sale */
    private function draft(Transaction $sale, array $lines): SalesReturn
    {
        $this->actingAs($this->cashier)->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
            'items' => array_map(fn (array $line) => [
                'transaction_detail_id' => $line[0]->id, 'qty_return' => $line[1], 'return_reason' => 'Salah pesan', 'restock_to_inventory' => true,
            ], $lines),
        ])->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    private function completeReturn(SalesReturn $return): void
    {
        $this->actingAs($this->cashier)->post(route('sales-returns.complete', $return))->assertSessionHasNoErrors();
    }
}
