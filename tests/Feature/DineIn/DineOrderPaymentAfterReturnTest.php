<?php

namespace Tests\Feature\DineIn;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/** #405: a return recorded on an unpaid dine bill lowers what the counter asks for and accepts (25000 - 5000 = 20000). */
class DineOrderPaymentAfterReturnTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
        $this->seed(PermissionSeeder::class);
        $this->cashier->givePermissionTo(['dine-orders-process', 'sales-returns-create', 'sales-returns-complete', 'transactions-access']);
    }

    /** @param  array<string, mixed>  $tender */
    private function payAtCounter(int|string $orderId, array $tender): TestResponse
    {
        return $this->actingAs($this->cashier)->post(route('dine-orders.pay', $orderId), ['tenders' => [$tender]]);
    }

    public function test_counter_pay_asks_only_what_the_bill_still_owes(): void
    {
        $order = $this->acceptedOrder();
        $return = $this->returnFromBill($this->bill($order), $this->plain);
        $this->assertSame([0, 0, 5000], [(int) $return->refund_amount, (int) $return->credited_amount, (int) $return->total_return_amount]);

        // the old total is no longer what the guest owes
        $this->payAtCounter($order->id, ['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL])
            ->assertSessionHasErrors('tenders');
        $this->assertSame('unpaid', $this->bill($order)->payment_status);
        $this->assertSame(0, $this->bill($order)->tenders()->count());

        $this->payAtCounter($order->id, ['method' => 'cash', 'amount' => 20000, 'cash_received' => self::TOTAL])
            ->assertSessionHasNoErrors();

        $bill = $this->bill($order);
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(5000, (int) $bill->change);
        $this->assertSame(20000, (int) $bill->tenders()->sole()->amount);
    }

    public function test_counter_gateway_charge_uses_the_owed_amount(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->payAtCounter($order->id, ['method' => 'qris', 'amount' => 20000])->assertSessionHasNoErrors();

        $this->assertSame('pending', $this->bill($order)->payment_status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['transaction_details']['gross_amount'] === 20000);
    }

    public function test_fully_returned_dine_bill_cannot_be_paid(): void
    {
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->flagged, 2);
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->payAtCounter($order->id, ['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL])
            ->assertSessionHasErrors(['order' => 'Penjualan ini sudah diretur penuh, tidak ada yang perlu dibayar.']);

        $bill = $this->bill($order);
        $this->assertSame('unpaid', $bill->payment_status);
        $this->assertSame('open_bill', $bill->payment_method);
        $this->assertSame(0, $bill->tenders()->count());
    }

    /** The "Bayar" modal on Transactions History takes its amount from the page, so the page must send what is owed. */
    public function test_history_sends_what_an_open_bill_still_owes(): void
    {
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->actingAs($this->cashier)->get(route('transactions.history'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.owed_total', 20000));
    }
}
