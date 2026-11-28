<?php

namespace Tests\Feature\CashierShifts;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\DiscountApprovalLog;
use App\Models\Outlet;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/** #542 BR-1..BR-5: a sale whose discount still waits for approval holds the shift close, and a force close leaves it pending. */
class ShiftCloseDiscountHoldTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    private CashierShift $shift; // the cashier's own open shift

    private User $approver; // a manager holding discounts-approve and cashier-shifts-force-close

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDineBill();
        foreach (['cashier-shifts-access', 'cashier-shifts-close', 'cashier-shifts-force-close', 'discounts-approve'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->cashier->givePermissionTo(['cashier-shifts-access', 'cashier-shifts-close']);
        $this->shift = $this->openShiftFor($this->cashier);
        $this->approver = User::factory()->create()
            ->givePermissionTo(['cashier-shifts-access', 'cashier-shifts-close', 'cashier-shifts-force-close', 'discounts-approve']);
    }

    public function test_pending_discount_holds_the_close_and_is_listed(): void
    {
        $sale = $this->pendingDiscountSale($this->shift, ['note' => 'Meja 3']);
        $stock = $this->stock();

        $this->close($this->shift)->assertSessionHasErrors('shift');

        $this->assertTrue($this->shift->fresh()->isOpen());
        $this->assertSame($stock, $this->stock()); // nothing is cancelled or restocked by a refused close
        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 1)
            ->where('closeBlockers.0', [
                'type' => 'discount', 'id' => $sale->id, 'order' => $sale->invoice, 'label' => 'Meja 3', 'amount' => 5000,
            ]));
    }

    public function test_close_proceeds_once_the_discount_is_approved(): void
    {
        $sale = $this->pendingDiscountSale($this->shift);

        $this->decide($sale, 'approve');

        $this->assertSame(['approved', 'paid'], [$sale->fresh()->discount_approval_status, $sale->fresh()->payment_status]);
        $this->close($this->shift, null, ['actual_cash' => 115000])->assertSessionHasNoErrors();

        $closed = $this->shift->fresh();
        $this->assertSame(CashierShift::STATUS_CLOSED, $closed->status);
        // The cash taken at checkout counts once approved: opening 100.000 + 15.000.
        $this->assertSame(15000, $closed->cash_sales_total);
        $this->assertSame(115000, $closed->expected_cash);
        $this->assertSame(0, $closed->cash_difference);
    }

    public function test_close_proceeds_once_the_discount_is_denied(): void
    {
        $sale = $this->pendingDiscountSale($this->shift);

        $this->decide($sale, 'deny');

        $sale->refresh();
        $this->assertSame(
            ['denied', 'unpaid', 0, 20000],
            [$sale->discount_approval_status, $sale->payment_status, (int) $sale->discount, (int) $sale->grand_total],
        );
        $this->close($this->shift)->assertSessionHasNoErrors();

        $closed = $this->shift->fresh();
        $this->assertSame(CashierShift::STATUS_CLOSED, $closed->status);
        $this->assertSame(0, $closed->cash_sales_total); // an unpaid cash sale is no money received
        $this->assertSame(100000, $closed->expected_cash);
        $this->assertSame(1, $closed->transactions_count);
    }

    public function test_cashier_cannot_decide_from_the_list(): void
    {
        $sale = $this->pendingDiscountSale($this->shift);

        $this->actingAs($this->cashier)->post(route('discount-approvals.approve', $sale))->assertForbidden();

        $sale->refresh();
        $this->assertSame(['pending', 'pending_approval'], [$sale->discount_approval_status, $sale->payment_status]);
        // The hold is still listed, read-only: the cashier sees why and can ask a holder.
        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 1)
            ->where('closeBlockers.0.type', 'discount')
            ->where('closeBlockers.0.id', $sale->id));
    }

    public function test_force_close_leaves_the_discount_pending(): void
    {
        $sale = $this->pendingDiscountSale($this->shift);
        $log = DiscountApprovalLog::sole();
        $stock = $this->stock();

        $this->close($this->shift, $this->approver, [
            'force' => true, 'actual_cash' => 115000, 'close_notes' => 'Diskon belum diputus',
        ])->assertSessionHasNoErrors();

        $closed = $this->shift->fresh();
        $this->assertSame(CashierShift::STATUS_FORCE_CLOSED, $closed->status);
        $sale->refresh();
        $this->assertSame(
            ['pending', 'pending_approval', 5000, 15000],
            [$sale->discount_approval_status, $sale->payment_status, (int) $sale->discount, (int) $sale->grand_total],
        );
        $this->assertSame('pending', $log->fresh()->status); // the decision is still open for the approvals page
        $this->assertSame($stock, $this->stock()); // not cancelled and not restocked
        $this->assertSame(0, StockMutation::where('reference_type', 'transaction_expire')->count());
        $this->assertSame([
            'cash_sales_total' => 15000, // BR-5: the cash received counts at the force close, so no false surplus
            'expected_cash' => 115000,
            'cash_difference' => 0, // nothing is booked as a shortfall
            'unpaid_bills_total' => 0,
            'transactions_count' => 1,
        ], Arr::mapWithKeys(
            ['cash_sales_total', 'expected_cash', 'cash_difference', 'unpaid_bills_total', 'transactions_count'],
            fn (string $column) => [$column => $closed->{$column}],
        ));
        $meta = AuditLog::where('event', 'cashier_shift.hold_overridden')->sole()->meta;
        $this->assertEquals([['invoice' => $sale->invoice, 'discount' => 5000]], $meta['pending_discounts']);
    }

    public function test_force_close_over_a_discount_needs_the_note(): void
    {
        $sale = $this->pendingDiscountSale($this->shift);

        $this->close($this->shift, $this->approver, ['force' => true, 'close_notes' => '  '])
            ->assertSessionHasErrors('close_notes');

        $this->assertTrue($this->shift->fresh()->isOpen());
        $this->assertSame(['pending', 'pending_approval'], [$sale->fresh()->discount_approval_status, $sale->fresh()->payment_status]);
    }

    public function test_closed_shift_recap_never_changes_after_a_late_denial(): void
    {
        $this->assertRecapSurvives('deny');
    }

    public function test_closed_shift_recap_never_changes_after_a_late_approval(): void
    {
        $this->assertRecapSurvives('approve');
    }

    /** #396 BR-6, #542 TC-10: a deciding after the force close leaves the stored recap exactly as it closed. */
    private function assertRecapSurvives(string $decision): void
    {
        $sale = $this->pendingDiscountSale($this->shift);
        $this->close($this->shift, $this->approver, [
            'force' => true, 'actual_cash' => 115000, 'close_notes' => 'Diskon belum diputus',
        ])->assertSessionHasNoErrors();
        $before = $this->recap($this->shift->fresh());

        $this->decide($sale, $decision);

        $this->assertSame('force_closed', $this->shift->fresh()->status);
        $this->assertNotSame('pending', $sale->fresh()->discount_approval_status);
        $this->assertSame($before, $this->recap($this->shift->fresh()));
    }

    public function test_other_shifts_pending_discount_is_untouched(): void
    {
        $other = $this->openShiftFor(User::factory()->create());
        $sale = $this->pendingDiscountSale($other);

        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 0)); // AC-27: never the other shift's sale
        $this->close($this->shift)->assertSessionHasNoErrors();

        $this->assertSame(CashierShift::STATUS_CLOSED, $this->shift->fresh()->status);
        $this->assertTrue($other->fresh()->isOpen());
        $this->assertSame(['pending', 'pending_approval'], [$sale->fresh()->discount_approval_status, $sale->fresh()->payment_status]);
    }

    public function test_only_pending_unrestocked_sales_hold(): void
    {
        $this->pendingDiscountSale($this->shift, ['discount_approval_status' => 'approved', 'payment_status' => 'paid']);
        $this->pendingDiscountSale($this->shift, ['discount_approval_status' => 'denied', 'payment_status' => 'unpaid']);
        $restocked = $this->pendingDiscountSale($this->shift, ['payment_status' => 'failed']);
        StockMutation::create([
            'product_id' => $this->flagged->id, 'warehouse_id' => $this->warehouse->id, 'reference_type' => 'transaction_expire',
            'reference_id' => $restocked->id, 'mutation_type' => 'in', 'qty' => 2, 'stock_before' => 18, 'stock_after' => 20,
        ]);

        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 0));
        $this->close($this->shift)->assertSessionHasNoErrors();

        $this->assertSame(CashierShift::STATUS_CLOSED, $this->shift->fresh()->status);
    }

    public function test_split_with_pending_discount_is_listed_under_both_holds(): void
    {
        $split = $this->splitSale($this->shift, 'pending_approval', [['cash', 'paid', 5000], ['bank_transfer', 'pending', 10000]], [
            'discount' => 5000, 'grand_total' => 15000, 'discount_approval_status' => 'pending',
        ]);

        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 2)
            ->where('closeBlockers.0', ['type' => 'split', 'order' => $split->invoice, 'label' => null, 'amount' => 10000])
            ->where('closeBlockers.1', [
                'type' => 'discount', 'id' => $split->id, 'order' => $split->invoice, 'label' => null, 'amount' => 5000,
            ]));

        $this->decide($split, 'approve');

        // Only the discount reason is gone: the partly paid split still holds the close.
        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page
            ->has('closeBlockers', 1)
            ->where('closeBlockers.0.type', 'split'));
    }

    public function test_api_close_lists_the_discount_hold(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        cache()->put('api_step_up:user:'.$this->cashier->id, time());
        $sale = $this->pendingDiscountSale($this->shift);

        $closes = ['/api/v1/pos/shift/close' => 'closing_cash', "/api/v1/cashier-shifts/{$this->shift->id}/close" => 'actual_cash'];
        foreach ($closes as $url => $field) {
            $this->postJson($url, [$field => 115000])->assertStatus(422)
                ->assertJsonPath('errors.blockers.0.type', 'discount')
                ->assertJsonPath('errors.blockers.0.id', $sale->id)
                ->assertJsonPath('errors.blockers.0.order', $sale->invoice)
                ->assertJsonPath('errors.blockers.0.amount', 5000);
        }

        $this->assertTrue($this->shift->fresh()->isOpen());
        $this->assertSame('pending', $sale->fresh()->discount_approval_status);
    }

    public function test_manager_of_another_outlet_cannot_decide_it(): void
    {
        $outletA = Outlet::create(['code' => 'A542', 'name' => 'Outlet A']);
        $outletB = Outlet::create(['code' => 'B542', 'name' => 'Outlet B']);
        $warehouseA = Warehouse::create(['code' => 'WH-A542', 'name' => 'Gudang A', 'status' => 'active', 'outlet_id' => $outletA->id]);
        $shift = $this->openShiftForRaw(User::factory()->create(), $warehouseA);
        $sale = $this->pendingDiscountSale($shift, ['warehouse_id' => $warehouseA->id]);
        $outsider = User::factory()->create()->givePermissionTo('discounts-approve');
        $outsider->outlets()->attach($outletB->id, ['is_default' => true]);

        $this->actingAs($outsider)->post(route('discount-approvals.approve', $sale))->assertNotFound();

        $this->assertSame(['pending', 'pending_approval'], [$sale->fresh()->discount_approval_status, $sale->fresh()->payment_status]);
    }

    private function decide(Transaction $sale, string $action): void
    {
        $this->actingAs($this->approver)->post(route("discount-approvals.{$action}", $sale))->assertSessionHas('success');
    }

    /** @return array<string, int> the columns #396 BR-6 fixes when the shift closes */
    private function recap(CashierShift $shift): array
    {
        return Arr::mapWithKeys(
            ['cash_sales_total', 'expected_cash', 'cash_difference', 'transactions_count'],
            fn (string $column) => [$column => $shift->{$column}],
        );
    }

    private function openShiftFor(User $user): CashierShift
    {
        return $this->openShiftForRaw($user, $this->warehouse);
    }

    private function openShiftForRaw(User $user, Warehouse $warehouse): CashierShift
    {
        return app(CashierShiftService::class)->openShift($user, $user, 100000, null, $warehouse->id);
    }

    /** @param  array<string, mixed>  $data */
    private function close(CashierShift $shift, ?User $actor = null, array $data = [])
    {
        return $this->withSession($this->recentlyConfirmedSession())->actingAs($actor ?? $this->cashier)
            ->post(route('cashier-shifts.close', $shift), ['actual_cash' => 100000, ...$data]);
    }

    /** A sale of 2 x Rujak (20000) whose stock has left the shelf, as checkout leaves it. */
    private function sale(CashierShift $shift, string $method, string $status, array $attributes = []): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => $shift->user_id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)), 'cash' => 0, 'change' => 0, 'discount' => 0,
            'grand_total' => 20000, 'payment_method' => $method, 'payment_status' => $status, ...$attributes,
        ]);
        // Not a kitchen line: the close would take in any other.
        $sale->details()->create(['product_id' => $this->flagged->id, 'qty' => 2, 'price' => 20000, 'needs_preparation' => false]);
        $this->flagged->decrement('stock', 2);
        DB::table('product_warehouse')->where('product_id', $this->flagged->id)->decrement('stock', 2);

        return $sale;
    }

    /**
     * A cash sale of 15.000 after a 5.000 manual discount, still waiting for approval, exactly as TransactionController
     * leaves it: payment_status pending_approval plus a pending DiscountApprovalLog row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function pendingDiscountSale(CashierShift $shift, array $attributes = []): Transaction
    {
        $sale = $this->sale($shift, 'cash', 'pending_approval', [
            'discount' => 5000, 'grand_total' => 15000, 'cash' => 15000, 'discount_approval_status' => 'pending',
            ...$attributes,
        ]);
        DiscountApprovalLog::create([
            'transaction_id' => $sale->id, 'cashier_id' => $shift->user_id,
            'requested_discount' => (int) $sale->discount, 'status' => 'pending',
        ]);

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
            $sale->tenders()->create(['method' => $method, 'amount' => $amount, 'payment_status' => $state]);
        }

        return $sale;
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
