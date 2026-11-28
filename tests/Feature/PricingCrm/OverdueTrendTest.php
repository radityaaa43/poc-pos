<?php

namespace Tests\Feature\PricingCrm;

use App\Models\Outlet;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// REQ-007 "Piutang macet": the overdue total at each month end, measured as it stood then.
class OverdueTrendTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:00:00');
        $outlet = Outlet::create(['code' => 'OUT', 'name' => 'Outlet']);
        $this->warehouse = Warehouse::create(['code' => 'WH', 'name' => 'Gudang', 'type' => 'branch', 'is_active' => true, 'sort_order' => 0, 'outlet_id' => $outlet->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credit(int $total, string $dueDate, ?string $createdAt = null): Receivable
    {
        $transaction = Transaction::create([
            'warehouse_id' => $this->warehouse->id, 'invoice' => 'TRX-'.uniqid(), 'cash' => 0, 'change' => 0,
            'discount' => 0, 'shipping_cost' => 0, 'grand_total' => $total,
            'payment_method' => 'pay_later', 'payment_status' => 'pending', 'is_pay_later' => true,
        ]);
        $receivable = Receivable::create([
            'transaction_id' => $transaction->id, 'invoice' => $transaction->invoice,
            'due_date' => $dueDate, 'total' => $total, 'paid' => 0, 'status' => 'unpaid',
        ]);
        $receivable->timestamps = false;
        $receivable->forceFill(['created_at' => $createdAt ?? $dueDate])->save();

        return $receivable;
    }

    private function month(string $month): array
    {
        return collect(app(ReceivableService::class)->overdueTrend(collect([$this->warehouse->id])))
            ->firstWhere('month', $month);
    }

    public function test_due_today_is_not_overdue_but_due_yesterday_is(): void
    {
        $this->credit(1000, '2026-06-15'); // due today
        $this->credit(2000, '2026-06-14'); // due yesterday

        $june = $this->month('2026-06');

        $this->assertSame(2000, $june['overdue']);
        $this->assertSame(1, $june['count']);
    }

    public function test_a_payment_after_the_month_end_does_not_reduce_the_past_month(): void
    {
        $receivable = $this->credit(1000, '2026-05-01');
        ReceivablePayment::create(['receivable_id' => $receivable->id, 'paid_at' => '2026-06-10', 'amount' => 1000, 'method' => 'transfer']);
        $receivable->timestamps = false;
        $receivable->forceFill(['paid' => 1000])->save(); // the payment above, as the app records it

        $may = $this->month('2026-05');
        $june = $this->month('2026-06');

        $this->assertSame(1000, $may['overdue']); // still owed at May end
        $this->assertSame(0, $june['overdue']);   // paid before the June snapshot
    }

    public function test_a_receivable_created_after_the_month_end_is_not_counted_then(): void
    {
        $this->credit(5000, '2026-04-01', createdAt: '2026-06-01');

        $this->assertSame(0, $this->month('2026-04')['overdue']);
        $this->assertSame(5000, $this->month('2026-06')['overdue']);
    }
}
