<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\OpenBillListService;
use App\Services\ShiftCloseBlockerService;
use App\Services\TransactionSettlementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * #530: collect the rest of a pay-now split sale after a failed gateway part, a denied discount, or a pending transfer.
 * BR-4: any open shift of the outlet takes it and counts it; BR-5: a pending transfer is confirmed first, never replaced;
 * BR-2: a late callback of the replaced part is booked or flagged by the existing decide() rule, never booked twice.
 */
class SplitRestCollectionTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private User $cashier;

    private User $other;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->outlet = Outlet::create(['code' => 'OUT-SPLIT', 'name' => 'Outlet Split', 'is_active' => true]);
        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT-SPLIT', 'name' => 'Gudang Split', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();
        $this->cashier->outlets()->attach($this->outlet->id, ['is_default' => true]);

        $this->other = User::factory()->create();
        $this->other->markEmailAsVerified();
        $this->other->givePermissionTo('transactions-access');
        $this->other->outlets()->attach($this->outlet->id, ['is_default' => true]);

        $this->shift = $this->openShift($this->cashier);

        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'server-key',
            'midtrans_client_key' => 'client-key',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xendit-secret',
            'xendit_public_key' => 'xendit-public',
            'xendit_callback_token' => 'callback-token',
        ]);
    }

    public function test_failed_gateway_part_is_collected_in_cash(): void
    {
        $sale = $this->failedSplit();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertRedirect(route('transactions.print', $sale->invoice))
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('split', $sale->payment_method);
        $this->assertSame(1, $sale->payment_attempt);
        // The paid part is untouched; the rest is a second paid cash tender of 15000, change from 20000.
        $this->assertSame(25000, $sale->cash);
        $this->assertSame(5000, $sale->change);
        $this->assertSame([5000, 15000], $sale->tenders()->orderBy('id')->pluck('amount')->map(fn ($amount) => (int) $amount)->all());
        $this->assertSame(['cash', 'cash'], $sale->tenders()->orderBy('id')->pluck('method')->all());
        $this->assertSame(0, $sale->tenders()->where('method', 'qris')->count());

        $audit = AuditLog::where('event', 'transaction.split_rest_collected')->sole();
        $this->assertSame(15000, $audit->meta['owed']);
        $this->assertSame('cash', $audit->meta['method']);
        $replaced = collect($audit->meta['replaced'])->keyBy('method');
        $this->assertSame($sale->invoice.'-qris', $replaced['qris']['order_id']);
        $this->assertSame('failed', $replaced['qris']['status']);

        // BR-3: the paid sale no longer holds the close.
        $this->assertCount(0, app(ShiftCloseBlockerService::class)->blockersFor($this->shift)['splits']);
    }

    public function test_rest_by_transfer_waits_and_the_hold_stays(): void
    {
        $bank = $this->bankAccount();
        $sale = $this->failedSplit();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $bank->id])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('pending', $sale->payment_status);
        $this->assertSame(1, $sale->payment_attempt);
        $this->assertSame(1, $sale->tenders()->where('method', 'bank_transfer')->where('payment_status', 'pending')->count());
        // BR-3: a pending rest keeps the hold, with the owed amount.
        $this->assertSame($sale->id, app(ShiftCloseBlockerService::class)->blockersFor($this->shift)['splits']->sole()->id);
        $this->assertSame(15000, app(ShiftCloseBlockerService::class)->blockersFor($this->shift)['splits']->sole()->unpaidAmount());

        $this->actingAs(User::where('email', 'arya@gmail.com')->firstOrFail())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $sale))
            ->assertSessionHas('success');

        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_rest_by_gateway_charges_the_owed_amount_under_a_new_order_id(): void
    {
        Http::fake(['https://app.sandbox.midtrans.com/*' => Http::response([
            'token' => 'snap', 'redirect_url' => 'https://pay.midtrans.test/rest', 'qr_string' => 'REST-QR',
        ], 200)]);
        $sale = $this->failedSplit();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => ($request['transaction_details']['order_id'] ?? null) === $sale->invoice.'-qris-r1'
            && $request['transaction_details']['gross_amount'] === 15000);

        $sale->refresh();
        $this->assertSame('pending', $sale->payment_status);
        $this->assertSame(1, $sale->payment_attempt);

        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice.'-qris-r1', 15000))->assertOk();
        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame(20000, (int) $sale->tenders()->where('payment_status', 'paid')->sum('amount'));

        // A redelivery changes nothing.
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice.'-qris-r1', 15000))->assertOk();
        $this->assertSame(20000, (int) $sale->tenders()->where('payment_status', 'paid')->sum('amount'));
    }

    public function test_gateway_refusal_restores_the_sale_and_keeps_the_paid_part(): void
    {
        Http::fake(['https://app.sandbox.midtrans.com/*' => Http::response(['status_message' => 'down'], 500)]);
        $sale = $this->failedSplit();

        // charge()/restore() reports a refused gateway through the 'tenders' validation bag, which the panel shows.
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertSessionHasErrors('tenders');

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertSame('split', $sale->payment_method);
        $this->assertSame(1, $sale->payment_attempt);
        $this->assertSame(1, $sale->tenders()->count());
        $this->assertSame(5000, (int) $sale->tenders()->sole()->amount);
        $this->assertSame(1, AuditLog::where('event', 'transaction.gateway_charge_failed')->count());
    }

    public function test_denied_discount_split_collects_the_larger_rest(): void
    {
        // The discount was re-added to the total; the old gateway part is stale and replaced.
        $sale = $this->failedSplit(status: 'unpaid', total: 24000);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 19000])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(24000, (int) $sale->tenders()->where('payment_status', 'paid')->sum('amount'));
        $this->assertSame(19000, AuditLog::where('event', 'transaction.split_rest_collected')->sole()->meta['owed']);
    }

    public function test_rest_is_taken_in_another_open_shift_and_counts_there(): void
    {
        $sale = $this->failedSplit();
        $otherShift = $this->openShift($this->other);

        $this->actingAs($this->other)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame($this->shift->id, $sale->cashier_shift_id); // the sale never moves
        $this->assertSame($this->cashier->id, $sale->cashier_id);
        $this->assertSame($otherShift->id, (int) $sale->tenders()->where('amount', 15000)->sole()->cashier_shift_id);

        $summary = fn (CashierShift $shift) => app(CashierShiftService::class)->calculateSummary($shift);
        $this->assertSame([5000, 0, 1], [
            $summary($this->shift)['cash_sales_total'], $summary($this->shift)['non_cash_sales_total'], $summary($this->shift)['transactions_count'],
        ]);
        $this->assertSame([15000, 0, 0], [
            $summary($otherShift)['cash_sales_total'], $summary($otherShift)['non_cash_sales_total'], $summary($otherShift)['transactions_count'],
        ]);
    }

    public function test_a_non_cash_rest_counts_in_the_shift_that_took_it(): void
    {
        $bank = $this->bankAccount();
        $sale = $this->failedSplit();
        $otherShift = $this->openShift($this->other);

        $this->actingAs($this->other)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $bank->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($otherShift->id, (int) $sale->fresh()->tenders()->where('method', 'bank_transfer')->sole()->cashier_shift_id);

        $this->actingAs(User::where('email', 'arya@gmail.com')->firstOrFail())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $sale))
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame($this->shift->id, $sale->cashier_shift_id); // the sale and its count stay with its shift
        $this->assertSame($this->cashier->id, $sale->cashier_id);

        $summary = fn (CashierShift $shift) => app(CashierShiftService::class)->calculateSummary($shift);
        $this->assertSame([5000, 0, 1], [
            $summary($this->shift)['cash_sales_total'], $summary($this->shift)['non_cash_sales_total'], $summary($this->shift)['transactions_count'],
        ]);
        $this->assertSame([0, 15000, 0], [
            $summary($otherShift)['cash_sales_total'], $summary($otherShift)['non_cash_sales_total'], $summary($otherShift)['transactions_count'],
        ]);
    }

    public function test_rest_in_another_shift_on_a_force_closed_sale_is_marked_paid_after_close(): void
    {
        $sale = $this->failedSplit();
        $closed = app(CashierShiftService::class)->closeShift($this->shift, $this->cashier, 100000, 'Tutup paksa', forceClose: true);
        $recap = $closed->only(['expected_cash', 'cash_sales_total', 'non_cash_sales_total', 'transactions_count']);
        $otherShift = $this->openShift($this->other);

        $this->actingAs($this->other)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNotNull($sale->paid_after_close_at);
        // A's stored snapshot is unchanged; B counts the rest.
        $this->assertSame($recap, CashierShift::findOrFail($closed->id)->only(array_keys($recap)));
        $this->assertSame(15000, app(CashierShiftService::class)->calculateSummary($otherShift)['cash_sales_total']);
        $this->assertSame($closed->id, AuditLog::where('event', 'payment.paid_after_close')->sole()->meta['shift_id']);
    }

    public function test_pending_transfer_part_is_refused_until_confirmed(): void
    {
        $sale = $this->pendingTransferSplit();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasErrors(['payment' => 'Konfirmasi transfer dulu (Jumlah diterima), lalu tagih sisanya.']);

        // The pending transfer is unchanged: not replaced, not paid.
        $this->assertSame('pending', $sale->fresh()->payment_status);
        $this->assertSame(0, $sale->fresh()->payment_attempt);
        $this->assertSame(1, $sale->tenders()->where('method', 'bank_transfer')->where('payment_status', 'pending')->count());
        $this->assertSame(2, $sale->tenders()->count());

        // The same refusal over JSON is a 422.
        $this->actingAs($this->cashier)
            ->postJson(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertStatus(422)
            ->assertJsonPath('errors.payment.0', 'Konfirmasi transfer dulu (Jumlah diterima), lalu tagih sisanya.');
    }

    public function test_collection_locks_the_shift_then_the_sale_then_the_tenders(): void
    {
        $sale = $this->failedSplit();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TransactionSettlementService::class)->collectRest($sale, $this->cashier, ['payment_method' => 'cash', 'cash_amount' => 15000]);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // Anchored at the start: the sale query's own subqueries (returns, restock) are not the lock.
        $first = fn (string $table) => collect($queries)->search(fn (string $sql) => (bool) preg_match('/^select \* from [`"]'.$table.'[`"]/', $sql));
        $shift = $first('cashier_shifts');
        $locked = $first('transactions');
        $tenders = $first('transaction_tenders');

        $this->assertIsInt($shift);
        $this->assertIsInt($locked);
        $this->assertIsInt($tenders);
        $this->assertLessThan($locked, $shift, 'Lock order must be shift, then sale.');
        $this->assertLessThan($tenders, $locked, 'Lock order must be sale, then tenders.');

        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', strtolower($queries[$shift]));
            $this->assertStringContainsString('for update', strtolower($queries[$locked]));
            // The sale's own shift is only read: the later CashierShift find carries no lock.
            $own = collect($queries)->search(fn (string $sql, int $index) => $index > $locked
                && preg_match('/^select \* from `cashier_shifts`/', $sql));
            $this->assertIsInt($own);
            $this->assertStringNotContainsString('for update', strtolower($queries[$own]));
        }
    }

    public function test_cash_below_the_owed_rest_is_refused(): void
    {
        $sale = $this->failedSplit();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 14999])
            ->assertSessionHasErrors('cash_amount');

        $this->assertSame(0, $sale->fresh()->payment_attempt);
        $this->assertSame('failed', $sale->fresh()->payment_status);
    }

    public function test_awaiting_discount_approval_is_not_collectable(): void
    {
        $sale = $this->failedSplit(status: 'pending_approval');

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasErrors(['payment' => 'Diskon penjualan ini masih menunggu persetujuan.']);

        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_a_live_pending_gateway_part_is_refused(): void
    {
        $sale = $this->failedSplit(status: 'pending');
        $sale->tenders()->where('method', 'qris')->update(['payment_reference' => 'live-ref']);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasErrors('payment');

        $this->assertSame('pending', $sale->fresh()->payment_status);
        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_an_abandoned_claim_is_replaced(): void
    {
        $sale = $this->failedSplit(status: 'pending');
        // Claimed, but the charge never stored a reference and is stale: the cashier may pay it again.
        $sale->tenders()->where('method', 'qris')->update([
            'payment_reference' => null,
            'created_at' => now()->subSeconds(TransactionTender::CLAIM_STALE_SECONDS + 10),
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(0, $sale->tenders()->where('method', 'qris')->count()); // the abandoned claim is gone
        $this->assertSame(20000, (int) $sale->tenders()->where('payment_status', 'paid')->sum('amount'));
        $this->assertSame(2, $sale->tenders()->count());
    }

    public function test_split_with_a_completed_return_is_not_collectable(): void
    {
        $sale = $this->failedSplit();
        SalesReturn::create([
            'code' => 'SR-REST-'.++self::$seq, 'transaction_id' => $sale->id,
            'status' => 'completed', 'total_return_amount' => 0,
        ]);

        // A completed return leaves the partly-paid-split predicate: the sale cannot take a rest.
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasErrors('payment');

        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_a_restocked_split_is_not_collectable(): void
    {
        $sale = $this->failedSplit();
        StockMutation::create([
            'product_id' => $this->product()->id, 'warehouse_id' => $this->warehouse->id,
            'reference_type' => 'transaction_expire', 'reference_id' => $sale->id, 'mutation_type' => 'in',
            'qty' => 2, 'stock_before' => 0, 'stock_after' => 2,
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_late_success_of_the_owed_part_is_booked(): void
    {
        $sale = $this->failedSplit();
        $this->assertSame('failed', $sale->payment_status);

        // The part is still owed and the amount is what the sale owes: book it, never flag it (#530 BR-7/A4).
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice.'-qris', 15000))->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->late_superseded_payment_at);
        $this->assertSame(20000, (int) $sale->tenders()->where('payment_status', 'paid')->sum('amount'));
        $this->assertSame(2, $sale->tenders()->where('payment_status', 'paid')->count()); // the cash part and the booked qris part

        $log = AuditLog::where('event', 'payment.late_after_failed')->sole();
        $this->assertTrue($log->meta['booked']);
        $this->assertSame($sale->invoice.'-qris', $log->meta['order_id']);
    }

    public function test_late_success_while_a_transfer_rest_is_pending_books_it_and_the_later_confirm_flags_the_excess(): void
    {
        $bank = $this->bankAccount();
        $sale = $this->failedSplit();
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $bank->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('pending', $sale->fresh()->payment_status);
        $this->assertSame(1, $sale->fresh()->tenders()->where('method', 'bank_transfer')->where('payment_status', 'pending')->count());

        // The pending transfer is left as it is, and the late part that is still owed is booked (#530 BR-7/A4).
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice.'-qris', 15000))->assertOk();

        $sale->refresh();
        $this->assertSame('pending', $sale->payment_status); // the transfer is still pending
        $this->assertSame(1, $sale->tenders()->where('method', 'bank_transfer')->where('payment_status', 'pending')->count());
        $this->assertSame(15000, (int) $sale->tenders()->where('method', 'qris')->where('payment_status', 'paid')->sum('amount'));
        $this->assertNull($sale->late_superseded_payment_at);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);

        // Confirming the untouched transfer pays the rest a second time: the excess is flagged for a manual refund.
        $this->actingAs(User::where('email', 'arya@gmail.com')->firstOrFail())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $sale))
            ->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(15000, $sale->overpaid_amount);
        $this->assertSame(15000, AuditLog::where('event', 'payment.overpaid_after_return')->sole()->meta['excess']);
    }

    public function test_late_success_is_flagged_when_the_rest_is_already_paid_or_the_amount_differs(): void
    {
        // The rest was collected another way: the late payment is a second one, flagged for a manual refund.
        $paid = $this->failedSplit();
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $paid), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', $paid->fresh()->payment_status);

        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($paid->invoice.'-qris', 15000))->assertOk();

        $paid->refresh();
        $this->assertSame('paid', $paid->payment_status);
        $this->assertSame(20000, (int) $paid->tenders()->where('payment_status', 'paid')->sum('amount')); // never booked twice
        $this->assertNotNull($paid->late_superseded_payment_at);
        $this->assertSame(1, AuditLog::where('event', 'payment.late_superseded')->count());

        // An amount that is not what the sale still owes is not booked either.
        $short = $this->failedSplit();
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($short->invoice.'-qris', 10000))->assertOk();

        $short->refresh();
        $this->assertSame('failed', $short->payment_status);
        $this->assertNotNull($short->late_superseded_payment_at);
        $this->assertSame(1, $short->tenders()->where('payment_status', 'paid')->count()); // only the original cash part
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    public function test_other_outlets_splits_are_not_listed_or_printable(): void
    {
        $otherOutlet = Outlet::create(['code' => 'OUT-OTHER', 'name' => 'Outlet Lain', 'is_active' => true]);
        $otherWarehouse = Warehouse::create(['code' => 'WH-OTHER', 'name' => 'Gudang Lain', 'status' => 'active', 'outlet_id' => $otherOutlet->id]);
        $foreign = Transaction::create([
            'cashier_id' => $this->other->id, 'cashier_shift_id' => $this->shift->id, 'warehouse_id' => $otherWarehouse->id,
            'invoice' => 'TRX-FOREIGN-'.++self::$seq, 'cash' => 5000, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 20000, 'payment_method' => 'split', 'payment_status' => 'failed',
        ]);
        $foreign->tenders()->create(['method' => 'cash', 'amount' => 5000, 'payment_status' => 'paid']);
        $foreign->tenders()->create(['method' => 'qris', 'amount' => 15000, 'payment_status' => 'failed']);
        $own = $this->failedSplit();

        $rows = app(OpenBillListService::class)->rowsFor($this->cashier);
        $this->assertSame([$own->id], $rows->pluck('id')->all());
        $this->assertSame('split', $rows->first()['kind']);
        $this->assertSame(15000, $rows->first()['amount']);

        $this->actingAs($this->cashier)->get(route('transactions.print', $foreign->invoice))->assertNotFound();
    }

    public function test_a_rest_from_another_outlet_is_refused(): void
    {
        $sale = $this->failedSplit();
        $otherOutlet = Outlet::create(['code' => 'OUT-AWAY', 'name' => 'Outlet Jauh', 'is_active' => true]);
        $otherWarehouse = Warehouse::create(['code' => 'WH-AWAY', 'name' => 'Gudang Jauh', 'status' => 'active', 'outlet_id' => $otherOutlet->id]);
        app(CashierShiftService::class)->openShift($this->other, $this->other, 100000, null, $otherWarehouse->id);

        $this->actingAs($this->other)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertSessionHasErrors(['payment' => 'Buka shift di outlet penjualan ini']);

        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_a_rest_without_an_open_shift_is_refused(): void
    {
        $sale = $this->failedSplit();

        $this->actingAs($this->other) // attached to the outlet, but no shift is open
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 15000])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('error', 'Shift kasir belum dibuka.');

        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_print_page_asks_only_the_rest_of_a_split(): void
    {
        $sale = $this->failedSplit();

        $this->actingAs($this->cashier)
            ->get(route('transactions.print', $sale->invoice))
            ->assertInertia(fn (Assert $page) => $page
                ->where('retryable', true)
                ->where('transaction.retry_amount', 15000)
                ->where('transaction.payment_method', 'split'));
    }

    private function failedSplit(string $status = 'failed', int $total = 20000): Transaction
    {
        $sale = $this->sale($status, $total);
        $sale->tenders()->createMany([
            ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000, 'change' => 0, 'payment_status' => 'paid'],
            ['method' => 'qris', 'amount' => $total - 5000, 'change' => 0, 'payment_status' => $status === 'pending' ? 'pending' : 'failed'],
        ]);

        return $sale;
    }

    private function pendingTransferSplit(): Transaction
    {
        $sale = $this->sale('pending', 20000);
        $sale->tenders()->createMany([
            ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000, 'change' => 0, 'payment_status' => 'paid'],
            ['method' => 'bank_transfer', 'amount' => 15000, 'bank_account_id' => $this->bankAccount()->id, 'payment_status' => 'pending'],
        ]);
        $sale->update(['bank_account_id' => $sale->tenders()->where('method', 'bank_transfer')->value('bank_account_id')]);

        return $sale->refresh();
    }

    private function sale(string $status, int $total): Transaction
    {
        return Transaction::create([
            'cashier_id' => $this->cashier->id,
            'cashier_shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-SPLIT-'.++self::$seq,
            'cash' => 5000,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => $total,
            'payment_method' => 'split',
            'payment_status' => $status,
        ]);
    }

    private function openShift(User $user): CashierShift
    {
        return app(CashierShiftService::class)->openShift($user, $user, 100000, null, $this->warehouse->id);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Split Category']);

        return Product::create([
            'image' => 'test.png', 'barcode' => 'SPLIT-'.++self::$seq, 'sku' => 'SPLIT-'.self::$seq, 'title' => 'Produk Split',
            'description' => 'test', 'category_id' => $category->id, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 0, 'tax_rate' => 0, 'tax_type' => 'exclusive',
        ]);
    }

    private function bankAccount(): BankAccount
    {
        return BankAccount::create([
            'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko', 'is_active' => true,
        ]);
    }

    private function midtransPayload(string $orderId, int $amount): array
    {
        $gross = $amount.'.00';

        return [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $gross,
            'transaction_status' => 'settlement',
            'transaction_id' => 'mt-'.$orderId,
            'signature_key' => hash('sha512', $orderId.'200'.$gross.'server-key'),
        ];
    }
}
