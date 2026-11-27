<?php

namespace App\Services;

use App\Jobs\ExpireSupersededGatewayCharge;
use App\Models\DineOrder;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

/**
 * A guest pays the open bill of an accepted dine-in order online, from the QR menu status page.
 *
 * Every charge is a payment attempt (`{invoice}-r{n}`, the counter of #281), so the existing webhook settles
 * the bill and ignores a superseded charge. Unlike the cashier's payment it never writes cashier_id or
 * cashier_shift_id: the payment stays in the shift that accepted the order.
 */
class DineOrderOnlinePaymentService
{
    public const METHOD_QRIS = 'qris';

    /** How long a guest's QRIS stays valid; an abandoned charge would keep the bill pending (and the cashier waiting). */
    public const EXPIRES_IN_MINUTES = 15;

    // What payable() answers. state() and start() read the same answer differently.
    private const OK = 'ok';

    private const NOT_PAYABLE = 'not_payable';

    private const ALREADY_PAID = 'already_paid';

    // Not for the guest to pay online (a pay-later bill, or a charge the cashier started): the counter settles it.
    private const COUNTER = 'counter';

    private const NO_GATEWAY = 'no_gateway';

    public function __construct(
        private readonly PaymentGatewayManager $gatewayManager,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * What the guest page shows about paying, or null while there is nothing to show (not accepted, rejected,
     * nothing left to pay). Holds no internal field: no invoice, bill id, customer, outlet or gateway reference.
     *
     * @return array{state: string, amount: int, attempt: int, payment_url: ?string, qr: ?string}|null
     */
    public function state(DineOrder $order, bool $withQr = true): ?array
    {
        $bill = $order->transaction_id ? Transaction::find($order->transaction_id) : null;
        $remaining = $bill?->remainingTotal() ?? 0;

        $state = match ($this->payable($order, $bill, $remaining)) {
            self::NOT_PAYABLE => null,
            self::ALREADY_PAID => 'paid',
            self::COUNTER, self::NO_GATEWAY => 'counter',
            default => $this->chargeState($bill),
        };

        if ($state === null) {
            return null;
        }

        $pending = $state === 'pending';

        return [
            'state' => $state,
            'amount' => $remaining,
            'attempt' => (int) $bill->payment_attempt,
            'payment_url' => $pending ? $bill->payment_url : null,
            'qr' => $pending && $withQr && filled($bill->qr_string) ? $this->qrImage($bill->qr_string) : null,
        ];
    }

    /**
     * Charge the bill's remaining total under a new attempt and return the new state.
     *
     * The attempt is claimed and committed first, the gateway is called with no transaction open (a slow gateway
     * must not hold the bill's row lock), and the result is stored only if no newer attempt claimed the bill meanwhile.
     *
     * @param  string  $method  METHOD_QRIS (the QR on the page) or 'gateway' (the gateway's page, every enabled method)
     *
     * @throws ValidationException payment => not_payable | already_paid | no_gateway | gateway_error
     */
    public function start(DineOrder $order, string $method): array
    {
        [$bill, $attempt, $amount, $setting, $gateway] = DB::transaction(function () use ($order, $method) {
            // The same row lock the cashier's payment takes: a second tap waits here and then supersedes the first.
            $bill = $order->transaction_id
                ? Transaction::with('warehouse.outlet')->whereKey($order->transaction_id)->lockForUpdate()->first()
                : null;
            $order->refresh();
            $amount = $bill?->remainingTotal() ?? 0;

            $this->refuseUnlessPayable($this->payable($order, $bill, $amount));

            [$setting, $gateway] = $this->gateway($bill) ?? throw ValidationException::withMessages(['payment' => [self::NO_GATEWAY]]);
            $attempt = (int) $bill->payment_attempt + 1;

            // A guest charge never has tender rows; the ones left are a failed cashier attempt's, which held no money.
            $bill->tenders()->delete();
            // A charge still pending is superseded by this tap: have the gateway expire it once this claim commits.
            if ($bill->payment_status === 'pending') {
                ExpireSupersededGatewayCharge::dispatchFor($bill, $bill->only(['payment_attempt', 'payment_method', 'payment_reference']));
            }
            $bill->update([
                'payment_attempt' => $attempt,
                'payment_method' => $method === self::METHOD_QRIS ? TransactionTender::METHOD_QRIS : $gateway,
                'payment_status' => 'pending',
                'payment_failure_reason' => null,
                'payment_reference' => null,
                'payment_url' => null,
                'qr_string' => null,
            ]);

            $this->auditLogService->log(
                event: 'dine_order.online_payment_started',
                module: 'transactions',
                auditable: $bill,
                description: "Tamu memulai pembayaran online invoice {$bill->invoice} (percobaan {$attempt}).",
                meta: ['attempt' => $attempt, 'method' => $method, 'amount' => $amount],
            );

            return [$bill, $attempt, $amount, $setting, $gateway];
        });

        $orderId = "{$bill->invoice}-r{$attempt}";
        $returnUrl = route('dine-order.status', $order->access_token);

        try {
            $charge = $method === self::METHOD_QRIS
                ? $this->gatewayManager->createQrisPayment($bill, $setting, $amount, $orderId, $returnUrl, self::EXPIRES_IN_MINUTES)
                : $this->gatewayManager->createPayment($bill, $gateway, $setting, $amount, $orderId, $returnUrl, self::EXPIRES_IN_MINUTES);

            // A charge with neither a page nor a QR gives the guest nothing to pay with and would keep the bill pending.
            if (blank($charge['payment_url'] ?? null) && blank($charge['qr_string'] ?? null)) {
                throw new \RuntimeException('The gateway returned no payment page and no QR.');
            }
        } catch (Throwable $exception) {
            // Failed, so the bill is payable at the counter again; any failure counts (also a timeout).
            Transaction::storeChargeResult($bill->id, $attempt, ['payment_status' => 'failed', 'payment_failure_reason' => 'gateway_error']);
            Log::warning('Dine guest payment could not be started', ['transaction_id' => $bill->id, 'attempt' => $attempt, 'message' => $exception->getMessage()]);

            throw ValidationException::withMessages(['payment' => ['gateway_error']]);
        }

        $stored = Transaction::storeChargeResult($bill->id, $attempt, [
            'payment_reference' => $charge['reference'] ?? null,
            'payment_url' => $charge['payment_url'] ?? null,
            'qr_string' => $charge['qr_string'] ?? null,
        ]);
        if (! $stored) {
            // Superseded while the gateway answered: only now is this charge's reference known. A charge that is still
            // current (a webhook settled it first) is left alone by the job.
            ExpireSupersededGatewayCharge::dispatchFor($bill, ['payment_attempt' => $attempt, 'payment_method' => $bill->payment_method, 'payment_reference' => $charge['reference'] ?? null]);
        }
        Log::info('Dine guest payment started', ['transaction_id' => $bill->id, 'attempt' => $attempt, 'gateway' => $gateway, 'amount' => $amount]);

        return $this->state($order) ?? throw ValidationException::withMessages(['payment' => [self::NOT_PAYABLE]]);
    }

    /**
     * The one rule for both methods: may the guest pay this order's bill online?
     *
     * @return string one of the class's answers (OK, NOT_PAYABLE, ALREADY_PAID, COUNTER, NO_GATEWAY)
     */
    private function payable(DineOrder $order, ?Transaction $bill, int $remaining): string
    {
        if (! $bill) {
            return self::NOT_PAYABLE;
        }

        if ($bill->payment_status === 'paid') {
            return self::ALREADY_PAID;
        }

        // Before accept there is no bill; a rejected or cancelled order has none to pay. Nothing left after returns: never a zero charge.
        if (! in_array($order->status, DineOrder::IN_PROGRESS, true) || $remaining < 1
            || ! in_array($bill->payment_status, [...Transaction::OPEN_BILL_PAYABLE, 'pending'], true)) {
            return self::NOT_PAYABLE;
        }

        // #328 (pay later from the guest page) deletes this clause.
        if ($bill->is_pay_later) {
            return self::COUNTER;
        }

        // A pending bill with tender rows is the cashier's own gateway charge or transfer (a guest charge never has tenders).
        // Money already taken in a split payment holds the bill too: either way the guest cannot start a second charge.
        $tenders = $bill->tenders();
        $held = $bill->payment_status === 'pending'
            ? $tenders->exists()
            : $tenders->where('payment_status', TransactionTender::STATUS_PAID)->exists();
        if ($held) {
            return self::COUNTER;
        }

        return $this->gateway($bill) ? self::OK : self::NO_GATEWAY;
    }

    /** @throws ValidationException */
    private function refuseUnlessPayable(string $answer): void
    {
        if ($answer !== self::OK) {
            // The counter answer (pay-later bill, cashier's charge) reads as "not payable" to a request.
            throw ValidationException::withMessages(['payment' => [$answer === self::COUNTER ? self::NOT_PAYABLE : $answer]]);
        }
    }

    /** The outlet's setting and the gateway a charge goes to (Midtrans first, as the QRIS charge picks), or null when none is ready. */
    private function gateway(Transaction $bill): ?array
    {
        $setting = PaymentSetting::forOutlet($bill->warehouse?->outlet);

        foreach ([PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT] as $gateway) {
            if ($setting?->isGatewayReady($gateway)) {
                return [$setting, $gateway];
            }
        }

        return null;
    }

    /** The guest-visible state of a bill the guest may pay online. */
    private function chargeState(Transaction $bill): string
    {
        return match (true) {
            $bill->payment_status === 'pending' => 'pending',
            $bill->payment_status === 'unpaid' => 'can_pay',
            $bill->payment_failure_reason === 'expired' => 'expired',
            $bill->payment_failure_reason === 'gateway_error' => 'gateway_error',
            default => 'failed',
        };
    }

    private function qrImage(string $qrString): string
    {
        // SVG: the PNG back end needs ext-imagick. An <img> with a data URI runs no script.
        return 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::format('svg')->size(300)->margin(2)->generate($qrString));
    }
}
