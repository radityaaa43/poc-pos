<?php

namespace Tests\Feature\DineIn;

use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * REQ-005 AC-10 (#327): the POS unpaid bill (Belum Lunas) list marks a bill whose guest is paying online, and the JSON
 * endpoint the browser polls returns the same rows as the page, with the same outlet scope.
 */
class OpenBillsAwaitingOnlineTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'WH-1', 'status' => 'active', 'outlet_id' => $this->outlet->id]);

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo('transactions-access');
        $this->cashier->outlets()->attach($this->outlet->id, ['is_default' => true]);
    }

    /** An accepted dine-in bill of 20000, unpaid unless the overrides say otherwise. */
    private function dineBill(array $overrides = [], ?Warehouse $warehouse = null): Transaction
    {
        $bill = Transaction::create($overrides + [
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => 'INV-AO-'.++$this->seq,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 20000,
            'payment_method' => Transaction::METHOD_OPEN_BILL,
            'payment_status' => 'unpaid',
        ]);
        $table = DiningTable::create(['name' => 'Meja '.$this->seq, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        DineOrder::create([
            'dine_table_id' => $table->id,
            'transaction_id' => $bill->id,
            'status' => DineOrder::STATUS_ACCEPTED,
            'payment_option' => 'pay_at_counter',
            'payment_status' => $bill->payment_status,
            'subtotal' => 20000,
            'item_count' => 2,
        ]);

        return $bill;
    }

    /** The guest's charge is in flight: pending, attempt 1, no tender rows. */
    private function guestPaying(array $overrides = [], ?Warehouse $warehouse = null): Transaction
    {
        return $this->dineBill($overrides + ['payment_method' => 'qris', 'payment_status' => 'pending', 'payment_attempt' => 1], $warehouse);
    }

    /** The row of the bill in what the polling endpoint returns. */
    private function row(Transaction $bill): array
    {
        $rows = $this->actingAs($this->cashier)->getJson(route('transactions.open-bills'))->assertOk()->json('openBills');

        return collect($rows)->firstWhere('id', $bill->id);
    }

    public function test_a_bill_the_guest_is_paying_is_awaiting_online(): void
    {
        $bill = $this->guestPaying();

        $row = $this->row($bill);

        $this->assertTrue($row['awaiting_online']);
        $this->assertTrue($row['pending']);
        $this->assertFalse($row['late_payment']);
    }

    public function test_the_cashiers_own_pending_charge_is_not_awaiting_online(): void
    {
        // Her gateway charge writes a tender row, also on a bill that had a guest attempt (its attempt is above 0 too).
        $afterGuest = $this->guestPaying();
        $afterGuest->tenders()->create(['method' => TransactionTender::METHOD_QRIS, 'amount' => 20000, 'payment_status' => 'pending']);
        $plain = $this->dineBill(['payment_method' => 'qris', 'payment_status' => 'pending']);
        $plain->tenders()->create(['method' => TransactionTender::METHOD_QRIS, 'amount' => 20000, 'payment_status' => 'pending']);

        foreach ([$afterGuest, $plain] as $bill) {
            $row = $this->row($bill);
            $this->assertFalse($row['awaiting_online'], $bill->invoice);
            $this->assertTrue($row['pending'], $bill->invoice); // still "Lihat QR"
        }
    }

    public function test_a_charge_that_never_got_an_answer_offers_bayar_again_after_two_minutes(): void
    {
        $this->freezeTime();
        // Her request died between saving the payment and the gateway's answer: a gateway tender with no reference.
        $abandoned = $this->dineBill(['payment_method' => 'qris', 'payment_status' => 'pending', 'payment_attempt' => 1]);
        $abandoned->tenders()->create(['method' => TransactionTender::METHOD_QRIS, 'amount' => 20000, 'payment_status' => 'pending']);
        $charged = $this->dineBill(['payment_method' => 'qris', 'payment_status' => 'pending', 'payment_attempt' => 1]);
        $charged->tenders()->create(['method' => TransactionTender::METHOD_QRIS, 'amount' => 20000, 'payment_status' => 'pending', 'payment_reference' => 'ref-1']);

        $this->travel(119)->seconds();
        $this->assertTrue($this->row($abandoned)['pending']);

        $this->travel(2)->seconds();
        $row = $this->row($abandoned);
        $this->assertFalse($row['pending']);
        $this->assertFalse($row['awaiting_online']);
        $this->assertTrue($this->row($charged)['pending']); // there is a QR to show: still "Lihat QR"
    }

    public function test_a_failed_or_unpaid_bill_is_not_awaiting_online(): void
    {
        $failed = $this->dineBill(['payment_method' => 'qris', 'payment_status' => 'failed', 'payment_attempt' => 1]);
        $unpaid = $this->dineBill();
        $pendingWithoutAttempt = $this->dineBill(['payment_status' => 'pending']);

        foreach ([$failed, $unpaid, $pendingWithoutAttempt] as $bill) {
            $this->assertFalse($this->row($bill)['awaiting_online'], $bill->invoice);
        }
    }

    public function test_a_pay_later_pos_sale_is_never_awaiting_online(): void
    {
        // No dine order: taking over is DineOrderService::pay, and a retried POS sale also has no tender rows.
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'INV-AO-POS',
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 20000,
            'payment_method' => 'qris', 'payment_status' => 'pending', 'payment_attempt' => 1, 'is_pay_later' => true,
        ]);

        $row = $this->row($sale);

        $this->assertFalse($row['awaiting_online']);
        $this->assertTrue($row['pending']);
    }

    public function test_a_bill_with_a_late_payment_carries_the_refund_marker(): void
    {
        $marked = $this->dineBill(['late_superseded_payment_at' => now()]);
        $clean = $this->dineBill();

        $this->assertTrue($this->row($marked)['late_payment']);
        $this->assertFalse($this->row($clean)['late_payment']);
    }

    public function test_the_endpoint_lists_only_the_cashiers_outlets(): void
    {
        $mine = $this->guestPaying();
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $this->guestPaying([], Warehouse::create(['code' => 'WH-2', 'name' => 'WH-2', 'status' => 'active', 'outlet_id' => $other->id]));

        $this->actingAs($this->cashier)->getJson(route('transactions.open-bills'))
            ->assertOk()
            ->assertJsonCount(1, 'openBills')
            ->assertJsonPath('openBills.0.id', $mine->id);
    }

    public function test_the_endpoint_returns_the_rows_of_the_page(): void
    {
        $this->guestPaying();
        $this->dineBill(['late_superseded_payment_at' => now()]);

        $polled = $this->actingAs($this->cashier)->getJson(route('transactions.open-bills'))->assertOk()->json('openBills');

        $this->assertCount(2, $polled);
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($polled) {
                $this->assertEquals($polled, $page->toArray()['props']['openBills']);
            });
    }

    public function test_the_endpoint_needs_the_pos_permission(): void
    {
        $this->guestPaying();
        $nobody = User::factory()->create();
        $nobody->markEmailAsVerified();

        $this->getJson(route('transactions.open-bills'))->assertUnauthorized();
        $this->actingAs($nobody)->getJson(route('transactions.open-bills'))->assertForbidden();
    }

    public function test_a_longer_list_costs_no_extra_queries(): void
    {
        $this->guestPaying();
        $this->row($this->guestPaying()); // warms the permission cache and the session

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->cashier)->getJson(route('transactions.open-bills'))->assertOk();

            return count(DB::getQueryLog());
        };

        $two = $count();
        $this->guestPaying();
        $this->guestPaying();
        $this->dineBill();
        $five = $count();

        $this->assertSame($two, $five);
    }
}
