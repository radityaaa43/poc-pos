<?php

namespace App\Testing\E2e;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

/**
 * Registered by AppServiceProvider only when APP_ENV=e2e. Nothing here may run anywhere else.
 */
class E2eServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A running `npm run dev` writes public/hot; e2e always serves the built assets.
        Vite::useHotFile(storage_path('framework/e2e.hot'));

        // Each request is a fresh PHP process under `artisan serve`, so faking in boot() is enough.
        // Http::preventStrayRequests() is deliberately not used: other outbound HTTP may be legitimate.
        $midtrans = function (Request $request) {
            $orderId = $request['transaction_details']['order_id'] ?? 'e2e-order';

            return Http::response([
                'order_id' => $orderId,
                'token' => 'e2e-snap-token',
                'redirect_url' => "http://pay.e2e.test/midtrans/{$orderId}",
                'qr_string' => "00020101e2e-qris-{$orderId}",
            ], 201);
        };

        Http::fake([
            'app.sandbox.midtrans.com/*' => $midtrans,
            'app.midtrans.com/*' => $midtrans,
            'api.xendit.co/*' => function (Request $request) {
                $externalId = $request['external_id'] ?? 'e2e-order';

                return Http::response([
                    'id' => "e2e-invoice-{$externalId}",
                    'invoice_url' => "http://pay.e2e.test/xendit/{$externalId}",
                    'qr_string' => "00020101e2e-qris-{$externalId}",
                ], 200);
            },
            // Per-outlet `wa_service_url` setting seeded by E2eSeeder.
            'wa.e2e.test/*' => fn () => Http::response(['connected' => true, 'status' => true]),
        ]);

        $this->commands([ResetCommand::class, ArrangeCommand::class]);
    }
}
