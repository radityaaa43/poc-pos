<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\TransactionTender;

class PaymentGatewayManager
{
    public function __construct(
        private MidtransGateway $midtransGateway,
        private XenditGateway $xenditGateway
    ) {}

    public function createPayment(Transaction $transaction, string $gateway, PaymentSetting $setting, ?int $amount = null, ?string $orderId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        return match ($gateway) {
            PaymentSetting::GATEWAY_MIDTRANS => $this->midtransGateway->createCharge($transaction, $setting->midtransConfig(), $amount, $orderId, $returnUrl, $expiresInMinutes),
            PaymentSetting::GATEWAY_XENDIT => $this->xenditGateway->createInvoice($transaction, $setting->xenditConfig(), $amount, $orderId, $returnUrl, $expiresInMinutes),
            default => throw new PaymentGatewayException("Gateway {$gateway} belum didukung."),
        };
    }

    /**
     * Dynamic QRIS charge — uses whichever gateway is ready (midtrans
     * preferred, xendit fallback). Returns reference/payment_url/qr_string.
     */
    public function createQrisPayment(Transaction $transaction, PaymentSetting $setting, ?int $amount = null, ?string $orderId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        if ($setting->isGatewayReady(PaymentSetting::GATEWAY_MIDTRANS)) {
            return $this->midtransGateway->createQrisCharge($transaction, $setting->midtransConfig(), $amount, $orderId, $returnUrl, $expiresInMinutes);
        }

        if ($setting->isGatewayReady(PaymentSetting::GATEWAY_XENDIT)) {
            return $this->xenditGateway->createQrisInvoice($transaction, $setting->xenditConfig(), $amount, $orderId, $returnUrl, $expiresInMinutes);
        }

        throw new PaymentGatewayException('Tidak ada gateway aktif untuk QRIS. Aktifkan Midtrans atau Xendit di pengaturan pembayaran.');
    }

    /**
     * Ask the gateway holding a charge to expire it. Midtrans knows it by the order id, Xendit by its invoice id.
     *
     * @return string expired, not_pending, not_found, transient or permanent
     */
    public function expireCharge(string $gateway, PaymentSetting $setting, string $orderId, ?string $reference): string
    {
        return match ($gateway) {
            PaymentSetting::GATEWAY_MIDTRANS => $this->midtransGateway->expireCharge($setting->midtransConfig(), $orderId),
            PaymentSetting::GATEWAY_XENDIT => $reference ? $this->xenditGateway->expireInvoice($setting->xenditConfig(), $reference) : 'permanent',
            default => 'permanent',
        };
    }

    /**
     * Charge a single tender. For split payments the gateway amount must be
     * the tender amount and the orderId unique per transaction+tender, so
     * gateway webhooks don't flip the whole transaction paid.
     */
    public function createTenderPayment(Transaction $transaction, TransactionTender $tender, PaymentSetting $setting): array
    {
        $orderId = $transaction->tenders()->count() > 1
            ? $transaction->invoice.'-'.$tender->method
            : $transaction->invoice;

        // A bill with a payment history (a guest attempt, a retry) is charged under its current attempt: without the
        // suffix the callback reads as attempt 0 and is treated as superseded, so the cashier's own payment would never be booked.
        if ($transaction->payment_attempt > 0) {
            $orderId .= '-r'.$transaction->payment_attempt;
        }

        $response = $tender->method === TransactionTender::METHOD_QRIS
            ? $this->createQrisPayment($transaction, $setting, $tender->amount, $orderId)
            : $this->createPayment($transaction, $tender->method, $setting, $tender->amount, $orderId);

        return [
            'reference' => $response['reference'] ?? null,
            'payment_url' => $response['payment_url'] ?? null,
            'qr_string' => $response['qr_string'] ?? null,
        ];
    }
}
