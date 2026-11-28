<?php

namespace Tests\Feature\Settings;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// REQ-010 BR-2: a sale can keep the bill rules it was computed with.
class OutletBillSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_keeps_the_bill_values_it_is_given(): void
    {
        $values = ['service_charge' => 500, 'tax_kind' => 'pb1', 'tax_base' => 10500];
        $bare = ['cashier_id' => User::factory()->create()->id, 'cash' => 11550, 'change' => 0, 'discount' => 0, 'grand_total' => 11550];

        $sale = Transaction::create($bare + $values + ['invoice' => 'TRX-BILL-2'])->fresh();
        $earlier = Transaction::create($bare + ['invoice' => 'TRX-BILL-1'])->fresh();

        $this->assertSame($values, $sale->only(array_keys($values)));
        $this->assertSame(['service_charge' => 0, 'tax_kind' => null, 'tax_base' => null], $earlier->only(array_keys($values)));
    }
}
