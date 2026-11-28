<?php

namespace Tests\Feature\DineIn;

use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use App\Services\OpenBillListService;
use App\Services\TableStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\DineIn\Concerns\MakesPosTableOrders;
use Tests\Feature\DineIn\Concerns\ReservesTables;
use Tests\TestCase;

// #426 (REQ-009 AC-04, AC-05, BR-1): a table's status is computed from its orders, never stored.
class DineTableStatusTest extends TestCase
{
    use MakesPosTableOrders, RefreshDatabase, ReservesTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPosTable();
    }

    public function test_status_and_bill_count_match_the_pos_list(): void
    {
        $free = $this->tableNamed('Kosong');
        $waiting = $this->tableNamed('Menunggu');
        $this->submitted($waiting);
        $this->sentOrder();
        $this->sentOrder()->transaction->update(['payment_status' => 'pending']);
        $this->sentOrder()->transaction->update(['payment_status' => 'paid']); // paid, not served yet: Terisi, no bill

        $states = $this->states($free, $waiting, $this->table);

        $this->assertSame(['free', 'occupied', 'occupied'], $states->pluck('status')->all());
        $this->assertSame([0, 0, 2], $states->pluck('open_bills')->all());
        $this->assertSame([0, 1, 3], $states->pluck('active_orders')->all());
        $listed = app(OpenBillListService::class)->rowsFor($this->cashier)->countBy('table');
        $this->assertSame(['Meja 7' => 2], $listed->all());
    }

    public function test_table_frees_itself_when_the_last_order_finishes(): void
    {
        $first = $this->sentOrder();
        $second = $this->submitted($this->table);

        foreach ([DineOrder::STATUS_COMPLETED, DineOrder::STATUS_REJECTED, DineOrder::STATUS_CANCELLED] as $finished) {
            $first->update(['status' => $finished]);
            $second->update(['status' => DineOrder::STATUS_SUBMITTED]);
            $this->assertSame('occupied', $this->states($this->table)->first()['status'], $finished);

            $second->update(['status' => $finished]);
            $this->assertSame('free', $this->states($this->table)->first()['status'], $finished);
        }
    }

    public function test_reserved_within_the_window_and_occupied_wins(): void
    {
        $free = $this->tableNamed('Meja 8');
        $this->sentOrder();
        $this->reserve($free, $this->table);

        $states = $this->states($free, $this->table);

        $this->assertSame(['reserved', 'occupied'], $states->pluck('status')->all());
        $this->assertSame(['19:00', '19:00'], $states->pluck('reservation.time')->all());
    }

    public function test_status_costs_a_fixed_number_of_queries(): void
    {
        $tables = collect(range(1, 100))->map(fn (int $n) => $this->tableNamed("M{$n}"));
        $this->submitted($tables[3]);
        DB::enableQueryLog();

        app(TableStatusService::class)->forTables($tables);

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_existing_orders_default_to_qr(): void
    {
        $this->assertSame('qr', $this->submitted($this->table)->fresh()->source);
    }

    public function test_pos_list_is_scoped_to_the_outlet_and_carries_no_token(): void
    {
        $shared = DiningTable::create(['name' => 'Teras', 'is_active' => true, 'capacity' => 2, 'sort_order' => 0]);
        $this->tableNamed('Cabang', Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]));
        $this->tableNamed('Rusak')->update(['is_active' => false]);
        $order = $this->sentOrder();
        $this->cart();

        $response = $this->actingAs($this->cashier)->getJson(route('transactions.tables'))->assertOk();

        $this->assertSame(['Teras', 'Meja 7'], array_column($response->json('tables'), 'name'));
        $this->assertSame(
            ['id' => $this->table->id, 'name' => 'Meja 7', 'area_name' => 'Area Meja 7', 'capacity' => 4, 'status' => 'occupied',
                'open_bills' => 1, 'orders' => [['id' => $order->id, 'status' => 'accepted', 'item_count' => 2]]],
            $response->json('tables.1'),
        );
        $this->assertSame([$shared->id, 'free', []], [$response->json('tables.0.id'), $response->json('tables.0.status'), $response->json('tables.0.orders')]);
        $response->assertJsonPath('payLaterEnabled', false)->assertJsonPath('cartTotal', 20000);
        $this->assertStringNotContainsString($this->table->token, $response->getContent());

        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
        $this->getJson(route('transactions.tables'))->assertJsonPath('payLaterEnabled', true);
    }

    // One target per bill: an order and its add-on are one; a finished original leaves its open add-on to be added to.
    public function test_add_on_targets_are_one_per_running_bill(): void
    {
        $original = $this->sentOrder();
        $addOn = $this->sentOrder(['add_on_to_dine_order_id' => $original->id]);
        $separate = $this->sentOrder();
        $this->submitted($this->table);
        $targets = fn () => array_column($this->actingAs($this->cashier)->getJson(route('transactions.tables'))->json('tables.0.orders'), 'id');

        $this->assertSame([$original->id, $separate->id], $targets());

        $original->update(['status' => DineOrder::STATUS_COMPLETED]);
        $this->assertSame([$addOn->id, $separate->id], $targets());
    }

    public function test_tables_page_shows_status_and_open_bills(): void
    {
        $this->sentOrder();
        $admin = User::where('email', 'arya@gmail.com')->first();
        $admin->markEmailAsVerified();

        $this->actingAs($admin)->withSession(['active_outlet_id' => $this->outlet->id])->get(route('dine-tables.index'))
            ->assertInertia(fn (Assert $page) => $page->where('tables.0.status', 'occupied')->where('tables.0.open_bills', 1));
    }

    private function submitted(DiningTable $table): DineOrder
    {
        return DineOrder::create(['dine_table_id' => $table->id, 'status' => DineOrder::STATUS_SUBMITTED, 'subtotal' => 0, 'item_count' => 0]);
    }

    private function states(DiningTable ...$tables)
    {
        $states = app(TableStatusService::class)->forTables(collect($tables));

        return collect($tables)->map(fn (DiningTable $table) => $states[$table->id]);
    }
}
