<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class XenditGateway
{
    public function createInvoice(Transaction $transaction, array $config, ?int $amount = null, ?string $externalId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        return $this->createInvoiceRequest($transaction, $config, null, $amount, $externalId, $returnUrl, $expiresInMinutes);
    }

    /**
     * Dynamic QRIS invoice — QRIS-only channel; returns qr_string.
     */
    public function createQrisInvoice(Transaction $transaction, array $config, ?int $amount = null, ?string $externalId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        $result = $this->createInvoiceRequest($transaction, $config, ['QRIS'], $amount, $externalId, $returnUrl, $expiresInMinutes);

        return [
            ...$result,
            'qr_string' => $result['raw']['qr_string'] ?? null,
        ];
    }

    /**
     * Expire an unpaid invoice, by the invoice id Xendit returned (the external id is not enough).
     *
     * @return string expired, not_pending, not_found, transient or permanent
     */
    public function expireInvoice(array $config, string $invoiceId): string
    {
        try {
            $response = Http::timeout(10)->withBasicAuth($config['secret_key'], '')
                ->send('POST', 'https://api.xendit.co/invoices/'.rawurlencode($invoiceId).'/expire!');
        } catch (ConnectionException) {
            return 'transient';
        }

        // A paid or already expired invoice is refused with a 4xx; only an answer saying EXPIRED counts as done by us.
        return match (true) {
            $response->successful() => $response->json('status') === 'EXPIRED' ? 'expired' : 'not_pending',
            in_array($response->status(), [401, 403], true) => 'permanent',
            $response->status() === 404 => 'not_found',
            in_array($response->status(), [408, 429], true), $response->serverError() => 'transient',
            default => 'not_pending',
        };
    }

    private function createInvoiceRequest(Transaction $transaction, array $config, ?array $channels = null, ?int $amount = null, ?string $externalId = null, ?string $returnUrl = null, ?int $expiresInMinutes = null): array
    {
        if (! ($config['enabled'] ?? false)) {
            throw new PaymentGatewayException('Xendit tidak aktif atau belum dikonfigurasi.');
        }

        $customer = $transaction->customer;

        $payload = [
            'external_id' => $externalId ?? $transaction->invoice,
            'amount' => $amount ?? (int) $transaction->grand_total,
            'description' => 'Pembayaran transaksi #'.$transaction->invoice,
            'customer' => [
                'given_names' => optional($customer)->name ?? 'Customer',
                'email' => optional($customer)->email ?? config('mail.from.address'),
                'mobile_number' => optional($customer)->no_telp,
            ],
            'success_redirect_url' => $returnUrl ?? route('transactions.print', $transaction->invoice),
        ];

        if ($expiresInMinutes !== null) {
            $payload['invoice_duration'] = $expiresInMinutes * 60; // Xendit takes seconds
        }

        if ($channels !== null) {
            $payload['channel_code'] = $channels;
        }

        $response = Http::withBasicAuth($config['secret_key'], '')
            ->post('https://api.xendit.co/v2/invoices', $payload);

        if ($response->failed()) {
            throw new PaymentGatewayException(
                'Xendit error: '.$response->json('message', $response->body())
            );
        }

        return [
            'reference' => $response->json('id'),
            'payment_url' => $response->json('invoice_url'),
            'raw' => $response->json(),
        ];
    }
}
