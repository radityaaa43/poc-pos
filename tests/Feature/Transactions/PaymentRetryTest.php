<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\CashierShiftService;
use App\Services\TransactionPaymentRetryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class PaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private User $cashier;

    private Warehouse $warehouse;

    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);

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

    public function test_failed_qris_sale_can_be_paid_in_cash(): void
    {
        $sale = $this->makeSale('qris');
        // Stock was taken at sale and stays taken: a retry never touches it (product_warehouse.stock, products.stock, mutations).
        $product = $this->product();
        $this->warehouse->products()->updateExistingPivot($product->id, ['stock' => 7]);
        $product->update(['stock' => 7]);
        $mutations = StockMutation::count();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 25000])
            ->assertRedirect(route('transactions.print', $sale->invoice))
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertSame(25000, $sale->cash);
        $this->assertSame(5000, $sale->change);
        $this->assertSame(1, $sale->payment_attempt);
        $this->assertNull($sale->payment_url);
        $this->assertNull($sale->qr_string);
        $this->assertNull($sale->payment_reference);
        $this->assertSame(1, AuditLog::where('event', 'transaction.payment_retried')->count());
        $this->assertSame(7, (int) $this->warehouse->products()->whereKey($product->id)->first()->pivot->stock);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame($mutations, StockMutation::count());

        // Paid once: a second retry is refused and changes nothing.
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 25000])
            ->assertSessionHasErrors('payment');
        $this->assertSame(1, $sale->fresh()->payment_attempt);
    }

    public function test_dine_open_bill_is_not_retried_here(): void
    {
        $sale = $this->makeSale('qris');
        $table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        DineOrder::create([
            'dine_table_id' => $table->id, 'status' => DineOrder::STATUS_ACCEPTED, 'subtotal' => 20000, 'item_count' => 1,
            'transaction_id' => $sale->id,
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 25000])
            ->assertSessionHasErrors(['payment' => 'Bayar lewat daftar Belum Lunas di POS']);

        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame(0, $sale->fresh()->payment_attempt);
        $this->actingAs($this->cashier)
            ->get(route('transactions.print', $sale->invoice))
            ->assertInertia(fn (Assert $page) => $page->where('retryable', false));
    }

    public function test_an_expired_sale_can_be_retried_and_old_tenders_are_replaced(): void
    {
        $sale = $this->makeSale('qris', ['payment_status' => 'expired']);
        $sale->tenders()->create([
            'method' => 'qris', 'amount' => 20000, 'change' => 0, 'payment_status' => 'failed',
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $sale->fresh()->payment_status);
        $this->assertSame(0, TransactionTender::where('transaction_id', $sale->id)->count());
    }

    public function test_a_sale_with_a_paid_tender_part_cannot_be_retried(): void
    {
        $sale = $this->makeSale('split');
        $sale->tenders()->createMany([
            ['method' => 'cash', 'amount' => 10000, 'cash_received' => 10000, 'change' => 0, 'payment_status' => 'paid'],
            ['method' => 'qris', 'amount' => 10000, 'change' => 0, 'payment_status' => 'failed'],
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasErrors('payment');

        $this->assertSame(2, $sale->tenders()->count());
        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_retry_validates_the_chosen_method(): void
    {
        $sale = $this->makeSale('qris');
        $bank = $this->bankAccount();

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bitcoin'])
            ->assertSessionHasErrors('payment_method');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash'])
            ->assertSessionHasErrors('cash_amount');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 19999])
            ->assertSessionHasErrors('cash_amount');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer'])
            ->assertSessionHasErrors('bank_account_id');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $bank->id + 99])
            ->assertSessionHasErrors('bank_account_id');

        $this->assertSame(0, $sale->fresh()->payment_attempt);
    }

    public function test_retry_same_gateway_uses_a_new_order_id(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'ignored-by-fake',
                'redirect_url' => 'https://pay.midtrans.test/new',
                'token' => 'snap-token',
                'qr_string' => 'NEW-QR',
            ], 200),
        ]);
        $sale = $this->makeSale('qris');

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertRedirect(route('transactions.print', $sale->invoice))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => ($request['transaction_details']['order_id'] ?? null) === $sale->invoice.'-r1'
            && $request['transaction_details']['gross_amount'] === 20000);

        $sale->refresh();
        $this->assertSame('pending', $sale->payment_status);
        $this->assertSame('qris', $sale->payment_method);
        $this->assertSame(1, $sale->payment_attempt);
        $this->assertSame('NEW-QR', $sale->qr_string);
        $this->assertSame('https://pay.midtrans.test/new', $sale->payment_url);

        // The callback of the new attempt settles the sale.
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice.'-r1', 'settlement'))->assertOk();
        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_retry_with_xendit_charges_under_the_attempt_order_id(): void
    {
        Http::fake(['https://api.xendit.co/*' => Http::response([
            'id' => 'xnd-1', 'invoice_url' => 'https://pay.xendit.test/new',
        ], 200)]);
        $sale = $this->makeSale('xendit');

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'xendit'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => ($request['external_id'] ?? null) === $sale->invoice.'-r1');
        $this->assertSame('pending', $sale->fresh()->payment_status);

        $this->withHeader('X-CALLBACK-TOKEN', 'callback-token')
            ->postJson(route('webhooks.xendit'), ['external_id' => $sale->invoice.'-r1', 'status' => 'PAID', 'id' => 'xnd-1'])
            ->assertOk();
        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_a_gateway_error_keeps_the_sale_failed_and_shows_the_error(): void
    {
        Http::fake(['https://app.sandbox.midtrans.com/*' => Http::response(['status_message' => 'down'], 500)]);
        $sale = $this->makeSale('qris');

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertRedirect(route('transactions.print', $sale->invoice))
            ->assertSessionHas('error');

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertSame(1, $sale->payment_attempt);
    }

    public function test_a_gateway_timeout_fails_the_sale_so_it_can_be_retried_again(): void
    {
        $reachable = false;
        Http::fake(['https://app.sandbox.midtrans.com/*' => function () use (&$reachable) {
            return $reachable
                ? Http::response(['redirect_url' => 'https://pay.test', 'qr_string' => 'QR'], 200)
                : throw new ConnectionException('timed out');
        }]);
        $sale = $this->makeSale('qris');

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertRedirect(route('transactions.print', $sale->invoice))
            ->assertSessionHas('error', 'Gagal menghubungi gateway pembayaran. Coba lagi.');

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertSame(1, $sale->payment_attempt);

        $reachable = true;
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertSessionHasNoErrors();
        $this->assertSame('pending', $sale->fresh()->payment_status);
        $this->assertSame(2, $sale->fresh()->payment_attempt);
    }

    public function test_late_failed_callback_of_superseded_attempt_is_ignored(): void
    {
        Http::fake(['https://app.sandbox.midtrans.com/*' => Http::response(['redirect_url' => 'https://pay.test', 'qr_string' => 'QR'], 200)]);
        $sale = $this->makeSale('qris');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'qris'])
            ->assertSessionHasNoErrors();

        // The first charge (order id = invoice) expires after the retry started.
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice, 'expire'))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $sale->refresh();
        $this->assertSame('pending', $sale->payment_status);
        $this->assertNull($sale->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_superseded')->count());

        $this->withHeader('X-CALLBACK-TOKEN', 'callback-token')
            ->postJson(route('webhooks.xendit'), ['external_id' => $sale->invoice, 'status' => 'EXPIRED', 'id' => 'old'])
            ->assertOk();
        $this->assertSame('pending', $sale->fresh()->payment_status);
    }

    public function test_a_failed_audit_write_leaves_the_late_payment_unmarked_so_the_redelivery_audits_it(): void
    {
        $sale = $this->makeSale('midtrans');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasNoErrors();

        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new RuntimeException('audit down')));
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice, 'settlement'))->assertStatus(500);
        $this->assertNull($sale->fresh()->late_superseded_payment_at);

        $this->app->forgetInstance(AuditLogService::class);
        $this->app->offsetUnset(AuditLogService::class);
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice, 'settlement'))->assertOk();
        $this->assertNotNull($sale->fresh()->late_superseded_payment_at);
        $this->assertSame(1, AuditLog::where('event', 'payment.late_superseded')->count());
    }

    public function test_late_paid_callback_of_superseded_attempt_is_audited_not_recorded(): void
    {
        $sale = $this->makeSale('midtrans');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasNoErrors();

        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice, 'settlement'))
            ->assertOk()
            ->assertJson(['status' => 'success']);
        // A duplicate delivery changes nothing more.
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($sale->invoice, 'settlement'))->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertNull($sale->payment_reference);
        $this->assertNotNull($sale->late_superseded_payment_at);

        $logs = AuditLog::where('event', 'payment.late_superseded')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('midtrans', $logs[0]->meta['gateway']);
        $this->assertSame($sale->invoice, $logs[0]->meta['order_id']);
        $this->assertEquals(20000, $logs[0]->meta['amount']);
    }

    public function test_late_paid_xendit_callback_of_superseded_attempt_is_audited_not_recorded(): void
    {
        $sale = $this->makeSale('xendit');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasNoErrors();

        $this->withHeader('X-CALLBACK-TOKEN', 'callback-token')
            ->postJson(route('webhooks.xendit'), ['external_id' => $sale->invoice, 'status' => 'PAID', 'id' => 'old', 'paid_amount' => 20000])
            ->assertOk();

        $sale->refresh();
        $this->assertNull($sale->payment_reference);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertSame('xendit', AuditLog::where('event', 'payment.late_superseded')->firstOrFail()->meta['gateway']);
    }

    public function test_retry_locks_the_shift_before_the_sale_and_reads_the_sale_after_both_locks(): void
    {
        $sale = $this->makeSale('qris');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TransactionPaymentRetryService::class)->retry($sale, $this->cashier, ['payment_method' => 'cash', 'cash_amount' => 20000]);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // The webhook and the cashier's payment lock shift, then bill; a retry in the opposite order could deadlock with them.
        // SQLite quotes names with ", MySQL (the CI backend job) with `: match both.
        $first = fn (string $pattern) => collect($queries)->search(fn (string $sql) => (bool) preg_match($pattern, $sql));
        $shift = $first('/from [`"]cashier_shifts[`"]/');
        $locked = $first('/from [`"]transactions[`"] where [`"]transactions[`"]\.[`"]id[`"] = \?/');

        $this->assertIsInt($shift);
        $this->assertIsInt($locked);
        $this->assertLessThan($locked, $shift, 'Lock order must be shift, then sale.');
        // SQLite renders no lock clause: only the MySQL job can show them.
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', strtolower($queries[$shift]));
            $this->assertStringContainsString('for update', strtolower($queries[$locked]));
        }
    }

    public function test_retry_judges_the_sale_by_what_it_is_after_the_locks_not_by_the_caller_copy(): void
    {
        $stale = $this->makeSale('qris'); // the caller's copy says failed
        DB::table('transactions')->where('id', $stale->id)->update(['payment_status' => 'paid']); // paid meanwhile

        try {
            app(TransactionPaymentRetryService::class)->retry($stale, $this->cashier, ['payment_method' => 'cash', 'cash_amount' => 20000]);
            $this->fail('A paid sale must not be retried.');
        } catch (ValidationException $exception) {
            $this->assertSame('Pembayaran ini tidak bisa diulang', $exception->errors()['payment'][0]);
        }

        $this->assertSame(0, $stale->fresh()->payment_attempt);
    }

    public function test_auto_expired_restocked_sale_cannot_be_retried(): void
    {
        $product = $this->product();
        $sale = $this->makeSale('midtrans', ['payment_status' => 'pending', 'created_at' => now()->subHours(25)]);
        TransactionDetail::create([
            'transaction_id' => $sale->id, 'product_id' => $product->id, 'qty' => 2, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000,
        ]);

        $this->artisan('transactions:expire')->assertSuccessful();
        $this->assertSame('failed', $sale->fresh()->payment_status);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasErrors(['payment' => 'Penjualan ini sudah dibatalkan otomatis dan stoknya dikembalikan — buat penjualan baru.']);

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertSame(0, $sale->payment_attempt);

        $this->actingAs($this->cashier)
            ->get(route('transactions.print', $sale->invoice))
            ->assertInertia(fn (Assert $page) => $page->where('retryable', false));
    }

    public function test_retry_requires_permission_and_open_shift_in_the_outlet(): void
    {
        $sale = $this->makeSale('qris');
        $payload = ['payment_method' => 'cash', 'cash_amount' => 20000];

        // no transactions-access
        $this->actingAs(User::factory()->create())
            ->post(route('transactions.retry-payment', $sale), $payload)
            ->assertForbidden();

        // no open shift: the same redirect as for any POS action
        $this->shift->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), $payload)
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('error');

        // open shift in another outlet
        $admin = User::where('email', 'arya@gmail.com')->first();
        $outletA = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]);
        $outletB = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouseA = Warehouse::create(['outlet_id' => $outletA->id, 'code' => 'WH-A', 'name' => 'A', 'type' => 'branch', 'is_active' => true]);
        $warehouseB = Warehouse::create(['outlet_id' => $outletB->id, 'code' => 'WH-B', 'name' => 'B', 'type' => 'branch', 'is_active' => true]);
        app(CashierShiftService::class)->openShift($admin, $admin, 0, null, $warehouseA->id);
        $otherOutletSale = $this->makeSale('qris', ['warehouse_id' => $warehouseB->id]);

        $this->actingAs($admin)
            ->postJson(route('transactions.retry-payment', $otherOutletSale), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame('failed', $otherOutletSale->fresh()->payment_status);
    }

    public function test_retry_moves_the_sale_to_the_current_shift(): void
    {
        $oldShift = CashierShift::create([
            'user_id' => $this->cashier->id, 'opened_by' => $this->cashier->id, 'opened_at' => now()->subDay(),
            'closed_at' => now()->subHours(20), 'opening_cash' => 0, 'expected_cash' => 0, 'status' => CashierShift::STATUS_CLOSED,
        ]);
        $sale = $this->makeSale('qris', ['cashier_shift_id' => $oldShift->id]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->shift->id, $sale->fresh()->cashier_shift_id);
    }

    public function test_history_marks_and_filters_late_superseded_payments(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->first();
        $late = $this->makeSale('qris', ['payment_status' => 'paid', 'late_superseded_payment_at' => now()]);
        $this->makeSale('qris', ['payment_status' => 'paid']);

        $this->actingAs($admin)
            ->get(route('transactions.history'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 2)
                ->where('filters.late_payment', false)
                ->etc());

        $this->actingAs($admin)
            ->get(route('transactions.history', ['late_payment' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.invoice', $late->invoice)
                ->where('transactions.data.0.late_superseded_payment_at', fn ($value) => filled($value))
                ->where('filters.late_payment', true)
                ->etc());

    }

    public function test_paid_or_pending_sale_cannot_be_retried(): void
    {
        foreach (['paid', 'pending'] as $status) {
            $sale = $this->makeSale('qris', ['payment_status' => $status]);

            $this->actingAs($this->cashier)
                ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
                ->assertSessionHasErrors(['payment' => 'Pembayaran ini tidak bisa diulang']);

            $sale->refresh();
            $this->assertSame($status, $sale->payment_status);
            $this->assertSame(0, $sale->payment_attempt);

            $this->actingAs($this->cashier)
                ->get(route('transactions.print', $sale->invoice))
                ->assertInertia(fn (Assert $page) => $page->where('retryable', false));
        }
    }

    public function test_print_page_offers_retry_for_a_failed_sale(): void
    {
        $bank = $this->bankAccount();
        $sale = $this->makeSale('qris');

        $this->actingAs($this->cashier)
            ->get(route('transactions.print', $sale->invoice))
            ->assertInertia(fn (Assert $page) => $page
                ->where('retryable', true)
                ->where('transaction.payment_attempt', 0)
                ->where('transaction.late_superseded_payment_at', null)
                ->where('bankAccounts.0.id', $bank->id)
                ->has('paymentGateways'));
    }

    public function test_retry_by_bank_transfer_waits_for_confirmation(): void
    {
        $bank = $this->bankAccount();
        $sale = $this->makeSale('qris');

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $bank->id])
            ->assertRedirect(route('transactions.print', $sale->invoice))
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('pending', $sale->payment_status);
        $this->assertSame('bank_transfer', $sale->payment_method);
        $this->assertSame($bank->id, $sale->bank_account_id);
        $this->assertSame(1, $sale->payment_attempt);

        $this->actingAs(User::where('email', 'arya@gmail.com')->first())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $sale))
            ->assertSessionHas('success');

        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_payment_attempt_migration_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_30_200001_add_payment_attempt_to_transactions_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('transactions', 'payment_attempt'));
        $this->assertFalse(Schema::hasColumn('transactions', 'late_superseded_payment_at'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('transactions', 'payment_attempt'));
        $this->assertTrue(Schema::hasColumn('transactions', 'late_superseded_payment_at'));
    }

    public function test_retry_of_a_pay_later_bill_asks_what_it_still_owes_and_the_drawer_matches(): void
    {
        $sale = $this->returnedPayLaterSale(returnedQty: 1);

        $this->actingAs($this->cashier)->get(route('transactions.print', $sale->invoice))
            ->assertInertia(fn (Assert $page) => $page->where('transaction.retry_amount', 10000));
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 10000])
            ->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame(['paid', 10000, 0], [$sale->payment_status, $sale->cash, $sale->change]);
        $this->assertSame(10000, app(CashierShiftService::class)->calculateSummary($this->shift)['expected_cash']);
    }

    public function test_gateway_retry_of_a_pay_later_bill_charges_what_it_still_owes(): void
    {
        Http::fake(['https://app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://pay.test/new'], 200)]);
        $sale = $this->returnedPayLaterSale(returnedQty: 1);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'midtrans'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request['transaction_details']['gross_amount'] === 10000);
    }

    public function test_retry_of_a_fully_returned_pay_later_bill_is_refused(): void
    {
        $sale = $this->returnedPayLaterSale(returnedQty: 2);

        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'cash', 'cash_amount' => 20000])
            ->assertSessionHasErrors(['payment' => 'Penjualan ini sudah diretur penuh, tidak ada yang perlu dibayar.']);

        $this->assertSame(['failed', 0], [$sale->fresh()->payment_status, $sale->fresh()->payment_attempt]);
    }

    /** A failed pay-later bill of 2 x 10000, with $returnedQty of them returned before anything was paid. */
    private function returnedPayLaterSale(int $returnedQty): Transaction
    {
        $sale = $this->makeSale('midtrans', ['is_pay_later' => true]);
        $product = $this->product();
        $detail = $sale->details()->create(['product_id' => $product->id, 'qty' => 2, 'price' => 20000]);
        $return = SalesReturn::create([
            'code' => 'SR-RETRY-'.$sale->id, 'transaction_id' => $sale->id, 'cashier_id' => $this->cashier->id,
            'status' => 'completed', 'return_type' => 'refund_cash', 'refund_amount' => 0, 'credited_amount' => 0,
            'total_return_amount' => $returnedQty * 10000, 'completed_at' => now(),
        ]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id, 'product_id' => $product->id, 'qty_sold' => 2,
            'qty_returned_before' => 0, 'qty_return' => $returnedQty, 'unit_price' => 10000, 'subtotal' => $returnedQty * 10000,
            'return_reason' => 'Retur', 'restock_to_inventory' => false,
        ]);

        return $sale;
    }

    private function makeSale(string $method, array $overrides = []): Transaction
    {
        $sale = new Transaction(array_merge([
            'cashier_id' => $this->cashier->id,
            'cashier_shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-RETRY-'.++self::$seq,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 20000,
            'payment_method' => $method,
            'payment_status' => 'failed',
            'payment_reference' => 'old-ref',
            'payment_url' => 'https://old.test/pay',
            'qr_string' => 'old-qr',
        ], $overrides));
        if (isset($overrides['created_at'])) {
            $sale->created_at = $overrides['created_at'];
        }
        $sale->save();

        return $sale->refresh();
    }

    private function bankAccount(): BankAccount
    {
        return BankAccount::create([
            'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko', 'is_active' => true,
        ]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Retry Category']);
        $product = Product::create([
            'image' => 'test.png', 'barcode' => '1234567890', 'sku' => 'RETRY-1', 'title' => 'Produk Retry',
            'description' => 'test', 'category_id' => $category->id, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 0, 'tax_rate' => 0, 'tax_type' => 'exclusive',
        ]);
        $this->warehouse->products()->attach($product->id, ['stock' => 0]);

        return $product;
    }

    private function midtransPayload(string $orderId, string $status): array
    {
        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '20000.00',
            'transaction_status' => $status,
            'transaction_id' => 'midtrans-'.$status,
        ];
        $payload['signature_key'] = hash('sha512', $orderId.'200'.'20000.00'.'server-key');

        return $payload;
    }
}
