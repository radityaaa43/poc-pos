<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * #444: a return completed while a payment waits lowers what the bill owes. A payment of the old amount pays the bill,
 * and the excess is flagged for a manual refund (bill 25000, 5000 returned while waiting).
 */
class OverpaidAfterReturnTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
        $this->seed(PermissionSeeder::class);
        $this->cashier->givePermissionTo(['sales-returns-create', 'sales-returns-complete', 'transactions-confirm-payment']);
    }

    #[DataProvider('gateways')]
    public function test_a_guest_charge_paid_at_the_old_total_after_a_return_is_flagged(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk()->assertJsonPath('payment.amount', self::TOTAL);
        $this->returnFromBill($this->bill($order), $this->plain);
        $orderId = $this->bill($order)->invoice.'-r1';

        $this->notify($gateway, $orderId, 'paid', self::TOTAL)->assertOk();

        $bill = $this->bill($order);
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(5000, (int) $bill->overpaid_amount);
        $meta = AuditLog::where('event', 'payment.overpaid_after_return')->sole()->meta;
        $this->assertSame(
            [self::TOTAL, 20000, 5000, 'gateway', $orderId],
            [$meta['settled'], $meta['owed'], $meta['excess'], $meta['source'], $meta['order_id']],
        );
    }
}
