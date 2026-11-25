<?php

namespace Tests\Unit\Exports;

use App\Exports\TransactionsExport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Tests\TestCase;

class TransactionsExportTest extends TestCase
{
    public function test_subtotal_adds_up_with_discount_shipping_and_tax(): void
    {
        // grand_total is net of the discount and already includes shipping and tax (LoyaltyService).
        $transaction = new Transaction([
            'invoice' => 'TRX-1',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'discount' => 3000,
            'shipping_cost' => 2000,
            'tax_total' => 2700,
            'grand_total' => 31700,
        ]);
        $transaction->created_at = now();

        $row = (new TransactionsExport(new Request))->map($transaction);

        [$subtotal, $discount, $shipping, $tax, $grandTotal] = array_slice($row, 6);
        $this->assertSame(30000, $subtotal);
        $this->assertSame($grandTotal, $subtotal - $discount + $shipping + $tax);
    }
}
