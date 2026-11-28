<?php

namespace App\Services;

use App\Exceptions\CreditRefusedException;
use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\CreditApprovalRequest;
use App\Models\CustomerVoucher;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Checkout\CheckoutContext;
use App\Support\Checkout\CheckoutResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The manager exception to the credit rules (REQ-007 AC-06). A refused sale is parked as a held cart plus one request:
 * nothing moves until an approver books it, once, as the cashier.
 *
 * Lock order: cashier shift, customer, request, carts, then what the checkout locks (stock).
 */
class CreditApprovalService
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly CheckoutService $checkoutService,
        private readonly CustomerCreditService $customerCredit,
        private readonly OutletAccessService $outletAccessService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /** Checks the sale out; null when it was refused and, as asked, parked for an approver instead. */
    public function checkout(CheckoutContext $ctx, bool $askException): ?CheckoutResult
    {
        try {
            return $this->checkoutService->execute($ctx);
        } catch (CreditRefusedException $refusal) {
            throw_unless($askException, $refusal);
            $this->hold($ctx, $refusal);

            return null;
        }
    }

    public function pendingFor(User $user): Collection
    {
        $warehouseIds = $this->outletAccessService->warehousesFor($user)
            // The same rule as approve and deny, so nothing is listed that would answer 404.
            ->filter(fn ($warehouse) => $warehouse instanceof Warehouse && $this->outletAccessService->canUseWarehouse($user, $warehouse))
            ->pluck('id');

        return CreditApprovalRequest::with('customer:id,name', 'cashier:id,name')
            ->where('status', CreditApprovalRequest::PENDING)
            ->whereIn('warehouse_id', $warehouseIds)
            ->oldest('id')
            ->get()
            ->map(fn (CreditApprovalRequest $request) => [
                'id' => $request->id,
                'customer' => $request->customer->name,
                'cashier' => $request->cashier->name,
                'amount' => $request->amount,
                'refusal' => CreditRefusedException::describe($request->reason_code, $request->remaining_limit),
                'created_at' => $request->created_at->toISOString(),
            ]);
    }

    /** Books the held sale once. A second approval of the same request changes nothing. */
    public function approve(CreditApprovalRequest $request, string $reason, User $approver): void
    {
        // Before the transaction: its first plain read must come after the customer lock.
        $this->ensureOutletAccess($request, $approver);

        DB::transaction(function () use ($request, $reason, $approver) {
            // The same query as the checkout's own lock, so both take the shift's index and row in one order.
            $shift = CashierShift::query()->open()->where('user_id', $request->cashier_id)
                ->latest('opened_at')->lockForUpdate()->first();
            $customer = $this->customerCredit->lock($request->customer_id);
            $locked = CreditApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === CreditApprovalRequest::APPROVED) {
                return;
            }
            $this->ensure($locked->status === CreditApprovalRequest::PENDING, 'already_denied');
            $this->ensure($shift?->id === $locked->cashier_shift_id, 'shift_closed');
            $this->ensure((bool) $customer->may_buy_on_credit, 'credit_revoked');

            $held = Cart::where('cashier_id', $locked->cashier_id)->forHold($locked->hold_id);
            $cartIds = (clone $held)->pluck('id')->all();
            $this->ensure($cartIds !== [], 'cart_gone');
            // BR-12: the outlet's setting today wins over the request. A plain read, so only after the locks above.
            $this->ensure(Setting::getBoolForOutlet('pay_later_enabled', $shift->warehouse?->outlet), 'pay_later_off');
            $held->update(['hold_id' => null, 'hold_label' => null, 'held_at' => null]);

            $payload = $locked->payload;
            $result = $this->checkoutService->execute(new CheckoutContext(
                userId: $locked->cashier_id,
                customer: $customer,
                voucher: $payload['customer_voucher_id'] ? CustomerVoucher::find($payload['customer_voucher_id']) : null,
                manualDiscount: $payload['discount'],
                shippingCost: $payload['shipping_cost'],
                requestedRedeemPoints: $payload['redeem_points'],
                isPayLater: true,
                dueDate: $payload['due_date'],
                orderType: $payload['order_type'],
                note: $payload['note'],
                customerNpwp: $payload['customer_npwp'],
                isCashPayment: false,
                cashAmount: 0,
                paymentGateway: null,
                useTenders: false,
                tenderInput: [],
                outlet: $shift->warehouse?->outlet,
                bankAccountId: null,
                onlyCartIds: $cartIds,
                creditApproved: true,
            ));

            // The approver agreed to an amount: a price, promo or voucher that changed since voids that agreement.
            $this->ensure((int) $result->transaction->grand_total === $locked->amount, 'total_changed');

            if ($result->needsDiscountApproval) {
                $this->checkoutService->requestDiscountApproval($result, $locked->cashier_id);
            }

            $this->decide($locked, CreditApprovalRequest::APPROVED, $reason, $approver, $result->transaction->id);
            $this->audit($locked, 'approved', 'Pengecualian kredit disetujui', $approver, [
                'amount' => $locked->amount,
                'invoice' => $result->transaction->invoice,
                'reason' => $reason,
                'approver' => $approver->name,
            ]);
        }, attempts: 3);
    }

    /** The basket stays held, so the cashier can resume it to take payment now, or delete it. */
    public function deny(CreditApprovalRequest $request, string $reason, User $approver): void
    {
        $this->ensureOutletAccess($request, $approver);

        DB::transaction(function () use ($request, $reason, $approver) {
            $locked = CreditApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === CreditApprovalRequest::DENIED) {
                return;
            }
            $this->ensure($locked->status === CreditApprovalRequest::PENDING, 'already_approved');

            Cart::where('cashier_id', $locked->cashier_id)->forHold($locked->hold_id)
                ->update(['hold_label' => 'Pengecualian kredit ditolak']);

            $this->decide($locked, CreditApprovalRequest::DENIED, $reason, $approver);
            $this->audit($locked, 'denied', 'Pengecualian kredit ditolak', $approver, [
                'amount' => $locked->amount,
                'reason' => $reason,
                'approver' => $approver->name,
            ]);
        }, attempts: 3);
    }

    /** Parks the refused cart. The refused checkout rolled back, so no sale, stock, receivable or kitchen item exists. */
    private function hold(CheckoutContext $ctx, CreditRefusedException $refusal): void
    {
        DB::transaction(function () use ($ctx, $refusal) {
            $shift = $this->cashierShiftService->requireActiveShiftForUser($ctx->userId, lockForUpdate: true);
            $holdId = 'HOLD-'.strtoupper(uniqid());

            $parked = Cart::where('cashier_id', $ctx->userId)->active()
                ->update(['hold_id' => $holdId, 'hold_label' => 'Menunggu persetujuan kredit', 'held_at' => now()]);
            // A second ask for the same basket finds it already parked.
            $this->ensure($parked > 0, 'cart_gone', 'credit');

            $request = CreditApprovalRequest::create([
                'customer_id' => $ctx->customer->id,
                'cashier_id' => $ctx->userId,
                'cashier_shift_id' => $shift->id,
                'warehouse_id' => $shift->warehouse_id,
                'hold_id' => $holdId,
                'amount' => $refusal->total,
                'reason_code' => $refusal->reason,
                'remaining_limit' => $refusal->remaining,
                // Built from the server's own context, never from what the client sends at approval.
                'payload' => [
                    'customer_voucher_id' => $ctx->voucher?->id,
                    'discount' => $ctx->manualDiscount,
                    'shipping_cost' => $ctx->shippingCost,
                    'redeem_points' => $ctx->requestedRedeemPoints,
                    'due_date' => $ctx->dueDate,
                    'order_type' => $ctx->orderType,
                    'note' => $ctx->note,
                    'customer_npwp' => $ctx->customerNpwp,
                ],
            ]);

            $this->audit($request, 'requested', 'Pengecualian kredit diajukan', null, ['amount' => $refusal->total]);
        }, attempts: 3);
    }

    /** 404, not 403, as the discount queue does: another outlet's request does not exist for this user. */
    private function ensureOutletAccess(CreditApprovalRequest $request, User $approver): void
    {
        abort_unless($this->outletAccessService->canUseWarehouse($approver, $request->warehouse), 404);
    }

    private function ensure(bool $condition, string $message, string $field = 'reason'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => __("messages.credit.$message")]);
        }
    }

    private function decide(CreditApprovalRequest $request, string $status, string $reason, User $approver, ?int $transactionId = null): void
    {
        $request->update([
            'status' => $status,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'decision_reason' => $reason,
            'transaction_id' => $transactionId,
        ]);
    }

    /** Logged on the customer, so it is found by their name; the actor is the approver, or the cashier asking. */
    private function audit(CreditApprovalRequest $request, string $event, string $description, ?User $actor, array $after): void
    {
        $this->auditLogService->log(
            event: "credit_approval.$event",
            module: 'customers',
            auditable: $request->customer,
            description: $description,
            after: ['request_id' => $request->id] + $after,
            meta: ['reason_code' => $request->reason_code, 'cashier_id' => $request->cashier_id],
            actor: $actor,
        );
    }
}
