<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Category;
use App\Models\CreditApprovalRequest;
use App\Models\Customer;
use App\Models\DiscountApprovalLog;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CreditApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesCreditData;
use Tests\TestCase;

/** The manager exception for a refused credit sale: ask, hold, approve or deny (REQ-007 AC-06, BR-4..BR-7). */
class CreditApprovalTest extends TestCase
{
    use MakesCreditData, RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    private User $approver;

    private Product $product;

    private Customer $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Gudang 1', 'status' => 'active', 'outlet_id' => $this->outlet->id]);
        $this->product = Product::create([
            'title' => 'Produk Kasir',
            'barcode' => 'CA-001',
            'sku' => 'SKU-CA-001',
            'image' => '',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 200,
            'category_id' => Category::create(['name' => 'Kategori', 'image' => '', 'description' => ''])->id,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
            'needs_preparation' => true,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 200]);

        $this->cashier = $this->staff('transactions-access', 'cashier-shifts-access');
        $this->approver = $this->staff('customers-credit-approve');
        $this->openShift();
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);

        // Limit Rp 200.000: a Rp 210.000 sale is refused.
        $this->budi = $this->customer(200000);
    }

    private function staff(string ...$permissions): User
    {
        $user = $this->userWith(...$permissions);
        // The column's default: the web answers in the user's language.
        $user->forceFill(['locale' => 'id', 'email_verified_at' => now()])->save();

        return $user;
    }

    private function openShift(): void
    {
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
    }

    private function cart(int $total): Cart
    {
        return Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => $total / 10000,
            'price' => $total,
        ]);
    }

    private function checkout(array $extra = []): TestResponse
    {
        return $this->from(route('transactions.index'))
            ->actingAs($this->cashier)
            ->post(route('transactions.store'), $extra + [
                'pay_later' => '1',
                'discount' => 0,
                'customer_id' => $this->budi->id,
                'due_date' => today()->addDays(7)->toDateString(),
                'note' => 'Meja 4',
            ]);
    }

    /** A refused sale of `$total`, held for an approver. */
    private function requested(int $total = 210000, array $extra = []): CreditApprovalRequest
    {
        $this->cart($total);
        $this->checkout(['credit_exception' => true] + $extra)->assertSessionHasNoErrors();

        return CreditApprovalRequest::latest('id')->firstOrFail();
    }

    private function decide(string $action, CreditApprovalRequest $request, ?User $user = null, string $reason = 'Pelanggan lama'): TestResponse
    {
        return $this->from(route('discount-approvals.pending'))
            ->actingAs($user ?? $this->approver)
            ->post(route("credit-approvals.$action", $request), ['reason' => $reason]);
    }

    private function stock(): array
    {
        return [
            (int) $this->product->fresh()->stock,
            (int) $this->product->warehouses()->firstOrFail()->pivot->stock,
        ];
    }

    private function assertNothingBooked(): void
    {
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Receivable::count());
        $this->assertSame(0, KitchenItem::count());
        $this->assertSame(0, DB::table('stock_mutations')->where('reference_type', 'transaction')->count());
        $this->assertSame([200, 200], $this->stock());
    }

    private function assertStillPending(CreditApprovalRequest $request): void
    {
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(1, Cart::forHold($request->hold_id)->count());
        $this->assertNothingBooked();
    }

    public function test_exception_request_holds_the_cart_and_moves_nothing(): void
    {
        $cart = $this->cart(210000);

        $this->checkout(['credit_exception' => true])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('success', 'Menunggu persetujuan kredit');

        $request = CreditApprovalRequest::sole();
        $this->assertSame(
            ['pending', 210000, 'credit_limit_exceeded', 200000, $this->budi->id, $this->cashier->id, $this->warehouse->id],
            [$request->status, $request->amount, $request->reason_code, $request->remaining_limit, $request->customer_id, $request->cashier_id, $request->warehouse_id],
        );
        $this->assertSame(
            [$request->hold_id, 'Menunggu persetujuan kredit'],
            [$cart->fresh()->hold_id, $cart->fresh()->hold_label],
        );
        $this->assertNothingBooked();

        $audit = AuditLog::where('event', 'credit_approval.requested')->sole();
        $this->assertSame([$this->cashier->id, $this->budi->id], [$audit->user_id, $audit->auditable_id]);

        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn ($page) => $page->where('heldCarts.0.credit_status', 'pending'));
    }

    public function test_an_overdue_customer_can_be_excepted_too(): void
    {
        $this->receivable($this->budi, 50000, 0, ['due_date' => today()->subDay()]);

        $request = $this->requested(10000);
        $this->assertSame(['credit_overdue', 150000], [$request->reason_code, $request->remaining_limit]);

        $this->decide('approve', $request)->assertSessionHasNoErrors();

        $this->assertSame(10000, (int) Receivable::whereNotNull('transaction_id')->sole()->total);
    }

    public function test_double_exception_request_makes_one_request(): void
    {
        $this->requested();

        $this->checkout(['credit_exception' => true])->assertStatus(422);

        $this->assertSame(1, CreditApprovalRequest::count());
    }

    public function test_exception_flag_is_ignored_when_the_sale_passes(): void
    {
        $this->cart(200000);

        $this->checkout(['credit_exception' => true])->assertSessionHasNoErrors();

        $this->assertSame(0, CreditApprovalRequest::count());
        $this->assertSame(1, Receivable::count());
    }

    public function test_a_pending_basket_cannot_be_resumed_deleted_or_edited(): void
    {
        $request = $this->requested();
        $row = Cart::forHold($request->hold_id)->sole();
        // The mobile cart edit needs a unit on the row.
        $unit = Unit::create(['code' => 'PCS-CA', 'name' => 'Pcs', 'symbol' => 'pcs']);
        DB::table('carts')->where('id', $row->id)->update(['unit_id' => $unit->id]);
        $message = ['hold' => 'Menunggu persetujuan kredit'];

        $this->actingAs($this->cashier);
        $this->post(route('transactions.resume', $request->hold_id))->assertSessionHasErrors($message);
        $this->delete(route('transactions.clearHold', $request->hold_id))->assertSessionHasErrors($message);
        $this->patch(route('transactions.updateCart', $row->id), ['qty' => 1])->assertSessionHasErrors($message);

        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson("/api/v1/pos/holds/{$request->hold_id}/resume")->assertStatus(422);
        $this->deleteJson("/api/v1/pos/holds/{$request->hold_id}")->assertStatus(422);
        $this->putJson("/api/v1/pos/cart/{$row->id}", ['qty' => 1])->assertStatus(422);
        $this->deleteJson("/api/v1/pos/cart/{$row->id}")->assertStatus(422);

        $this->assertSame(21.0, (float) $row->fresh()->qty);
        $this->assertStillPending($request);
    }

    public function test_approval_books_the_sale_once_and_leaves_the_limit(): void
    {
        $request = $this->requested();

        $this->decide('approve', $request)
            ->assertRedirect(route('discount-approvals.pending'))
            ->assertSessionHas('success', 'Disetujui');

        $sale = Transaction::sole();
        $this->assertSame(
            [$this->cashier->id, $this->warehouse->id, $this->budi->id, 210000, 'unpaid', 'Meja 4'],
            [$sale->cashier_id, $sale->warehouse_id, $sale->customer_id, (int) $sale->grand_total, $sale->payment_status, $sale->note],
        );
        $receivable = Receivable::sole();
        $this->assertSame([$sale->id, 210000], [$receivable->transaction_id, (int) $receivable->total]);
        $this->assertSame(today()->addDays(7)->toDateString(), $receivable->due_date->toDateString());
        $this->assertSame([179, 179], $this->stock());
        $this->assertSame(1, KitchenItem::where('transaction_id', $sale->id)->count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(200000, $this->budi->fresh()->credit_limit);

        $request->refresh();
        $this->assertSame(
            ['approved', $this->approver->id, 'Pelanggan lama', $sale->id],
            [$request->status, $request->decided_by, $request->decision_reason, $request->transaction_id],
        );
        $this->assertNotNull($request->decided_at);
    }

    public function test_approval_is_audited(): void
    {
        $request = $this->requested();

        $this->decide('approve', $request);

        $audit = AuditLog::where('event', 'credit_approval.approved')->sole();
        $this->assertSame([$this->approver->id, $this->budi->id], [$audit->user_id, $audit->auditable_id]);
        $this->assertSame(
            [210000, 'Pelanggan lama', Transaction::sole()->invoice, $this->approver->name],
            [$audit->after['amount'], $audit->after['reason'], $audit->after['invoice'], $audit->after['approver']],
        );
        $this->assertSame($this->cashier->id, $audit->meta['cashier_id']);
    }

    public function test_a_second_approver_makes_no_second_receivable(): void
    {
        $request = $this->requested();
        $other = $this->staff('customers-credit-approve');
        // Both loaded it while it was pending.
        $stale = CreditApprovalRequest::findOrFail($request->id);

        $this->decide('approve', $request);
        app(CreditApprovalService::class)->approve($stale, 'Setuju juga', $other);

        $this->assertSame([1, 1], [Transaction::count(), Receivable::count()]);
        $this->assertSame([179, 179], $this->stock());
        $this->assertSame($this->approver->id, $request->fresh()->decided_by);
        $this->assertSame(1, AuditLog::where('event', 'credit_approval.approved')->count());
    }

    public function test_a_second_approval_after_the_shift_closed_is_still_a_no_op(): void
    {
        $request = $this->requested();
        $this->decide('approve', $request);
        DB::table('cashier_shifts')->update(['status' => 'closed', 'closed_at' => now()]);

        $this->decide('approve', $request)->assertSessionHasNoErrors();

        $this->assertSame(1, Receivable::count());
    }

    /** The order every writer shares: shift, customer, then the request; the customer before any plain read. */
    public function test_approval_locks_the_shift_then_the_customer_then_the_request(): void
    {
        $request = $this->requested();
        $request->load('warehouse');
        $outside = DB::transactionLevel();
        $reads = [];
        DB::listen(function ($query) use (&$reads, $outside) {
            if (DB::transactionLevel() > $outside && preg_match('/^select .*? from [`"](\w+)[`"]/', $query->sql, $match)) {
                $reads[] = [$match[1], str_ends_with($query->sql, 'for update')];
            }
        });

        app(CreditApprovalService::class)->approve($request, 'Pelanggan lama', $this->approver);

        $this->assertSame(['cashier_shifts', 'customers', 'credit_approval_requests', 'carts'], array_column(array_slice($reads, 0, 4), 0));
        if (DB::getDriverName() === 'mysql') { // SQLite renders no lock clause
            $this->assertSame([true, true, true, false], array_column(array_slice($reads, 0, 4), 1));
        }
    }

    public function test_decided_request_cannot_be_flipped(): void
    {
        $approved = $this->requested();
        $this->decide('approve', $approved);
        $this->decide('deny', $approved)->assertSessionHasErrors(['reason' => 'Sudah disetujui']);

        $denied = $this->requested();
        $this->decide('deny', $denied);
        $this->decide('approve', $denied)->assertSessionHasErrors(['reason' => 'Sudah ditolak']);
        $this->decide('deny', $denied, reason: 'Lagi')->assertSessionHasNoErrors();

        $this->assertSame(['approved', 'denied'], [$approved->fresh()->status, $denied->fresh()->status]);
        $this->assertSame('Pelanggan lama', $denied->fresh()->decision_reason);
        $this->assertSame([1, 1], [Transaction::count(), AuditLog::where('event', 'credit_approval.denied')->count()]);
    }

    public function test_denial_keeps_the_hold_and_the_cashier_can_resume_it(): void
    {
        $request = $this->requested();

        $this->decide('deny', $request, reason: 'Tagih dulu')->assertSessionHas('success', 'Ditolak');

        $request->refresh();
        $this->assertSame(['denied', $this->approver->id, 'Tagih dulu'], [$request->status, $request->decided_by, $request->decision_reason]);
        $this->assertSame('Pengecualian kredit ditolak', Cart::forHold($request->hold_id)->sole()->hold_label);
        $this->assertNothingBooked();
        $this->assertSame($this->approver->id, AuditLog::where('event', 'credit_approval.denied')->sole()->user_id);

        $this->actingAs($this->cashier)->get(route('transactions.index'))->assertInertia(fn ($page) => $page
            ->where('heldCarts.0.credit_status', 'denied')
            ->where('heldCarts.0.credit_note', 'Tagih dulu'));

        $this->post(route('transactions.resume', $request->hold_id))->assertSessionHasNoErrors();
        $this->assertSame(1, Cart::active()->count());
    }

    public function test_reason_is_required_to_approve_and_deny(): void
    {
        $request = $this->requested();

        foreach (['approve', 'deny'] as $action) {
            foreach (['', '   ', 'ab', str_repeat('a', 501)] as $reason) {
                $this->decide($action, $request, reason: $reason)->assertSessionHasErrors('reason');
            }
        }

        $this->assertStillPending($request);
        $this->decide('approve', $request, reason: '')
            ->assertSessionHasErrors(['reason' => __('messages.credit.reason_required')]);
    }

    public function test_user_without_permission_cannot_approve_or_deny(): void
    {
        $request = $this->requested();

        foreach (['approve', 'deny'] as $action) {
            $this->decide($action, $request, $this->staff('discounts-approve'))->assertForbidden();
            $this->decide($action, $request, $this->cashier)->assertForbidden();
        }

        $this->assertStillPending($request);
    }

    public function test_approver_of_another_outlet_gets_404(): void
    {
        $request = $this->requested();
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $outsider = $this->staff('customers-credit-approve');
        $outsider->outlets()->attach($other->id, ['is_default' => true]);

        $this->decide('approve', $request, $outsider)->assertNotFound();
        $this->decide('deny', $request, $outsider)->assertNotFound();
        $this->actingAs($outsider)->get(route('discount-approvals.pending'))
            ->assertInertia(fn ($page) => $page->where('creditRequests', []));

        $this->assertStillPending($request);
    }

    public function test_queue_lists_by_permission(): void
    {
        $request = $this->requested();

        $this->actingAs($this->approver)->get(route('discount-approvals.pending'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('pendingTransactions', null)
            ->has('creditRequests', 1)
            ->where('creditRequests.0.id', $request->id)
            ->where('creditRequests.0.customer', 'Budi')
            ->where('creditRequests.0.cashier', $this->cashier->name)
            ->where('creditRequests.0.amount', 210000)
            ->where('creditRequests.0.refusal', 'Melebihi batas piutang — sisa Rp 200.000'));

        $this->actingAs($this->staff('discounts-approve'))->get(route('discount-approvals.pending'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('pendingTransactions', [])->where('creditRequests', null));

        $this->actingAs($this->cashier)->get(route('discount-approvals.pending'))->assertForbidden();

        // The credit permission opens the queue, not the discount decisions.
        $this->decide('approve', $request);
        $this->actingAs($this->approver)->post(route('discount-approvals.approve', Transaction::sole()))->assertForbidden();
    }

    public function test_approval_refuses_a_changed_total(): void
    {
        $request = $this->requested();
        $this->product->update(['sell_price' => 12000]);

        $this->decide('approve', $request)->assertSessionHasErrors([
            'reason' => 'Total berubah sejak diajukan. Tolak, lalu minta kasir mengajukan ulang.',
        ]);

        $this->assertStillPending($request);
    }

    public function test_approval_fails_cleanly_when_stock_is_short(): void
    {
        $request = $this->requested();
        $this->product->warehouses()->updateExistingPivot($this->warehouse->id, ['stock' => 5]);

        $this->decide('approve', $request)->assertSessionHasErrors('stock');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(1, Cart::forHold($request->hold_id)->count());
        $this->assertSame([0, 0], [Transaction::count(), Receivable::count()]);
    }

    public function test_approval_needs_the_shift_the_sale_was_asked_in(): void
    {
        $request = $this->requested();
        DB::table('cashier_shifts')->update(['status' => 'closed', 'closed_at' => now()]);
        $message = ['reason' => 'Shift kasir sudah ditutup.'];

        $this->decide('approve', $request)->assertSessionHasErrors($message);
        $this->openShift();
        $this->decide('approve', $request)->assertSessionHasErrors($message);

        $this->assertStillPending($request);
        $this->decide('deny', $request)->assertSessionHasNoErrors();
    }

    public function test_approval_refuses_once_pay_later_is_switched_off(): void
    {
        $request = $this->requested();
        Setting::setForOutlet('pay_later_enabled', '0', $this->outlet);

        $this->decide('approve', $request)->assertSessionHasErrors(['reason' => __('messages.credit.pay_later_off')]);

        $this->assertStillPending($request);
    }

    public function test_approval_refuses_when_the_flag_was_revoked(): void
    {
        $request = $this->requested();
        $this->budi->forceFill(['may_buy_on_credit' => false])->save();

        $this->decide('approve', $request)->assertSessionHasErrors(['reason' => 'Pelanggan tidak lagi boleh piutang.']);

        $this->assertStillPending($request);
    }

    public function test_approval_does_not_touch_the_cashiers_new_cart(): void
    {
        $request = $this->requested();
        $next = $this->cart(30000);

        $this->decide('approve', $request)->assertSessionHasNoErrors();

        $this->assertSame(210000, (int) Transaction::sole()->grand_total);
        $this->assertSame([$next->id], Cart::active()->pluck('id')->all());
    }

    /** An exception waives the credit rules only: a discount over the threshold still waits for its own approver. */
    public function test_approval_keeps_the_discount_approval(): void
    {
        Setting::set('discount_approval_threshold', '5000');
        $this->cart(220000);
        $this->checkout(['credit_exception' => true, 'discount' => 10000]);

        $this->decide('approve', CreditApprovalRequest::sole())->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame(['pending', 'pending_approval'], [$sale->discount_approval_status, $sale->payment_status]);
        $this->assertSame([$sale->id, $this->cashier->id], [DiscountApprovalLog::sole()->transaction_id, DiscountApprovalLog::sole()->cashier_id]);
        $this->assertSame(210000, (int) Receivable::sole()->total);
    }

    public function test_migration_runs_down_then_up(): void
    {
        $migration = require database_path('migrations/2026_12_26_100001_create_credit_approval_requests_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('credit_approval_requests'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('credit_approval_requests'));
    }
}
