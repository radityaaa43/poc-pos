<?php

namespace Tests\Feature\DineIn;

use App\Models\DineOrder;
use App\Models\SalesReturn;
use App\Models\Transaction;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->cashier->givePermissionTo([
            'dine-orders-process', 'sales-returns-access', 'sales-returns-create', 'sales-returns-complete', 'transactions-access',
        ]);
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

    /** @return array<string, array<int, string>> both completion paths: web and mobile API */
    public static function completions(): array
    {
        return ['web' => ['web'], 'api' => ['api']];
    }

    /** A draft return of the plain line (5000), not completed yet. */
    private function draftReturn(Transaction $bill): SalesReturn
    {
        $this->actingAs($this->cashier)->post(route('sales-returns.store', $bill), [
            'return_type' => 'refund_cash',
            'items' => [[
                'transaction_detail_id' => $bill->details()->where('product_id', $this->plain->id)->sole()->id,
                'qty_return' => 1, 'return_reason' => 'Batal', 'restock_to_inventory' => true,
            ]],
        ])->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    private function complete(string $via, SalesReturn $return): void
    {
        if ($via === 'api') {
            Sanctum::actingAs($this->cashier, ['*']);
            $this->postJson(route('api.sales-returns.complete', $return))->assertOk();

            return;
        }

        $this->actingAs($this->cashier)->post(route('sales-returns.complete', $return))->assertSessionHasNoErrors();
    }

    /** TC-6: completing a return locks the sale as a payment does: after the dine order, before any stock row. */
    #[DataProvider('completions')]
    public function test_return_and_payment_on_one_bill_are_serialised(string $via): void
    {
        $return = $this->draftReturn($this->bill($this->acceptedOrder()));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->complete($via, $return);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // SQLite quotes names with ", MySQL (the CI backend job) with `: match both.
        $first = fn (string $pattern, int $from = 0) => $queries->slice($from)->search(fn (string $sql) => (bool) preg_match($pattern, $sql));
        $order = $first('/from [`"]dine_orders[`"] where [`"]transaction_id[`"] = \?/');
        $this->assertIsInt($order);
        $sale = $first('/from [`"]transactions[`"] where [`"]transactions[`"]\.[`"]id[`"] = \?/', $order);
        $this->assertIsInt($sale, 'The sale must be locked after the dine order, the order pay() and the webhook use.');
        // the product lock of the restock, then the write of the settlement
        $this->assertLessThan($first('/from [`"]products[`"] where [`"]products[`"]\.[`"]id[`"] = \?/', $order), $sale);
        $this->assertLessThan($first('/^update [`"]sales_returns[`"]/'), $sale);

        // SQLite renders no lock clause: only the MySQL job can show it.
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', strtolower($queries[$sale]));
        }
    }

    /** The second writer reads what the first did: a payment committed while the return waited is refunded. */
    #[DataProvider('completions')]
    public function test_a_return_sees_a_payment_committed_while_it_waited(string $via): void
    {
        $bill = $this->bill($this->acceptedOrder());
        $return = $this->draftReturn($bill);
        // The counter payment holds the dine order, then the sale. It "commits" as the completion gets the dine order,
        // after the completion has already read the bill as unpaid.
        DineOrder::retrieved(fn () => DB::table('transactions')->where('id', $bill->id)
            ->update(['payment_status' => 'paid', 'payment_method' => 'cash']));

        $this->complete($via, $return);

        $this->assertSame(5000, (int) $return->fresh()->refund_amount);
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
