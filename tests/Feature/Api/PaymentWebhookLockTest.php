<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * REQ-005 (#327 server half): the webhook decides on the bill as it is under the locks, and a payment is booked at
 * most once. Row locks cannot be seen on SQLite: the order of the locking reads and the re-read are asserted instead,
 * and true concurrency is only exercised by the MySQL job.
 */
class PaymentWebhookLockTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
    }

    private function eventCount(string $event): int
    {
        return AuditLog::where('event', $event)->count();
    }

    /** A bill the guest has charged $attempts times (QRIS), with its order. @return array{0: \App\Models\DineOrder, 1: Transaction} */
    private function guestBill(string $gateway, int $attempts = 1): array
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        for ($i = 0; $i < $attempts; $i++) {
            $this->guestPays($order)->assertOk();
        }

        return [$order, $this->bill($order)];
    }

    // ---- locks and the fresh read -------------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_webhook_rereads_the_bill_under_the_lock(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway);

        // The cashier takes cash and commits after the webhook's first read, before it takes its locks.
        $fired = false;
        Transaction::retrieved(function (Transaction $read) use ($bill, &$fired) {
            if ($fired || $read->id !== $bill->id) {
                return;
            }
            $fired = true;
            DB::table('transactions')->where('id', $bill->id)->update([
                'payment_attempt' => 2, 'payment_status' => 'paid', 'payment_method' => 'cash', 'payment_reference' => null,
            ]);
        });

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertTrue($fired);
        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame('cash', $bill->payment_method);
        $this->assertNull($bill->payment_reference); // the gateway's reference did not overwrite the cashier's payment
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, $this->eventCount('payment.late_superseded'));
    }

    public function test_a_callback_after_a_new_claim_is_judged_against_the_new_attempt(): void
    {
        [, $bill] = $this->guestBill('midtrans');

        // The guest pressed Bayar again (claim of attempt 2) after the webhook's first read.
        $fired = false;
        Transaction::retrieved(function (Transaction $read) use ($bill, &$fired) {
            if ($fired || $read->id !== $bill->id) {
                return;
            }
            $fired = true;
            DB::table('transactions')->where('id', $bill->id)->update(['payment_attempt' => 2, 'payment_reference' => "{$bill->invoice}-r2"]);
        });

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        // Attempt 1 is no longer the current one: it is a late payment of an older attempt (first payment wins, no tenders, amount matches).
        $bill->refresh();
        $this->assertSame(2, $bill->payment_attempt);
        $log = AuditLog::where('event', 'payment.late_after_failed')->sole();
        $this->assertTrue($log->meta['booked']);
        $this->assertSame(1, $log->meta['order_attempt']);
        $this->assertSame(2, $log->meta['bill_attempt']);
    }

    public function test_locks_are_taken_shift_then_dine_order_then_receivable_then_bill_and_no_network_call_is_made(): void
    {
        [, $bill] = $this->guestBill('midtrans');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // SQLite quotes names with ", MySQL (the CI backend job) with `: match both.
        $first = fn (string $pattern) => collect($queries)->search(fn (string $sql) => (bool) preg_match($pattern, $sql));
        $shift = $first('/from [`"]cashier_shifts[`"]/');
        $order = $first('/from [`"]dine_orders[`"]/');
        $receivable = $first('/from [`"]receivables[`"]/');
        $locked = $first('/from [`"]transactions[`"] where [`"]transactions[`"]\.[`"]id[`"] = \?/');

        $this->assertIsInt($shift);
        $this->assertIsInt($order);
        $this->assertIsInt($receivable);
        $this->assertIsInt($locked);
        // The receivable controllers lock the receivable and then update the bill: the webhook must not take them the other way round.
        $this->assertTrue($shift < $order && $order < $receivable && $receivable < $locked, 'Lock order must be shift, dine order, receivable, bill.');
        Http::assertSentCount(1); // the guest's charge; the webhook calls nobody

        // SQLite renders no lock clause: only the MySQL job can show them.
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('lock in share mode', strtolower($queries[$shift]));
            $this->assertStringContainsString('for update', strtolower($queries[$order]));
            $this->assertStringContainsString('for update', strtolower($queries[$receivable]));
            $this->assertStringContainsString('for update', strtolower($queries[$locked]));
        }
    }

    /**
     * Each time the bill is read, move it to the next shift of the list: the read itself still sees the old one
     * (the event fires after hydration), the next read sees the new one.
     *
     * @param  array<int, int>  $toShiftIds
     */
    private function moveBillOnRead(Transaction $bill, array $toShiftIds): void
    {
        Transaction::retrieved(function (Transaction $read) use ($bill, &$toShiftIds) {
            if ($read->id === $bill->id && $toShiftIds !== []) {
                DB::table('transactions')->where('id', $bill->id)->update(['cashier_shift_id' => array_shift($toShiftIds)]);
            }
        });
    }

    private function otherShift(string $status = CashierShift::STATUS_OPEN): CashierShift
    {
        $user = User::factory()->create();

        return CashierShift::create([
            'user_id' => $user->id, 'opened_by' => $user->id, 'opened_at' => now(), 'opening_cash' => 0, 'expected_cash' => 0,
            'warehouse_id' => $this->warehouse->id, 'status' => $status, 'closed_at' => $status === CashierShift::STATUS_OPEN ? null : now(),
        ]);
    }

    public function test_a_bill_moved_to_another_shift_before_the_lock_is_judged_against_the_new_shift(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $stale = $this->closeShiftOf($bill); // what the first read sees: a closed shift, which would flag the payment
        $current = $this->otherShift();
        $this->moveBillOnRead($bill, [$current->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame($current->id, $bill->cashier_shift_id);
        $this->assertNull($bill->paid_after_close_at); // judged against the open shift it is in
        $this->assertSame(0, $this->eventCount('payment.paid_after_close'));
        $this->assertSame(self::TOTAL, app(CashierShiftService::class)->calculateSummary($current)['non_cash_sales_total']);
        $this->assertSame(0, (int) $stale->fresh()->non_cash_sales_total); // the closed snapshot is untouched
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());

        // Two rounds: the shift of the first read, then the shift the bill turned out to be in.
        $shiftReads = collect($log)->filter(fn (array $q) => (bool) preg_match('/from [`"]cashier_shifts[`"]/', $q['query']));
        $this->assertSame([$stale->id, $current->id], $shiftReads->pluck('bindings.0')->all());
    }

    public function test_a_bill_moved_to_a_closed_shift_before_the_lock_is_flagged(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $closed = $this->otherShift(CashierShift::STATUS_CLOSED);
        $this->moveBillOnRead($bill, [$closed->id]); // the first read saw the open shift

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNotNull($bill->paid_after_close_at);
        $this->assertSame($closed->id, AuditLog::where('event', 'payment.paid_after_close')->sole()->meta['shift_id']);
    }

    public function test_a_bill_that_keeps_moving_between_shifts_is_not_booked_and_the_redelivery_is(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $second = $this->otherShift();
        $third = $this->otherShift();
        // After the first read the bill is in $second (round 1 finds it there), after the round-1 lock read it is in $third.
        $this->moveBillOnRead($bill, [$second->id, $third->id]);

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertStatus(500); // fails closed: the gateway redelivers

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertNull($bill->paid_after_close_at);
        $this->assertSame(0, KitchenItem::where('transaction_id', $bill->id)->count());

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame($third->id, $bill->cashier_shift_id);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    /** A cashier charge made before #358: attempt 0, so the gateway knows it by the bare invoice. */
    private function cashierChargeAtAttemptZero(DineOrder $order): void
    {
        $this->cashierPays($order, 'qris')->update(['payment_attempt' => 0]);
    }

    // ---- who wins ------------------------------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_late_guest_payment_after_takeover_is_marked_not_booked(string $gateway): void
    {
        [$order, $bill] = $this->guestBill($gateway);
        $this->cashierPays($order);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('cash', $bill->payment_method);
        $this->assertNull($bill->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count()); // not started a second time
        $log = AuditLog::where('event', 'payment.late_superseded')->sole();
        $this->assertSame($gateway, $log->meta['gateway']);
        $this->assertSame("{$bill->invoice}-r1", $log->meta['order_id']);

        // A repeat of the same notification adds nothing.
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();
        $this->assertSame(1, $this->eventCount('payment.late_superseded'));
    }

    #[DataProvider('gateways')]
    public function test_redelivered_settlement_is_not_marked_late(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame(0, $this->eventCount('payment.late_superseded'));
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    #[DataProvider('gateways')]
    public function test_redelivery_of_a_late_booked_settlement_is_not_marked_late(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway, attempts: 2);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk(); // booked: the guest paid the older QR
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk(); // the gateway sends it again

        $this->assertNull($bill->fresh()->late_superseded_payment_at);
        $this->assertSame(1, $this->eventCount('payment.late_after_failed'));
        $this->assertSame(0, $this->eventCount('payment.late_superseded'));
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    #[DataProvider('gateways')]
    public function test_redelivery_of_a_cashier_gateway_payment_is_not_marked_late(string $gateway): void
    {
        [$order, $bill] = $this->guestBill($gateway);
        $this->cashierPays($order, 'qris');

        $this->notify($gateway, "{$bill->invoice}-r2", 'paid')->assertOk();
        $this->notify($gateway, "{$bill->invoice}-r2", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame(0, $this->eventCount('payment.late_superseded'));
    }

    // ---- late payments: booked or flagged -------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_late_settlement_on_an_unpaid_bill_without_tenders_is_booked(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway);
        $this->notify($gateway, "{$bill->invoice}-r1", 'expired')->assertOk();
        $this->assertSame('failed', $bill->fresh()->payment_status);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(1, $bill->payment_attempt);
        $this->assertNull($bill->payment_failure_reason);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count()); // the kitchen starts through the paid hook
        $log = AuditLog::where('event', 'payment.late_after_failed')->sole();
        $this->assertTrue($log->meta['booked']);
        $this->assertSame('failed', $log->meta['prior_status']);
    }

    #[DataProvider('gateways')]
    public function test_older_guest_attempt_paid_while_the_newer_is_pending_is_booked(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway, attempts: 2);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(2, $bill->payment_attempt); // a late booking leaves the counter alone
        $this->assertNull($bill->late_superseded_payment_at);
        $prefix = $gateway === 'midtrans' ? 'mt' : 'xnd';
        $this->assertSame("{$prefix}-{$bill->invoice}-r1", $bill->payment_reference);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_newer_attempt_paid_after_an_older_one_was_booked_is_flagged(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway, attempts: 2);
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();
        $booked = $bill->fresh()->payment_reference;

        $this->notify($gateway, "{$bill->invoice}-r2", 'paid')->assertOk(); // the guest paid the second QR too

        $bill->refresh();
        $this->assertSame($booked, $bill->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, $this->eventCount('payment.late_superseded'));
    }

    #[DataProvider('gateways')]
    public function test_late_settlement_on_a_paid_bill_is_flagged(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway, attempts: 2);
        $this->notify($gateway, "{$bill->invoice}-r2", 'paid')->assertOk();
        $booked = $bill->fresh()->payment_reference;

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame($booked, $bill->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, $this->eventCount('payment.late_superseded'));
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    #[DataProvider('gateways')]
    public function test_late_settlement_with_cashier_tenders_is_flagged(string $gateway): void
    {
        [$order, $bill] = $this->guestBill($gateway);
        $this->cashierPays($order, 'qris'); // the cashier is collecting: attempt 2, tender rows, pending

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
        $this->assertSame(0, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    #[DataProvider('gateways')]
    public function test_late_settlement_with_a_different_amount_is_flagged(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway);
        $this->notify($gateway, "{$bill->invoice}-r1", 'failed')->assertOk();

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid', amount: 20000)->assertOk();

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_late_payment_of_the_remaining_total_is_booked(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        // A partial return before the charge: the guest is charged what is still owed.
        SalesReturn::create([
            'code' => 'SR-'.Str::upper(Str::random(6)), 'transaction_id' => $bill->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => 5000, 'credited_amount' => 0, 'total_return_amount' => 5000, 'completed_at' => now(),
        ]);
        $this->guestPays($order)->assertOk()->assertJsonPath('payment.amount', 20000);
        $this->notify($gateway, "{$bill->invoice}-r1", 'failed', amount: 20000)->assertOk();

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid', amount: 20000)->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    public function test_a_restocked_sale_is_not_booked_late(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $this->notify('midtrans', "{$bill->invoice}-r1", 'failed')->assertOk();
        // ExpirePendingTransactionsCommand returned the stock of this sale: there is nothing left to hand over.
        StockMutation::create([
            'product_id' => $this->flagged->id, 'warehouse_id' => $this->warehouse->id, 'reference_type' => 'transaction_expire',
            'reference_id' => $bill->id, 'mutation_type' => 'in', 'qty' => 2, 'stock_before' => 18, 'stock_after' => 20,
        ]);

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame('failed', $bill->fresh()->payment_status);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    // ---- attempt 0 keeps today's rules -----------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_a_failed_bill_without_a_payment_history_or_tenders_stays_failed(string $gateway): void
    {
        // Branch E: attempt 0, failed, no tender row, no receivable and no gateway method: nothing says a gateway ever charged it.
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $bill->update(['payment_status' => 'failed']); // payment_method stays open_bill: no gateway charge was ever made

        $this->notify($gateway, $bill->invoice, 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame(0, $this->eventCount('payment.late_after_failed'));
        $this->assertSame(0, $this->eventCount('payment.late_superseded'));
    }

    #[DataProvider('gateways')]
    public function test_an_expired_cashier_charge_of_a_dine_bill_without_a_payment_history_is_booked_when_paid_late(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->cashierChargeAtAttemptZero($order);
        $this->notify($gateway, $bill->invoice, 'expired')->assertOk();
        $tender = $bill->tenders()->sole();
        $this->assertSame('failed', $bill->fresh()->payment_status);
        $this->assertSame(0, $bill->fresh()->payment_attempt);

        $this->notify($gateway, $bill->invoice, 'paid', reference: 'late-ref')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(0, $bill->payment_attempt);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame('paid', $tender->fresh()->payment_status);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
        $log = AuditLog::where('event', 'payment.late_after_failed')->sole();
        $this->assertTrue($log->meta['booked']);
        $this->assertSame('late-ref', $log->meta['reference']);
    }

    #[DataProvider('gateways')]
    public function test_a_dine_bill_settled_by_cash_after_its_qr_expired_flags_the_late_payment(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->cashierChargeAtAttemptZero($order);
        $this->notify($gateway, $bill->invoice, 'expired')->assertOk();
        $this->cashierPays($order); // cash instead: attempt 0 stays, the failed QR tender is replaced by a paid cash tender
        $this->assertSame(0, $bill->fresh()->payment_attempt);

        $this->notify($gateway, $bill->invoice, 'paid', reference: 'gw-late')->assertOk(); // the customer paid the expired QR

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame('cash', $bill->payment_method);
        $this->assertNull($bill->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame('gw-late', AuditLog::where('event', 'payment.late_superseded')->sole()->meta['reference']);
    }

    #[DataProvider('gateways')]
    public function test_a_paid_bill_without_a_payment_history_ignores_a_redelivery_and_flags_another_reference(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->cashierChargeAtAttemptZero($order);
        $this->notify($gateway, $bill->invoice, 'paid', reference: 'first')->assertOk();
        // Only the tender holds the settlement's reference; the bill keeps the one its charge was created with.
        $this->assertSame('first', $bill->tenders()->sole()->payment_reference);
        $charge = $bill->fresh()->payment_reference;
        $this->assertNotSame('first', $charge);

        $this->notify($gateway, $bill->invoice, 'paid', reference: 'first')->assertOk(); // redelivered
        $this->assertNull($bill->fresh()->late_superseded_payment_at);
        $this->assertSame(0, $this->eventCount('payment.late_superseded'));

        $this->notify($gateway, $bill->invoice, 'paid', reference: 'second')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame($charge, $bill->payment_reference);
        $this->assertSame('first', $bill->tenders()->sole()->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame('second', AuditLog::where('event', 'payment.late_superseded')->sole()->meta['reference']);
    }

    public function test_a_split_gateway_tender_with_an_attempt_settles_by_its_order_id(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $bill->update(['payment_attempt' => 3, 'payment_method' => 'split', 'payment_status' => 'pending']);
        $bill->tenders()->createMany([
            ['method' => TransactionTender::METHOD_CASH, 'amount' => 10000, 'cash_received' => 10000, 'payment_status' => 'paid'],
            ['method' => TransactionTender::METHOD_QRIS, 'amount' => 15000, 'payment_status' => 'pending'],
        ]);
        $qris = $bill->tenders()->where('method', 'qris')->sole();
        $this->assertSame("{$bill->invoice}-qris-r3", $qris->order_reference);

        $this->notify('midtrans', "{$bill->invoice}-qris-r3", 'paid', amount: 15000)->assertOk();

        $this->assertSame('paid', $qris->fresh()->payment_status);
        $this->assertSame('paid', $bill->fresh()->payment_status);

        $bill->update(['payment_attempt' => 0]);
        $this->assertSame("{$bill->invoice}-qris", $qris->fresh()->order_reference);
    }

    // ---- paid after the owning shift closed ------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_paid_after_close_leaves_the_closed_recap_unchanged(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway);
        $closed = $this->closeShiftOf($bill);
        $this->assertSame(0, (int) $closed->non_cash_sales_total); // a pending gateway sale is not in the snapshot
        $snapshot = $closed->only(['cash_sales_total', 'non_cash_sales_total', 'expected_cash', 'actual_cash', 'transactions_count', 'status']);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNotNull($bill->paid_after_close_at);
        $this->assertSame($closed->id, $bill->cashier_shift_id);
        // The stored snapshot is what stays: a live recompute would now hold the bill, so this is not vacuous.
        $this->assertSame(self::TOTAL, app(CashierShiftService::class)->calculateSummary($closed)['non_cash_sales_total']);
        $this->assertEquals($snapshot, CashierShift::findOrFail($closed->id)->only(array_keys($snapshot)));
        $log = AuditLog::where('event', 'payment.paid_after_close')->sole();
        $this->assertSame($closed->id, $log->meta['shift_id']);
    }

    #[DataProvider('gateways')]
    public function test_paid_before_the_owning_shift_closes_lands_in_the_snapshot_without_a_flag(string $gateway): void
    {
        [, $bill] = $this->guestBill($gateway);

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();
        $closed = $this->closeShiftOf($bill);

        $this->assertSame(self::TOTAL, (int) $closed->non_cash_sales_total);
        $this->assertNull($bill->fresh()->paid_after_close_at);
        $this->assertSame(0, $this->eventCount('payment.paid_after_close'));
    }

    public function test_paid_while_the_owning_shift_is_open_counts_in_its_recap_without_a_flag(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $shift = CashierShift::findOrFail($bill->cashier_shift_id);
        $recap = fn () => app(CashierShiftService::class)->calculateSummary($shift)['non_cash_sales_total'];
        $this->assertSame(0, $recap()); // pending: no money yet

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame(self::TOTAL, $recap());
        $this->assertNull($bill->fresh()->paid_after_close_at);
        $this->assertSame(0, $this->eventCount('payment.paid_after_close'));
    }

    public function test_a_force_closed_shift_counts_as_closed(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $this->closeShiftOf($bill, force: true);

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertNotNull($bill->fresh()->paid_after_close_at);
    }

    public function test_a_bill_without_an_owning_shift_is_booked_without_a_flag(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        DB::table('transactions')->where('id', $bill->id)->update(['cashier_shift_id' => null]);

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->paid_after_close_at);
    }

    public function test_paid_after_close_flag_follows_the_recap_rule(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $dine = $this->bill($order);
        // A dine bill left open for the counter: its method is open_bill, which the recap leaves out until it is paid.
        $this->assertSame(Transaction::METHOD_OPEN_BILL, $dine->payment_method);
        $dine->update(['payment_status' => 'pending']);
        $closed = $this->closeShiftOf($dine);

        // A POS credit sale of the same closed shift: counted in its recap by its method, so paying its receivable later is no flag.
        $credit = Transaction::create([
            'cashier_id' => $this->cashier->id, 'cashier_shift_id' => $closed->id, 'invoice' => 'TRX-CREDIT-1', 'cash' => 0, 'change' => 0,
            'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 150000, 'payment_method' => 'pay_later', 'payment_status' => 'pending', 'is_pay_later' => true,
        ]);
        Receivable::create(['transaction_id' => $credit->id, 'invoice' => $credit->invoice, 'due_date' => now()->addDays(7), 'total' => 150000, 'paid' => 0, 'status' => 'unpaid']);

        $this->notify('midtrans', $dine->invoice, 'paid')->assertOk();
        $this->notify('midtrans', $credit->invoice, 'paid', amount: 150000)->assertOk();

        $this->assertNotNull($dine->fresh()->paid_after_close_at);
        $credit->refresh();
        $this->assertSame('paid', $credit->payment_status);
        $this->assertNull($credit->paid_after_close_at);
    }

    // ---- every distinct late payment is audited --------------------------------------------------------

    public function test_two_late_payments_with_different_references_are_both_audited_and_the_marker_is_set_once(): void
    {
        [$order, $bill] = $this->guestBill('midtrans');
        $this->cashierPays($order); // the guest's attempt 1 is superseded: whatever it pays is a refund

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', reference: 'ref-a')->assertOk();
        $marked = $bill->fresh()->late_superseded_payment_at;
        $this->assertNotNull($marked);
        $this->travel(5)->minutes();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', reference: 'ref-b')->assertOk();

        $logs = AuditLog::where('event', 'payment.late_superseded')->get();
        $this->assertSame(['ref-a', 'ref-b'], $logs->pluck('meta.reference')->sort()->values()->all());
        $this->assertSame("{$bill->invoice}-r1", $logs[0]->meta['order_id']);
        $this->assertEquals($marked, $bill->fresh()->late_superseded_payment_at); // set once
    }

    public function test_the_same_late_reference_twice_is_audited_once(): void
    {
        [$order, $bill] = $this->guestBill('midtrans');
        $this->cashierPays($order);

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', reference: 'ref-a')->assertOk();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', reference: 'ref-a')->assertOk();

        $this->assertSame(1, $this->eventCount('payment.late_superseded'));
    }

    public function test_late_payments_that_are_not_booked_are_audited_per_reference_too(): void
    {
        [, $bill] = $this->guestBill('xendit');
        $this->notify('xendit', "{$bill->invoice}-r1", 'failed')->assertOk();

        // Wrong amount twice, from two different payments, then the first one again.
        $this->notify('xendit', "{$bill->invoice}-r1", 'paid', amount: 1000, reference: 'ref-a')->assertOk();
        $this->notify('xendit', "{$bill->invoice}-r1", 'paid', amount: 1000, reference: 'ref-b')->assertOk();
        $this->notify('xendit', "{$bill->invoice}-r1", 'paid', amount: 1000, reference: 'ref-a')->assertOk();

        $logs = AuditLog::where('event', 'payment.late_after_failed')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([false, false], $logs->pluck('meta.booked')->all());
        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);
    }

    // ---- which tenders hold the bill against a late payment ---------------------------------------------

    #[DataProvider('gateways')]
    public function test_a_late_payment_of_an_expired_cashier_charge_is_booked(string $gateway): void
    {
        [$order, $bill] = $this->guestBill($gateway);
        $this->cashierPays($order, 'qris'); // her own QRIS: attempt 2, a pending tender
        $this->notify($gateway, "{$bill->invoice}-r2", 'expired')->assertOk();
        $tender = $bill->tenders()->sole();
        $this->assertSame('failed', $tender->fresh()->payment_status);
        $this->assertSame('failed', $bill->fresh()->payment_status);

        // The customer pays that QR after it expired: the failed tender never held money, so nothing blocks the booking.
        $this->notify($gateway, "{$bill->invoice}-r2", 'paid', reference: 'late-ref')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame('paid', $tender->fresh()->payment_status);
        $this->assertSame('late-ref', $tender->fresh()->payment_reference);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());

        $this->notify($gateway, "{$bill->invoice}-r2", 'paid', reference: 'late-ref')->assertOk(); // redelivered
        $this->assertSame(0, $this->eventCount('payment.late_superseded'));
        $this->assertSame(1, $this->eventCount('payment.late_after_failed'));
    }

    public function test_after_a_late_booking_a_payment_with_another_reference_is_a_second_payment(): void
    {
        [$order, $bill] = $this->guestBill('midtrans');
        $this->cashierPays($order, 'qris');
        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk();
        $this->notify('midtrans', "{$bill->invoice}-r2", 'paid', reference: 'late-ref')->assertOk();
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame('late-ref', $bill->fresh()->payment_reference); // what was booked, not the cashier charge's old reference

        // The cashier charge's own reference now arrives as paid too: that is another payment, not a redelivery.
        $this->notify('midtrans', "{$bill->invoice}-r2", 'paid', reference: 'cashier-charge-ref')->assertOk();

        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);
        $this->assertSame(2, $this->eventCount('payment.late_after_failed') + $this->eventCount('payment.late_superseded'));
    }

    public function test_a_pending_tender_blocks_the_late_booking(): void
    {
        [$order, $bill] = $this->guestBill('midtrans');
        $this->cashierPays($order, 'qris');
        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk();
        // The cashier is collecting again by transfer: a pending tender next to the expired one.
        $bill->tenders()->create(['method' => TransactionTender::METHOD_BANK_TRANSFER, 'amount' => self::TOTAL, 'payment_status' => 'pending']);
        $this->assertTrue($bill->fresh()->hasBlockingTenders());

        $this->notify('midtrans', "{$bill->invoice}-r2", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    /**
     * A guest's attempt 1 is paid while the cashier collects by QRIS (attempt 2): flagged. Then her QRIS expires, and the
     * re-check books the payment if its amount fits.
     *
     * @return array{0: DineOrder, 1: Transaction}
     */
    private function flaggedWhileACashierChargeIsPending(string $reference, ?int $amount = null): array
    {
        [$order, $bill] = $this->guestBill('midtrans');
        $this->cashierPays($order, 'qris');
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', amount: $amount, reference: $reference)->assertOk();
        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk(); // nothing holds the bill any more: re-checked

        return [$order, $bill];
    }

    public function test_a_flagged_payment_booked_when_the_cashier_charge_expires_loses_the_refund_marker_and_keeps_both_audits(): void
    {
        [, $bill] = $this->flaggedWhileACashierChargeIsPending('guest-ref');

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', reference: 'guest-ref')->assertOk(); // the gateway redelivers

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at); // no "Bayar ganda" on a bill that was paid once
        $logs = AuditLog::where('event', 'payment.late_after_failed')->orderBy('id')->get();
        $this->assertSame([false, true], $logs->pluck('meta.booked')->all());
        $this->assertSame(['guest-ref', 'guest-ref'], $logs->pluck('meta.reference')->all());
    }

    public function test_booking_keeps_the_refund_marker_when_a_different_reference_was_flagged(): void
    {
        [, $bill] = $this->flaggedWhileACashierChargeIsPending('ref-other', amount: 20000); // too little: stays flagged

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', reference: 'ref-booked')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame('ref-booked', $bill->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at); // the other payment still has to be refunded
        $logs = AuditLog::where('event', 'payment.late_after_failed')->orderBy('id')->get();
        $this->assertSame(['ref-other', 'ref-booked'], $logs->pluck('meta.reference')->all());
        $this->assertSame([false, true], $logs->pluck('meta.booked')->all());
    }

    public function test_only_pending_and_paid_tenders_block(): void
    {
        [, $bill] = $this->guestBill('midtrans');
        $this->assertFalse($bill->hasBlockingTenders());

        foreach (['failed' => false, 'pending' => true, 'paid' => true] as $status => $blocks) {
            $bill->tenders()->delete();
            $bill->tenders()->create(['method' => TransactionTender::METHOD_QRIS, 'amount' => self::TOTAL, 'payment_status' => $status]);
            $this->assertSame($blocks, $bill->hasBlockingTenders(), $status);
        }
    }

    public function test_paid_after_close_migration_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_12_06_200001_add_paid_after_close_at_to_transactions_table.php');
        $this->assertTrue(Schema::hasColumn('transactions', 'paid_after_close_at'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('transactions', 'paid_after_close_at'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('transactions', 'paid_after_close_at'));
    }

    public function test_the_closed_recap_rule(): void
    {
        foreach (['cash', 'pay_later', 'split', 'bank_transfer'] as $method) {
            $this->assertTrue(CashierShiftService::countedInClosedRecap($method), $method);
        }
        foreach ([Transaction::METHOD_OPEN_BILL, 'qris', 'midtrans', 'xendit'] as $method) {
            $this->assertFalse(CashierShiftService::countedInClosedRecap($method), $method);
        }
    }
}
