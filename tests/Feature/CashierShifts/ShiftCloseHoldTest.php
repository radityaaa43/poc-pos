<?php

namespace Tests\Feature\CashierShifts;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\KitchenItem;
use App\Models\Receivable;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\KitchenSpeedReportService;
use App\Services\ShiftCloseBlockerService;
use App\Services\TransactionSettlementService;
use App\Services\TransactionTenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/** REQ-003 AC-12, AC-13, AC-17, AC-20, AC-27 (#242, #396): what holds a shift open, forced close, and the close's auto-cancel. */
class ShiftCloseHoldTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    private CashierShift $shift; // the cashier's own open shift

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDineBill();
        foreach (['cashier-shifts-access', 'cashier-shifts-close', 'cashier-shifts-force-close'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->cashier->givePermissionTo(['cashier-shifts-access', 'cashier-shifts-close']);
        $this->shift = $this->openShiftFor($this->cashier);
    }

    public function test_close_is_refused_with_the_list_of_open_items_and_unpaid_bills(): void
    {
        $dish = $this->kitchenSale($this->shift);
        $bill = $this->guestPayLater($this->shift);
        $this->close($this->shift)->assertSessionHasErrors('shift');
        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 2)
            ->where('closeBlockers.0', ['type' => 'item', 'order' => $dish->transaction->invoice, 'label' => 'Rujak × 2', 'amount' => 20000])
            ->where('closeBlockers.1.type', 'bill')->where('closeBlockers.1.order', $bill->invoice)->where('closeBlockers.1.amount', 10000));
    }

    public function test_a_refused_close_changes_nothing(): void
    {
        $item = $this->kitchenSale($this->shift);
        $pending = $this->sale($this->shift, 'qris', 'pending');
        $stock = $this->stock();
        $this->close($this->shift)->assertSessionHasErrors('shift');
        $this->assertTrue($this->shift->fresh()->isOpen());
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->payment_status);
        $this->assertSame($stock, $this->stock());
    }

    public function test_a_credit_customers_receivable_does_not_hold_the_close(): void
    {
        $credit = $this->guestPayLater($this->shift);
        Receivable::create(['transaction_id' => $credit->id, 'invoice' => $credit->invoice, 'total' => 10000, 'paid' => 0,
            'due_date' => now()->addWeek(), 'status' => 'unpaid']);
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame(CashierShift::STATUS_CLOSED, $this->shift->fresh()->status);
        $this->assertSame('unpaid', $credit->fresh()->payment_status);
        $this->assertSame(0, $this->shift->fresh()->unpaid_bills_total);
    }

    public function test_an_unpaid_dine_bill_holds_the_close_and_is_never_cancelled(): void
    {
        $bill = $this->bill($this->acceptedOrder());
        // A guest's online attempt leaves the bill pending: it is still a bill, not a Pay now sale (BR-10).
        $bill->update(['payment_status' => 'pending', 'payment_method' => 'qris', 'payment_attempt' => 1]);
        $shift = CashierShift::findOrFail($bill->cashier_shift_id);
        $this->close($shift)->assertSessionHasErrors('shift');
        $this->forceClose($shift, $this->cashier);
        $this->assertSame('pending', $bill->fresh()->payment_status);
        $this->assertSame(self::TOTAL, $shift->fresh()->unpaid_bills_total);
    }

    public function test_force_close_marks_items_not_collected_and_books_unpaid_bills_as_a_shortfall(): void
    {
        $manager = $this->manager();
        $item = $this->kitchenSale($this->shift);
        $bill = $this->guestPayLater($this->shift);

        $this->close($this->shift, $manager, ['force' => true, 'actual_cash' => 120000, 'close_notes' => 'Tamu pulang'])->assertSessionHasNoErrors();

        $closed = $this->shift->fresh();
        $this->assertSame(CashierShift::STATUS_FORCE_CLOSED, $closed->status);
        $this->assertSame(KitchenItem::STATUS_NOT_COLLECTED, $item->fresh()->status);
        $this->assertSame(10000, $closed->unpaid_bills_total);
        // Opening 100000 + the paid kitchen sale 20000 = 120000 expected; the unpaid 10000 is money missing (BR-9).
        $this->assertSame(120000, $closed->expected_cash);
        $this->assertSame(-10000, $closed->cash_difference);
        $this->assertSame(0, $closed->non_cash_sales_total); // BR-16: the booked bill is not counted a second time
        $this->assertSame('unpaid', $bill->fresh()->payment_status);

        $log = AuditLog::where('event', 'cashier_shift.hold_overridden')->sole();
        $this->assertSame([$item->id], $log->meta['not_collected_item_ids']);
        $this->assertSame([$bill->invoice], $log->meta['unpaid_bills']);
        $this->assertSame(10000, $log->meta['unpaid_bills_total']);
        $this->assertSame(1, AuditLog::where('event', 'cashier_shift.force_closed')->count());
    }

    public function test_a_partly_paid_bill_books_only_what_is_still_owed(): void
    {
        $bill = $this->guestPayLater($this->shift);
        $bill->update(['payment_method' => 'split', 'payment_status' => 'pending']);
        $this->tender($bill, TransactionTender::METHOD_BANK_TRANSFER, TransactionTender::STATUS_PAID, 4000);
        $this->tender($bill, TransactionTender::METHOD_QRIS, TransactionTender::STATUS_PENDING, 6000);
        $this->forceClose($this->shift, $this->cashier);
        $this->assertSame(6000, $this->shift->fresh()->unpaid_bills_total);
        $this->assertSame(4000, $this->shift->fresh()->non_cash_sales_total);
    }

    public function test_force_close_of_a_held_shift_requires_a_note(): void
    {
        $item = $this->kitchenSale($this->shift);
        $this->close($this->shift, $this->manager(), ['force' => true, 'close_notes' => '  '])->assertSessionHasErrors('close_notes');
        $this->assertTrue($this->shift->fresh()->isOpen());
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    // The manager's page may have loaded before the hold appeared: without the flag they get the list, not a silent shortfall.
    public function test_a_managers_close_without_the_force_flag_is_refused_on_a_held_shift(): void
    {
        $this->guestPayLater($this->shift);
        $this->close($this->shift, $this->manager(), ['close_notes' => 'Tutup'])->assertSessionHasErrors('shift');
        $this->assertTrue($this->shift->fresh()->isOpen());
    }

    public function test_forcing_a_held_shift_without_the_permission_is_refused(): void
    {
        $this->guestPayLater($this->shift);
        $this->close($this->shift, $this->cashier, ['force' => true, 'close_notes' => 'Paksa'])->assertForbidden();
        $this->assertTrue($this->shift->fresh()->isOpen());
    }

    public function test_the_owner_with_the_permission_may_force_close_their_own_shift(): void
    {
        $this->cashier->givePermissionTo('cashier-shifts-force-close');
        $this->guestPayLater($this->shift);
        $this->close($this->shift, $this->cashier, ['force' => true, 'close_notes' => 'Paksa'])->assertSessionHasNoErrors();
        $this->assertSame(CashierShift::STATUS_FORCE_CLOSED, $this->shift->fresh()->status);
    }

    public function test_force_close_never_touches_served_items(): void
    {
        $served = $this->kitchenSale($this->shift, KitchenItem::STATUS_SERVED);
        $ready = $this->kitchenSale($this->shift, KitchenItem::STATUS_READY);
        $this->forceClose($this->shift, $this->cashier);
        $this->assertSame(KitchenItem::STATUS_SERVED, $served->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_NOT_COLLECTED, $ready->fresh()->status);
        $this->assertFalse(KitchenItem::open()->whereKey($ready->id)->exists());
    }

    public function test_a_bill_paid_after_a_force_close_counts_in_the_shift_that_takes_it(): void
    {
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $old = CashierShift::findOrFail($bill->cashier_shift_id);
        $this->forceClose($old, $this->cashier);
        $new = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 50000, null, $this->warehouse->id);
        $this->cashierPays($order);
        $this->assertSame($new->id, $bill->fresh()->cashier_shift_id);
        $this->assertSame(50000 + self::TOTAL, app(CashierShiftService::class)->calculateSummary($new)['expected_cash']);
        $this->assertSame(self::TOTAL, $old->fresh()->unpaid_bills_total); // the closed recap stays as it was
    }

    public function test_unpaid_pay_now_sales_are_cancelled_restocked_and_audited_at_close(): void
    {
        $sales = collect(['pending', 'failed', 'expired'])->map(fn (string $status) => $this->sale($this->shift, 'qris', $status));
        $this->assertSame(14, $this->stock()['warehouse']); // 3 sales of 2 left the shelf
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame(['warehouse' => 20, 'product' => 20], $this->stock());
        foreach ($sales as $sale) {
            $this->assertSame('failed', $sale->fresh()->payment_status);
            $this->assertTrue($sale->fresh()->wasRestocked());
        }
        $this->assertSame(3, StockMutation::where('reference_type', 'transaction_expire')->count());
        $this->assertSame(3, AuditLog::where('event', 'transaction.cancelled_on_shift_close')->count());
        $closed = $this->shift->fresh();
        $this->assertSame(0, $closed->non_cash_sales_total);
        $this->assertSame(0, $closed->transactions_count);
    }

    public function test_an_unconfirmed_transfer_is_cancelled_with_its_tender(): void
    {
        $sale = $this->sale($this->shift, 'bank_transfer', 'pending');
        $tender = $this->tender($sale, TransactionTender::METHOD_BANK_TRANSFER, TransactionTender::STATUS_PENDING, 20000);
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame(TransactionTender::STATUS_FAILED, $tender->fresh()->payment_status);
    }

    public function test_paid_and_returned_sales_are_neither_held_nor_cancelled(): void
    {
        $paid = $this->sale($this->shift, 'qris', 'paid');
        $returned = $this->sale($this->shift, 'qris', 'pending');
        $this->completedReturn($returned);
        $stock = $this->stock();
        $this->close($this->shift)->assertSessionHasNoErrors(); // BR-15: none of these holds the close either
        $this->assertSame('paid', $paid->fresh()->payment_status);
        $this->assertSame('pending', $returned->fresh()->payment_status);
        $this->assertSame($stock, $this->stock());
    }

    public function test_a_sale_already_cancelled_is_not_restocked_again(): void
    {
        $sale = $this->sale($this->shift, 'qris', 'pending');
        $this->travel(2)->days();
        $this->artisan('transactions:expire')->assertSuccessful();
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame(['warehouse' => 20, 'product' => 20], $this->stock());
        $this->assertSame(1, StockMutation::where('reference_type', 'transaction_expire')->where('reference_id', $sale->id)->count());
    }

    public function test_a_transfer_cancelled_at_close_cannot_be_confirmed(): void
    {
        $sale = $this->sale($this->shift, 'bank_transfer', 'pending');
        $this->forceClose($this->shift, $this->cashier);
        // The page was read before the close: the model still says pending.
        $stale = Transaction::find($sale->id)->forceFill(['payment_status' => 'pending'])->syncOriginal();
        try {
            app(TransactionTenderService::class)->confirmTransfer($stale);
            $this->fail('a cancelled sale was confirmed');
        } catch (ValidationException) {
            $this->assertSame('failed', $sale->fresh()->payment_status);
        }
    }

    public function test_a_transfer_bill_booked_at_close_and_confirmed_later_is_marked_paid_after_close(): void
    {
        $bill = $this->guestPayLater($this->shift);
        $bill->update(['payment_method' => 'bank_transfer', 'payment_status' => 'pending']);
        $this->forceClose($this->shift, $this->cashier);
        app(TransactionTenderService::class)->confirmTransfer($bill->fresh());
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertNotNull($bill->fresh()->paid_after_close_at);
        $this->assertSame(1, AuditLog::where('event', 'payment.paid_after_close')->count());
    }

    public function test_a_not_collected_dish_that_was_cooked_is_not_restocked_by_default(): void
    {
        $this->cashier->givePermissionTo(Permission::firstOrCreate(['name' => 'sales-returns-create', 'guard_name' => 'web']));
        $cooked = $this->kitchenSale($this->shift, KitchenItem::STATUS_READY);
        $cooked->update(['preparing_at' => now()]);
        $untouched = $this->kitchenSale($this->shift);
        $this->forceClose($this->shift, $this->cashier);
        foreach ([[$cooked, false], [$untouched, true]] as [$item, $restock]) {
            $this->actingAs($this->cashier)->get(route('sales-returns.create', $item->transaction_id))
                ->assertInertia(fn (Assert $page) => $page->where('transaction.details.0.restock_default', $restock));
        }
    }

    public function test_other_shifts_neither_hold_the_close_nor_get_cancelled(): void
    {
        $otherShift = $this->openShiftFor(User::factory()->create());
        $item = $this->kitchenSale($otherShift);
        $bill = $this->guestPayLater($otherShift);
        $pending = $this->sale($otherShift, 'qris', 'pending');
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame(CashierShift::STATUS_CLOSED, $this->shift->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame('unpaid', $bill->fresh()->payment_status);
        $this->assertSame('pending', $pending->fresh()->payment_status);
        $this->assertCount(2, app(ShiftCloseBlockerService::class)->rows(app(ShiftCloseBlockerService::class)->blockersFor($otherShift)));
    }

    public function test_the_pos_api_close_returns_the_blockers(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $bill = $this->guestPayLater($this->shift);
        $this->postJson('/api/v1/pos/shift/close', ['closing_cash' => 100000])->assertStatus(422)
            ->assertJsonPath('errors.blockers.0.order', $bill->invoice);
        $this->assertTrue($this->shift->fresh()->isOpen());
    }

    public function test_the_v1_api_close_returns_the_blockers(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $bill = $this->guestPayLater($this->shift);
        cache()->put('api_step_up:user:'.$this->cashier->id, time());
        $this->postJson("/api/v1/cashier-shifts/{$this->shift->id}/close", ['actual_cash' => 100000])->assertStatus(422)
            ->assertJsonPath('errors.blockers.0.order', $bill->invoice);
    }

    public function test_not_collected_items_stay_out_of_the_speed_report(): void
    {
        $item = $this->kitchenSale($this->shift, KitchenItem::STATUS_READY);
        $this->forceClose($this->shift, $this->cashier);
        $this->assertNotNull($item->fresh()->ready_at);
        $report = app(KitchenSpeedReportService::class)->build([$this->warehouse->id], now()->subDay(), now()->addDay());
        $this->assertTrue($report['empty']);
    }

    public function test_credit_sale_with_an_expired_portal_charge_stays_in_the_recap(): void
    {
        $credit = $this->sale($this->shift, 'pay_later', 'failed', ['is_pay_later' => true]);
        Receivable::create(['transaction_id' => $credit->id, 'invoice' => $credit->invoice, 'total' => 20000, 'status' => 'unpaid']);
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->shift->fresh()->transactions_count);
        $this->assertSame(20000, $this->shift->fresh()->non_cash_sales_total);
    }

    public function test_restock_locks_the_stock_rows(): void
    {
        $this->sale($this->shift, 'qris', 'pending');
        DB::enableQueryLog();
        $this->close($this->shift)->assertSessionHasNoErrors();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $first = fn (string $pattern) => collect($queries)->search(fn (string $sql) => (bool) preg_match($pattern, $sql));
        $lock = $first('/^select \* from [`"]product_warehouse[`"] where [`"]warehouse_id[`"]/');
        $this->assertIsInt($lock);
        $this->assertLessThan($first('/^update [`"]product_warehouse[`"]/'), $lock);
        if (DB::getDriverName() === 'mysql') { // SQLite renders no lock clause
            $this->assertStringContainsString('for update', strtolower($queries[$lock]));
        }
    }

    // Intake runs after the payment commits, so a close can come first: it takes the sale in itself.
    public function test_a_paid_sale_that_has_not_reached_the_kitchen_holds_the_close(): void
    {
        $sale = $this->sale($this->shift, 'cash', 'paid', ['cash' => 20000]);
        $sale->details()->update(['needs_preparation' => true]);
        $this->close($this->shift)->assertSessionHasErrors('shift');
        // Kept although the close was refused, so the page lists it and offers the force close.
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, KitchenItem::where('transaction_id', $sale->id)->sole()->status);
        $this->forceClose($this->shift, $this->cashier);
        $this->assertSame(KitchenItem::STATUS_NOT_COLLECTED, KitchenItem::where('transaction_id', $sale->id)->sole()->status);
    }

    public function test_close_locks_the_kitchen_rows_of_a_sale_that_arrived_after_the_ids_were_read(): void
    {
        $this->kitchenSale($this->shift);
        $arrived = false;
        $itemLocks = 0;
        DB::listen(function ($query) use (&$arrived, &$itemLocks) {
            if (! $arrived && preg_match('/^select \* from [`"]cashier_shifts[`"]/', $query->sql)) {
                $arrived = true;
                $this->kitchenSale($this->shift);
            }
            $itemLocks += preg_match('/^select [`"]id[`"] from [`"]kitchen_items[`"] where/', $query->sql);
        });
        $this->forceClose($this->shift, $this->cashier);
        $this->assertSame(2, $itemLocks);
    }

    // The webhook's order. Marking a dine bill paid can complete its order, which a close locks before the bill.
    public function test_confirming_a_transfer_locks_shift_dine_order_receivable_then_sale(): void
    {
        $sale = $this->sale($this->shift, 'bank_transfer', 'pending');
        DB::enableQueryLog();
        app(TransactionTenderService::class)->confirmTransfer($sale);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertMatchesRegularExpression('/from [`"]cashier_shifts[`"] where/', $queries[0]);
        $this->assertMatchesRegularExpression('/from [`"]dine_orders[`"] where [`"]transaction_id[`"]/', $queries[1]);
        $this->assertMatchesRegularExpression('/from [`"]receivables[`"] where [`"]transaction_id[`"]/', $queries[2]);
        $this->assertMatchesRegularExpression('/from [`"]transactions[`"] where/', $queries[3]);
    }

    // MySQL fixes its snapshot at the first plain read, and two lock orders can deadlock: all rows are locked first.
    public function test_close_locks_items_dine_orders_and_sales_before_it_reads_them(): void
    {
        $this->kitchenSale($this->shift);
        DB::enableQueryLog();
        $this->forceClose($this->shift, $this->cashier);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $start = $queries->search(fn (string $sql) => (bool) preg_match('/^select \* from [`"]cashier_shifts[`"]/', $sql));
        foreach (['cashier_shifts', 'kitchen_items', 'dine_orders', 'transactions'] as $step => $table) {
            $this->assertMatchesRegularExpression("/^select .+ from [`\"]{$table}[`\"] where/", $queries[$start + $step]);
            if (DB::getDriverName() === 'mysql') { // SQLite renders no lock clause
                $this->assertStringContainsString('for update', $queries[$start + $step]);
            }
        }
    }

    // The bill is created under the shift lock, so it cannot land on a shift that a close has just passed.
    public function test_accepting_an_order_locks_the_shift_inside_its_transaction(): void
    {
        $outer = DB::transactionLevel();
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            $reads[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });
        $this->acceptedOrder();
        $shiftRead = collect($reads)->first(fn (array $read) => (bool) preg_match('/^select \* from [`"]cashier_shifts[`"] where [`"]user_id[`"]/', $read['sql']));
        $this->assertGreaterThan($outer, $shiftRead['level']);
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', $shiftRead['sql']);
        }
    }

    public function test_unpaid_bills_total_migration_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_12_08_100001_add_unpaid_bills_total_to_cashier_shifts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('cashier_shifts', 'unpaid_bills_total'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('cashier_shifts', 'unpaid_bills_total'));
    }

    // ---- #396: partly paid split sales ----------------------------------------------------------------

    public function test_partly_paid_split_holds_the_close_with_amount_owed(): void
    {
        $split = $this->splitSale($this->shift);
        $stock = $this->stock();
        $this->close($this->shift)->assertSessionHasErrors('shift');
        $this->assertTrue($this->shift->fresh()->isOpen());
        $this->assertSame('pending', $split->fresh()->payment_status);
        $this->assertSame($stock, $this->stock());
        $this->assertSame(0, AuditLog::where('event', 'like', 'cashier_shift.%')->count());
        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 1)
            ->where('closeBlockers.0', ['type' => 'split', 'order' => $split->invoice, 'label' => null, 'amount' => 15000]));
    }

    public function test_both_close_apis_refuse_with_the_partly_paid_split(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        cache()->put('api_step_up:user:'.$this->cashier->id, time());
        $split = $this->splitSale($this->shift);
        $closes = ['/api/v1/pos/shift/close' => 'closing_cash', "/api/v1/cashier-shifts/{$this->shift->id}/close" => 'actual_cash'];
        foreach ($closes as $url => $field) {
            $this->postJson($url, [$field => 105000])->assertStatus(422)
                ->assertJsonPath('errors.blockers.0.type', 'split')
                ->assertJsonPath('errors.blockers.0.order', $split->invoice)
                ->assertJsonPath('errors.blockers.0.amount', 15000);
        }
        $this->assertTrue($this->shift->fresh()->isOpen());
    }

    public function test_split_with_failed_gateway_part_still_holds(): void
    {
        $split = $this->splitSale($this->shift, 'failed', [['cash', 'paid', 8000], ['qris', 'failed', 12000]]);
        $this->close($this->shift)->assertSessionHasErrors('shift');
        $this->assertSame(12000, app(ShiftCloseBlockerService::class)->blockersFor($this->shift)['splits']->sole()->unpaidAmount());
        $this->assertFalse($split->fresh()->wasRestocked());
    }

    public function test_unpaid_split_is_cancelled_not_held(): void
    {
        $split = $this->splitSale($this->shift, 'pending', [['bank_transfer', 'pending', 8000], ['qris', 'pending', 12000]]);
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertTrue($split->fresh()->wasRestocked());
        $this->assertSame(['warehouse' => 20, 'product' => 20], $this->stock());
    }

    public function test_an_unsettled_split_with_money_is_held_and_one_without_is_cancelled_never_both(): void
    {
        $held = collect(['pending', 'pending_approval', 'failed', 'expired', 'unpaid'])
            ->map(fn (string $status) => $this->splitSale($this->shift, $status)->id);
        $cancelled = collect(['pending', 'failed', 'expired'])
            ->map(fn (string $status) => $this->splitSale($this->shift, $status, [['bank_transfer', 'pending', 20000]])->id);
        $this->splitSale($this->shift, 'paid', [['cash', 'paid', 5000], ['bank_transfer', 'paid', 15000]]);

        $this->assertEqualsCanonicalizing($held->all(), Transaction::query()->partlyPaidSplit()->pluck('id')->all());
        $this->assertEqualsCanonicalizing($cancelled->all(), Transaction::query()->unpaidPayNow()->pluck('id')->all());
    }

    public function test_split_with_completed_return_does_not_hold(): void
    {
        $split = $this->splitSale($this->shift);
        $this->completedReturn($split);
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame('pending', $split->fresh()->payment_status);
        $this->assertFalse($split->fresh()->wasRestocked());
    }

    public function test_other_shifts_partly_paid_split_does_not_hold(): void
    {
        $other = $this->splitSale($this->openShiftFor(User::factory()->create()));
        $this->close($this->shift)->assertSessionHasNoErrors();
        $this->assertSame('pending', $other->fresh()->payment_status);
        $this->assertSame(0, $this->shift->fresh()->cash_sales_total);
    }

    public function test_force_close_keeps_partly_paid_split_payable(): void
    {
        $split = $this->splitSale($this->shift);
        $stock = $this->stock();

        $this->close($this->shift, $this->manager(), ['force' => true, 'actual_cash' => 105000, 'close_notes' => 'Transfer ditunggu'])
            ->assertSessionHasNoErrors();

        $closed = $this->shift->fresh();
        $this->assertSame(CashierShift::STATUS_FORCE_CLOSED, $closed->status);
        $this->assertSame('pending', $split->fresh()->payment_status);
        $this->assertSame(['paid', 'pending'], $split->tenders()->orderBy('id')->pluck('payment_status')->all());
        $this->assertSame($stock, $this->stock());
        $this->assertSame(
            ['cash_sales_total' => 5000, 'non_cash_sales_total' => 0, 'expected_cash' => 105000, 'cash_difference' => 0, 'unpaid_bills_total' => 0],
            Arr::mapWithKeys(
                ['cash_sales_total', 'non_cash_sales_total', 'expected_cash', 'cash_difference', 'unpaid_bills_total'],
                fn (string $column) => [$column => $closed->{$column}],
            ),
        );
        $meta = AuditLog::where('event', 'cashier_shift.hold_overridden')->sole()->meta;
        // assertEquals: MySQL's JSON column does not keep the key order.
        $this->assertEquals([['invoice' => $split->invoice, 'owed' => 15000]], $meta['partly_paid_splits']);
    }

    /** @return array<string, array<int, string>> */
    public static function confirmActions(): array
    {
        return ['dashboard' => ['web'], 'API' => ['api']];
    }

    #[DataProvider('confirmActions')]
    public function test_split_transfer_confirmed_after_force_close_is_flagged(string $via): void
    {
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko', 'is_active' => true]);
        $split = $this->splitSale($this->shift, attributes: ['bank_account_id' => $account->id]);
        $this->forceClose($this->shift, $this->cashier);
        $next = $this->openShiftFor($this->cashier);
        $this->cashier->givePermissionTo(Permission::firstOrCreate(['name' => 'transactions-confirm-payment', 'guard_name' => 'web']));

        if ($via === 'web') {
            $this->withSession($this->recentlyConfirmedSession())->actingAs($this->cashier)
                ->patch(route('transactions.confirm-payment', $split))->assertSessionHas('success');
        } else {
            Sanctum::actingAs($this->cashier, ['*']);
            cache()->put('api_step_up:user:'.$this->cashier->id, time());
            $this->postJson("/api/v1/transactions/{$split->getRouteKey()}/confirm-payment")->assertOk();
        }

        $split->refresh();
        $this->assertSame('paid', $split->payment_status);
        $this->assertNotNull($split->paid_after_close_at);
        // The late money is the transfer, not the 20000 sale (#531 BR-1), and confirming it stamps the tender paid.
        $this->assertSame(15000, $split->paid_after_close_amount);
        $this->assertNotNull($split->tenders()->where('method', 'bank_transfer')->sole()->paid_at);
        $this->assertSame($this->shift->id, $split->cashier_shift_id);
        $meta = AuditLog::where('event', 'payment.paid_after_close')->sole()->meta;
        $this->assertSame([$this->shift->id, 15000], [$meta['shift_id'], $meta['amount']]);
        // Counted once: the closed recap keeps only what it received, and no other shift takes the late part.
        $this->assertSame(0, $this->shift->fresh()->non_cash_sales_total);
        $summary = app(CashierShiftService::class)->calculateSummary($next);
        $this->assertSame([0, 0], [$summary['cash_sales_total'], $summary['non_cash_sales_total']]);
    }

    public function test_the_late_audit_logs_what_was_asked_when_a_return_came_while_the_transfer_waited(): void
    {
        $split = $this->splitSale($this->shift);
        $this->forceClose($this->shift, $this->cashier);
        $this->completedReturn($split, 4000);

        app(TransactionTenderService::class)->confirmTransfer($split);

        $this->assertSame(15000, AuditLog::where('event', 'payment.paid_after_close')->sole()->meta['amount']);
        $this->assertSame(15000, $split->fresh()->paid_after_close_amount);
    }

    public function test_two_late_parts_of_a_split_are_both_counted(): void
    {
        // Both transfers are marked paid by the confirmation, after the close: the stored amount is their sum. The cash
        // taken at checkout has no paid_at, so it never counts, even though the sale is partly paid before the close.
        $split = $this->splitSale($this->shift, tenders: [['cash', 'paid', 5000], ['bank_transfer', 'pending', 6000], ['bank_transfer', 'pending', 9000]]);
        $this->forceClose($this->shift, $this->cashier);
        app(TransactionTenderService::class)->confirmTransfer($split);

        $this->assertSame(15000, $split->fresh()->paid_after_close_amount);
    }

    public function test_split_gateway_part_paid_after_force_close_is_flagged(): void
    {
        $this->useGateway('midtrans', false);
        $split = $this->splitSale($this->shift, 'pending', [['cash', 'paid', 5000], ['qris', 'pending', 15000]]);
        $this->forceClose($this->shift, $this->cashier);

        $this->notify('midtrans', "{$split->invoice}-qris", 'paid', amount: 15000)->assertOk();

        $this->assertSame('paid', $split->fresh()->payment_status);
        $this->assertNotNull($split->fresh()->paid_after_close_at);
        $this->assertSame(15000, $split->fresh()->paid_after_close_amount);
        $this->assertEquals(15000, AuditLog::where('event', 'payment.paid_after_close')->sole()->meta['amount']);
        $this->assertSame(0, $this->shift->fresh()->non_cash_sales_total);
    }

    public function test_split_paid_in_open_shift_is_not_flagged(): void
    {
        $split = $this->splitSale($this->shift);
        app(TransactionTenderService::class)->confirmTransfer($split);
        $this->assertSame('paid', $split->fresh()->payment_status);
        $this->assertNull($split->fresh()->paid_after_close_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.paid_after_close')->count());
        $this->assertSame(15000, app(CashierShiftService::class)->calculateSummary($this->shift)['non_cash_sales_total']);
    }

    public function test_recap_counts_only_received_split_money(): void
    {
        $waiting = $this->splitSale($this->shift);
        $this->splitSale($this->shift, 'failed', [['cash', 'paid', 8000], ['qris', 'failed', 12000]]);
        $this->splitSale($this->shift, 'pending', [['bank_transfer', 'paid', 4000], ['qris', 'pending', 16000]]);
        $recap = fn () => Arr::only(
            app(CashierShiftService::class)->calculateSummary($this->shift),
            ['cash_sales_total', 'non_cash_sales_total', 'expected_cash'],
        );

        $this->assertSame(['cash_sales_total' => 13000, 'non_cash_sales_total' => 4000, 'expected_cash' => 113000], $recap());

        app(TransactionTenderService::class)->confirmTransfer($waiting);

        $this->assertSame(['cash_sales_total' => 13000, 'non_cash_sales_total' => 19000, 'expected_cash' => 113000], $recap());
    }

    public function test_closed_shift_keeps_its_stored_recap_after_the_rule_change(): void
    {
        $this->splitSale($this->shift);
        // Closed before #396: the stored totals hold the waiting transfer as non-cash.
        $this->shift->update([
            'status' => CashierShift::STATUS_CLOSED, 'closed_at' => now(),
            'cash_sales_total' => 5000, 'non_cash_sales_total' => 15000, 'expected_cash' => 105000,
        ]);

        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))
            ->assertInertia(fn (Assert $page) => $page->where('cashierShift.non_cash_sales_total', 15000));
        $this->get(route('cashier-shifts.report', [$this->shift, 'Z']))->assertOk()
            ->assertSeeInOrder(['Penjualan Non Tunai', '15.000']);
    }

    // ---- #396 BR-7: pay later without a receivable ----------------------------------------------------

    public function test_unpaid_pay_later_sale_without_receivable_is_not_non_cash(): void
    {
        $this->guestPayLater($this->shift);
        $credit = $this->guestPayLater($this->shift);
        Receivable::create(['transaction_id' => $credit->id, 'invoice' => $credit->invoice, 'total' => 10000, 'paid' => 0,
            'due_date' => now()->addWeek(), 'status' => 'unpaid']);
        $recap = fn () => app(CashierShiftService::class)->calculateSummary($this->shift)['non_cash_sales_total'];

        $this->assertSame(10000, $recap()); // only the credit sale, counted when sold
    }

    public function test_force_closed_shift_counts_unpaid_pay_later_once(): void
    {
        $this->guestPayLater($this->shift);
        $this->forceClose($this->shift, $this->cashier);

        $closed = $this->shift->fresh();
        $this->assertSame(
            [0, 10000, -10000],
            [$closed->non_cash_sales_total, $closed->unpaid_bills_total, $closed->cash_difference],
        );
    }

    public function test_a_pay_later_sale_settled_after_its_shift_closed_is_flagged_and_counts_in_the_taking_shift(): void
    {
        $bill = $this->guestPayLater($this->shift);
        $this->forceClose($this->shift, $this->cashier);
        $next = $this->openShiftFor($this->cashier);

        $this->settleInCash($bill);

        $bill->refresh();
        $this->assertNotNull($bill->paid_after_close_at);
        $this->assertSame($next->id, $bill->cashier_shift_id);
        $meta = AuditLog::where('event', 'payment.paid_after_close')->sole()->meta;
        $this->assertSame([$this->shift->id, 10000], [$meta['shift_id'], $meta['amount']]);
        $this->assertSame(10000, app(CashierShiftService::class)->calculateSummary($next)['cash_sales_total']);
        $this->assertSame(0, $this->shift->fresh()->cash_sales_total);
    }

    public function test_a_pay_later_sale_settled_while_its_shift_is_open_is_not_flagged(): void
    {
        $bill = $this->guestPayLater($this->shift);

        $this->settleInCash($bill);

        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertNull($bill->fresh()->paid_after_close_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.paid_after_close')->count());
    }

    /** @return array<string, array<int, string>> */
    public static function waitingTillMethods(): array
    {
        return ['bank transfer, confirmed' => ['bank_transfer'], 'Midtrans, webhook' => ['midtrans']];
    }

    // The settlement moves the bill to the paying shift while it is still pending: that shift is open when the money arrives.
    #[DataProvider('waitingTillMethods')]
    public function test_a_booked_pay_later_sale_settled_without_cash_is_flagged_when_the_money_arrives(string $method): void
    {
        $bill = $this->guestPayLater($this->shift);
        $this->forceClose($this->shift, $this->cashier);
        $next = $this->openShiftFor($this->cashier);

        $this->settleAndReceive($bill, $method);

        $bill->refresh();
        $this->assertSame(['paid', $next->id], [$bill->payment_status, $bill->cashier_shift_id]);
        $this->assertNotNull($bill->paid_after_close_at);
        $meta = AuditLog::where('event', 'payment.paid_after_close')->sole()->meta;
        $this->assertEquals([$this->shift->id, 10000], [$meta['shift_id'], $meta['amount']]);
        $this->assertSame(0, $this->shift->fresh()->non_cash_sales_total);
        $this->assertSame(10000, app(CashierShiftService::class)->calculateSummary($next)['non_cash_sales_total']);
    }

    #[DataProvider('waitingTillMethods')]
    public function test_a_pay_later_sale_settled_without_cash_while_its_shift_is_open_is_not_flagged(string $method): void
    {
        $bill = $this->guestPayLater($this->shift);

        $this->settleAndReceive($bill, $method);

        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertNull($bill->fresh()->paid_after_close_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.paid_after_close')->count());
    }

    /** Settled at the till by transfer or Midtrans, then confirmed or settled by the webhook: unmarked until then. */
    private function settleAndReceive(Transaction $bill, string $method): void
    {
        $this->useGateway('midtrans');
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko', 'is_active' => true]);

        app(TransactionSettlementService::class)->settle($bill, $this->cashier, [
            ['method' => $method, 'amount' => 10000, 'bank_account_id' => $account->id],
        ]);
        $this->assertSame(['pending', null], [$bill->fresh()->payment_status, $bill->fresh()->paid_after_close_at]);

        if ($method === 'bank_transfer') {
            app(TransactionTenderService::class)->confirmTransfer($bill->fresh());
        } else {
            $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', 10000)->assertOk();
        }
    }

    private function settleInCash(Transaction $bill): void
    {
        app(TransactionSettlementService::class)->settle($bill, $this->cashier, [
            ['method' => 'cash', 'amount' => 10000, 'cash_received' => 10000],
        ]);
    }

    private function openShiftFor(User $user): CashierShift
    {
        return app(CashierShiftService::class)->openShift($user, $user, 100000, null, $this->warehouse->id);
    }

    private function manager(): User
    {
        return User::factory()->create()->givePermissionTo(['cashier-shifts-access', 'cashier-shifts-close', 'cashier-shifts-force-close']);
    }

    /** @param  array<string, mixed>  $data */
    private function close(CashierShift $shift, ?User $actor = null, array $data = [])
    {
        return $this->withSession($this->recentlyConfirmedSession())->actingAs($actor ?? $this->cashier)
            ->post(route('cashier-shifts.close', $shift), ['actual_cash' => 100000, ...$data]);
    }

    private function forceClose(CashierShift $shift, User $actor): void
    {
        app(CashierShiftService::class)->closeShift($shift, $actor, 100000, 'Tutup paksa', forceClose: true);
    }

    /** A sale of 2 x Rujak (20000) whose stock has left the shelf, as checkout leaves it. */
    private function sale(CashierShift $shift, string $method, string $status, array $attributes = []): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => $shift->user_id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)), 'cash' => 0, 'change' => 0, 'discount' => 0,
            'grand_total' => 20000, 'payment_method' => $method, 'payment_status' => $status, ...$attributes,
        ]);
        // Not a kitchen line: kitchenSale() adds the item itself, and the close would take in any other.
        $sale->details()->create(['product_id' => $this->flagged->id, 'qty' => 2, 'price' => 20000, 'needs_preparation' => false]);
        $this->flagged->decrement('stock', 2);
        DB::table('product_warehouse')->where('product_id', $this->flagged->id)->decrement('stock', 2);

        return $sale;
    }

    /**
     * A Bayar sekarang split sale of 20000: by default 5000 paid in cash and a 15000 transfer still waiting.
     *
     * @param  array<int, array{string, string, int}>  $tenders  method, status, amount
     * @param  array<string, mixed>  $attributes
     */
    private function splitSale(
        CashierShift $shift,
        string $status = 'pending',
        array $tenders = [['cash', 'paid', 5000], ['bank_transfer', 'pending', 15000]],
        array $attributes = [],
    ): Transaction {
        $sale = $this->sale($shift, 'split', $status, $attributes);
        foreach ($tenders as [$method, $state, $amount]) {
            $this->tender($sale, $method, $state, $amount);
        }

        return $sale;
    }

    /** A completed return, of no value by default: enough to take the sale out of the close's cancel and hold (BR-15). */
    private function completedReturn(Transaction $sale, int $amount = 0): void
    {
        DB::table('sales_returns')->insert([
            'code' => "SR-{$sale->id}", 'transaction_id' => $sale->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => 0, 'credited_amount' => 0, 'total_return_amount' => $amount,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A guest's unpaid Bayar Belakangan sale of 10000. */
    private function guestPayLater(CashierShift $shift): Transaction
    {
        return $this->sale($shift, 'pay_later', 'unpaid', ['is_pay_later' => true, 'grand_total' => 10000]);
    }

    /** A paid sale of 20000 in cash with one kitchen item in the given state. */
    private function kitchenSale(CashierShift $shift, string $status = KitchenItem::STATUS_ACCEPTED): KitchenItem
    {
        $sale = $this->sale($shift, 'cash', 'paid', ['cash' => 20000]);

        return KitchenItem::create([
            'transaction_id' => $sale->id, 'transaction_detail_id' => $sale->details()->value('id'),
            'product_id' => $this->flagged->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 2, 'status' => $status,
            'accepted_at' => now(),
            'ready_at' => in_array($status, [KitchenItem::STATUS_READY, KitchenItem::STATUS_SERVED], true) ? now() : null,
        ]);
    }

    private function tender(Transaction $sale, string $method, string $status, int $amount): TransactionTender
    {
        return $sale->tenders()->create(['method' => $method, 'amount' => $amount, 'payment_status' => $status]);
    }

    /** @return array{warehouse: int, product: int} */
    private function stock(): array
    {
        return [
            'warehouse' => (int) DB::table('product_warehouse')->where('product_id', $this->flagged->id)->value('stock'),
            'product' => (int) $this->flagged->fresh()->stock,
        ];
    }
}
