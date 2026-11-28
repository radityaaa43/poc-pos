<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OfflineSaleDiscardTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '7b0d0f4e-9a43-4f7e-9a55-2f9a3c1d8b10';

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo('transactions-access');
    }

    public function test_discard_writes_audit_with_the_sale_content(): void
    {
        $outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Gudang', 'status' => 'active', 'outlet_id' => $outlet->id]);
        $shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $warehouse->id);

        $payload = $this->payload();
        $payload['sale']['cart_ids'] = [8]; // not part of the contract
        $payload['sale']['items'][0]['price'] = 1;
        $payload['server_invoice'] = 'TRX-FORGED';

        $response = $this->actingAs($this->cashier)
            ->postJson(route('transactions.offline-discard'), $payload)
            ->assertCreated();

        $audit = AuditLog::where('event', 'offline_sale.discarded')->sole();
        $response->assertJsonPath('data.audit_id', $audit->id);
        $this->assertSame($this->cashier->id, (int) $audit->user_id);
        $this->assertSame('transactions', $audit->module);
        $this->assertSame('Transaksi offline dihapus dari antrean perangkat.', $audit->description);
        $this->assertNull($audit->auditable_id);
        $this->assertNotNull($audit->created_at);
        // assertEquals: key order is not part of the contract.
        $this->assertEquals([
            ...$this->payload(),
            'server_invoice' => null,
            'cashier_shift_id' => $shift->id,
        ], $audit->meta);
        $this->assertSame(0, Transaction::count());
    }

    public function test_conflict_discard_links_the_existing_sale(): void
    {
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id,
            'invoice' => 'TRX-OFFLINE01',
            'cash' => 25000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 25000,
            'client_uuid' => self::UUID,
        ]);

        $this->actingAs($this->cashier)
            ->postJson(route('transactions.offline-discard'), [...$this->payload(), 'queue_status' => 'conflict'])
            ->assertCreated();

        $audit = AuditLog::where('event', 'offline_sale.discarded')->sole();
        $this->assertSame($sale->getMorphClass(), $audit->auditable_type);
        $this->assertSame($sale->id, (int) $audit->auditable_id);
        $this->assertSame('TRX-OFFLINE01', $audit->meta['server_invoice']);
        $this->assertNull($audit->meta['cashier_shift_id']);
        // Removing the device's copy never touches the sale on the server.
        $this->assertSame(1, Transaction::count());
        $this->assertSame(25000, (int) $sale->fresh()->grand_total);
    }

    public function test_discard_validates_the_payload(): void
    {
        $item = ['product_id' => 1, 'unit_id' => null, 'qty' => 1];
        $cases = [
            'client_uuid' => ['client_uuid', 'not-a-uuid'],
            'queue_status' => ['queue_status', 'pending'],
            'reason' => ['reason', str_repeat('x', 501)],
            'attempts' => ['attempts', -1],
            'queued_at' => ['queued_at', 'yesterday-ish'],
            'sale' => ['sale', null],
            'sale.grand_total' => ['sale.grand_total', 'much'],
            'sale.payment_method' => ['sale.payment_method', str_repeat('x', 31)],
            'sale.customer_id' => ['sale.customer_id', 'Budi'],
            'sale.items' => ['sale.items', array_fill(0, 201, $item)],
            'sale.items.0.product_id' => ['sale.items.0.product_id', null],
            'sale.items.0.unit_id' => ['sale.items.0.unit_id', 'pcs'],
            'sale.items.0.qty' => ['sale.items.0.qty', 0],
            'sale.customer_voucher_id' => ['sale.customer_voucher_id', 'seven'],
            'sale.discount' => ['sale.discount', 'much'],
            'sale.redeem_points' => ['sale.redeem_points', 1.0E+20],
            'sale.shipping_cost' => ['sale.shipping_cost', 'far'],
            'sale.cash' => ['sale.cash', str_repeat('9', 400)],
            'sale.bank_account_id' => ['sale.bank_account_id', 'BCA'],
            'sale.order_type' => ['sale.order_type', str_repeat('x', 31)],
            'sale.note' => ['sale.note', str_repeat('x', 1001)],
            'sale.pay_later' => ['sale.pay_later', 'maybe'],
            'sale.due_date' => ['sale.due_date', str_repeat('1', 31)],
        ];

        foreach ($cases as $field => [$path, $value]) {
            $payload = $this->payload();
            Arr::set($payload, $path, $value);

            $this->actingAs($this->cashier)
                ->postJson(route('transactions.offline-discard'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, AuditLog::where('event', 'offline_sale.discarded')->count());
    }

    public function test_discard_requires_transactions_access(): void
    {
        $this->postJson(route('transactions.offline-discard'), $this->payload())->assertUnauthorized();

        $other = User::factory()->create();
        $other->markEmailAsVerified();
        $this->actingAs($other)
            ->postJson(route('transactions.offline-discard'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, AuditLog::where('event', 'offline_sale.discarded')->count());
    }

    private function payload(): array
    {
        return [
            'client_uuid' => self::UUID,
            'queue_status' => 'failed',
            'reason' => 'Stok tidak cukup.',
            'attempts' => 2,
            'queued_at' => '2026-11-28T03:15:00.000Z',
            'sale' => [
                'grand_total' => 25000,
                'payment_method' => 'cash',
                'customer_id' => null,
                'customer_voucher_id' => 7,
                'discount' => 1000,
                'redeem_points' => 5,
                'shipping_cost' => 5000,
                'cash' => 30000,
                'bank_account_id' => null,
                'order_type' => 'delivery',
                'note' => 'Antar sore',
                'pay_later' => false,
                'due_date' => null,
                'items' => [['product_id' => 12, 'unit_id' => 1, 'qty' => 2]],
            ],
        ];
    }
}
