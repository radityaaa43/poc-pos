<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MidtransGateway
{
    public function createCharge(Transaction $transaction, array $config, ?int $amount = null, ?string $orderId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        return $this->createTransaction($transaction, $config, null, $amount, $orderId, $returnUrl, $expiresInMinutes);
    }

    /**
     * Dynamic QRIS charge — Snap transaction restricted to QR-compatible
     * e-wallet payments; returns qr_string for on-counter rendering.
     */
    public function createQrisCharge(Transaction $transaction, array $config, ?int $amount = null, ?string $orderId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        $result = $this->createTransaction($transaction, $config, ['qris', 'gopay', 'shopeepay'], $amount, $orderId, $returnUrl, $expiresInMinutes);

        return [
            ...$result,
            'qr_string' => $result['raw']['qr_string'] ?? null,
        ];
    }

    /**
     * Core API expire: it moves only a pending transaction, so it cannot void a payment that has just settled.
     *
     * @return string expired, not_pending, not_found, transient or permanent
     */
    public function expireCharge(array $config, string $orderId, ?string $pageUrl = null): string
    {
        $host = ($config['is_production'] ?? false) ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';

        try {
            $response = Http::timeout(10)->withBasicAuth($config['server_key'], '')
                ->send('POST', "{$host}/v2/".rawurlencode($orderId).'/expire');
        } catch (ConnectionException) {
            return 'transient';
        }

        // The body carries its own status_code, also under HTTP 200; 407 is "success, transaction has expired".
        $code = (int) ($response->json('status_code') ?? $response->status());

        return match (true) {
            $code === 407 => 'expired',
            $code === 412 => 'not_pending',
            // No Core API transaction until a method is picked on the page: close the page itself.
            $code === 404 => $pageUrl ? $this->cancelSnapSession($config, basename((string) parse_url($pageUrl, PHP_URL_PATH))) : 'not_found',
            $code === 429, $code >= 500 => 'transient',
            default => 'permanent',
        };
    }

    /**
     * docs.midtrans.com/reference/cancel-a-snap-session, by the token that ends the page's URL. The docs name messages,
     * no error codes and no sandbox URL (assumed on the host that creates the page): anything else is retried.
     */
    private function cancelSnapSession(array $config, string $token): string
    {
        try {
            $response = Http::timeout(10)->withBasicAuth($config['server_key'], '')->acceptJson()
                ->send('POST', $this->snapUrl($config).'/'.rawurlencode($token).'/cancel');
        } catch (ConnectionException) {
            return 'transient';
        }

        $error = strtolower(implode(' ', (array) $response->json('error_messages')));

        return match (true) {
            $response->successful() && filled($response->json('canceled_at')) => 'expired',
            str_contains($error, 'already canceled') => 'not_pending',
            str_contains($error, 'token not found') => 'not_found',
            default => 'transient', // "Transaction is on progress" too: the next try finds the Core API transaction
        };
    }

    /** Where a Snap payment page is created, and canceled. */
    private function snapUrl(array $config): string
    {
        return config($config['is_production'] ?? false ? 'services.midtrans.snap_url' : 'services.midtrans.snap_sandbox_url');
    }

    private function createTransaction(Transaction $transaction, array $config, ?array $enabledPayments = null, ?int $amount = null, ?string $orderId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        if (! ($config['enabled'] ?? false)) {
            throw new PaymentGatewayException('Midtrans tidak aktif atau belum dikonfigurasi.');
        }

        $endpoint = $this->snapUrl($config);

        $customer = $transaction->customer;

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId ?? $transaction->invoice,
                'gross_amount' => $amount ?? (int) $transaction->grand_total,
            ],
            'customer_details' => [
                'first_name' => optional($customer)->name ?? 'Customer',
                'email' => optional($customer)->email ?? config('mail.from.address'),
                'phone' => optional($customer)->no_telp,
            ],
            'callbacks' => [
                'finish' => $returnUrl ?? route('transactions.print', $transaction->invoice),
            ],
        ];

        if ($expiresInMinutes !== null) {
            $payload['expiry'] = ['unit' => 'minutes', 'duration' => $expiresInMinutes];
        }

        if ($enabledPayments !== null) {
            $payload['enabled_payments'] = $enabledPayments;
        }

        $response = Http::withBasicAuth($config['server_key'], '')
            ->post($endpoint, $payload);

        if ($response->failed()) {
            throw new PaymentGatewayException(
                'Midtrans error: '.$response->json('status_message', $response->body())
            );
        }

        return [
            'reference' => $response->json('order_id', $orderId ?? $transaction->invoice),
            'payment_url' => $response->json('redirect_url'),
            'token' => $response->json('token'),
            'raw' => $response->json(),
        ];
    }
}
