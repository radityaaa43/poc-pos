<?php

namespace Tests\Feature\SalesReturn;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_authorized_user_can_create_sales_return_draft_for_cash_transaction(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
        ]);

        [$transaction, $detail] = $this->createTransaction($user);

        $response = $this
            ->actingAs($user)
            ->post(route('sales-returns.store', $transaction), [
                'return_type' => 'refund_cash',
                'notes' => 'Retur karena salah ukuran',
                'items' => [
                    [
                        'transaction_detail_id' => $detail->id,
                        'qty_return' => 1,
                        'return_reason' => 'Salah ukuran',
                        'restock_to_inventory' => true,
                    ],
                ],
            ]);

        $salesReturn = SalesReturn::first();

        $response->assertRedirect(route('sales-returns.show', $salesReturn));
        $this->assertNotNull($salesReturn);
        $this->assertSame('draft', $salesReturn->status);
        $this->assertSame(1, $salesReturn->items()->count());
        $this->assertSame(60000, $salesReturn->total_return_amount);
        $this->assertSame(60000, $salesReturn->refund_amount);
    }

    public function test_partial_return_refunds_only_the_returned_quantity(): void
    {
        $user = $this->createUserWithPermissions(['transactions-access', 'sales-returns-access', 'sales-returns-create']);
        [$transaction, $detail] = $this->createTransaction($user, qty: 3);

        $this->actingAs($user)->post(route('sales-returns.store', $transaction), [
            'return_type' => 'refund_cash',
            'items' => [[
                'transaction_detail_id' => $detail->id,
                'qty_return' => 1,
                'return_reason' => 'Salah ukuran',
                'restock_to_inventory' => true,
            ]],
        ]);

        $salesReturn = SalesReturn::first();
        $this->assertSame(60000, $salesReturn->total_return_amount);
        $this->assertSame(60000, $salesReturn->refund_amount);
        $this->assertSame(60000, $salesReturn->items->first()->unit_price);
        $this->assertSame(60000, $salesReturn->items->first()->subtotal);
    }

    public function test_partial_return_on_a_pay_later_sale_lowers_the_receivable_by_the_returned_quantity(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access', 'sales-returns-access', 'sales-returns-create', 'sales-returns-complete',
            'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close',
        ]);
        [$transaction, $detail] = $this->createTransaction(
            $user, qty: 3, paymentMethod: 'pay_later', paymentStatus: 'unpaid', withReceivable: true
        );
        $transaction->receivable->update(['paid' => 0, 'status' => 'unpaid']);
        $this->openShiftFor($user);

        $this->actingAs($user)->post(route('sales-returns.store', $transaction), [
            'return_type' => 'refund_cash',
            'items' => [[
                'transaction_detail_id' => $detail->id,
                'qty_return' => 1,
                'return_reason' => 'Salah ukuran',
                'restock_to_inventory' => true,
            ]],
        ]);
        $this->actingAs($user)->post(route('sales-returns.complete', SalesReturn::first()));

        $this->assertEquals(120000, $transaction->receivable->fresh()->total);
        $this->assertSame(0, SalesReturn::first()->refund_amount);
    }

    public function test_repeated_partial_returns_of_a_part_paid_pay_later_sale_never_refund_more_than_was_paid(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access', 'sales-returns-access', 'sales-returns-create', 'sales-returns-complete',
            'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close',
        ]);
        [$transaction, $detail] = $this->createTransaction(
            $user, qty: 3, paymentMethod: 'pay_later', paymentStatus: 'unpaid', withReceivable: true
        );
        $transaction->receivable->update(['paid' => 100000, 'status' => 'partial']);
        $this->openShiftFor($user);

        $refunds = [];
        $totals = [];
        foreach ([1, 2, 3] as $unused) {
            $this->actingAs($user)->post(route('sales-returns.store', $transaction), [
                'return_type' => 'refund_cash',
                'items' => [[
                    'transaction_detail_id' => $detail->id,
                    'qty_return' => 1,
                    'return_reason' => 'Salah ukuran',
                    'restock_to_inventory' => true,
                ]],
            ]);
            $salesReturn = SalesReturn::latest('id')->first();
            $this->actingAs($user)->post(route('sales-returns.complete', $salesReturn));

            $refunds[] = (int) $salesReturn->fresh()->refund_amount;
            $totals[] = (int) $transaction->receivable->fresh()->total;
        }

        // Receivable 180.000, paid 100.000: it only starts to overpay once the total drops under 100.000.
        $this->assertSame([120000, 60000, 0], $totals);
        $this->assertSame([0, 40000, 60000], $refunds);
        $this->assertSame('paid', $transaction->receivable->fresh()->status);
    }

    public function test_return_amounts_of_a_line_never_add_up_to_more_than_the_line_total(): void
    {
        $detail = new TransactionDetail(['qty' => 3, 'price' => 100]);

        // 100 / 3 does not divide: the three single returns must still add up to 100.
        $this->assertSame(100, $detail->returnAmount(0, 1) + $detail->returnAmount(1, 1) + $detail->returnAmount(2, 1));
        $this->assertSame(100, $detail->returnAmount(0, 3));
    }

    public function test_qty_return_cannot_exceed_remaining_quantity(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        [$transaction, $detail, $product] = $this->createTransaction($user, qty: 2);

        $firstReturn = SalesReturn::create([
            'code' => 'SR-TEST-001',
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $user->id,
            'status' => 'completed',
            'return_type' => 'refund_cash',
            'refund_amount' => 60000,
            'credited_amount' => 0,
            'total_return_amount' => 60000,
            'completed_at' => now(),
        ]);

        $firstReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 2,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Retur pertama',
            'restock_to_inventory' => true,
        ]);

        $response = $this
            ->from(route('sales-returns.create', $transaction))
            ->actingAs($user)
            ->post(route('sales-returns.store', $transaction), [
                'return_type' => 'refund_cash',
                'items' => [
                    [
                        'transaction_detail_id' => $detail->id,
                        'qty_return' => 2,
                        'return_reason' => 'Melebihi sisa',
                        'restock_to_inventory' => true,
                    ],
                ],
            ]);

        $response->assertInvalid(['items']);
        $this->assertDatabaseCount('sales_returns', 1);
    }

    public function test_complete_sales_return_restocks_product_and_creates_mutation(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        [$transaction, $detail, $product] = $this->createTransaction($user, qty: 1, stock: 4);
        $shift = $this->openShiftFor($user);
        $warehouse = Warehouse::create([
            'code' => 'RET',
            'name' => 'Gudang Retur',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $transaction->update(['warehouse_id' => $warehouse->id]);

        $salesReturn = SalesReturn::create([
            'code' => 'SR-TEST-002',
            'transaction_id' => $transaction->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $user->id,
            'status' => 'draft',
            'return_type' => 'refund_cash',
            'refund_amount' => 60000,
            'credited_amount' => 0,
            'total_return_amount' => 60000,
        ]);

        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn));

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('sales_returns', [
            'id' => $salesReturn->id,
            'status' => 'completed',
            'refund_amount' => 60000,
            'cashier_shift_id' => $shift->id,
        ]);
        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $product->id,
            'reference_type' => 'sales_return',
            'reference_id' => $salesReturn->id,
            'mutation_type' => 'in',
            'qty' => 1,
            'stock_before' => 4,
            'stock_after' => 5,
            'warehouse_id' => $warehouse->id,
        ]);
        $this->assertDatabaseHas('profits', [
            'transaction_id' => $transaction->id,
            'total' => -15000,
        ]);
    }

    public function test_complete_sales_return_updates_receivable_total_and_creates_credit_on_overpayment(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        [$transaction, $detail, $product, $customer] = $this->createTransaction(
            $user,
            qty: 1,
            stock: 3,
            paymentMethod: 'pay_later',
            paymentStatus: 'paid',
            withReceivable: true
        );
        $shift = $this->openShiftFor($user);

        $salesReturn = SalesReturn::create([
            'code' => 'SR-TEST-003',
            'transaction_id' => $transaction->id,
            'customer_id' => $customer->id,
            'cashier_id' => $user->id,
            'status' => 'draft',
            'return_type' => 'store_credit',
            'refund_amount' => 0,
            'credited_amount' => 60000,
            'total_return_amount' => 60000,
        ]);

        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Salah kirim',
            'restock_to_inventory' => true,
        ]);

        $this->actingAs($user)->post(route('sales-returns.complete', $salesReturn));

        $this->assertDatabaseHas('receivables', [
            'transaction_id' => $transaction->id,
            'total' => 0,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('customer_credits', [
            'customer_id' => $customer->id,
            'sales_return_id' => $salesReturn->id,
            'amount' => 60000,
            'balance' => 60000,
        ]);
        $this->assertDatabaseHas('sales_returns', [
            'id' => $salesReturn->id,
            'cashier_shift_id' => $shift->id,
        ]);
    }

    public function test_transaction_without_customer_forces_refund_cash(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
        ]);

        [$transaction, $detail] = $this->createTransaction($user, withCustomer: false);

        $this->actingAs($user)->post(route('sales-returns.store', $transaction), [
            'return_type' => 'store_credit',
            'items' => [
                [
                    'transaction_detail_id' => $detail->id,
                    'qty_return' => 1,
                    'return_reason' => 'Barang dibatalkan',
                    'restock_to_inventory' => true,
                ],
            ],
        ]);

        $salesReturn = SalesReturn::first();

        $this->assertSame('refund_cash', $salesReturn->return_type);
    }

    public function test_completed_sales_return_cannot_be_updated(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
        ]);

        [$transaction, $detail, $product] = $this->createTransaction($user);

        $salesReturn = SalesReturn::create([
            'code' => 'SR-TEST-004',
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $user->id,
            'status' => 'completed',
            'return_type' => 'refund_cash',
            'refund_amount' => 60000,
            'credited_amount' => 0,
            'total_return_amount' => 60000,
            'completed_at' => now(),
        ]);

        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 60000,
            'subtotal' => 60000,
            'return_reason' => 'Tidak jadi beli',
            'restock_to_inventory' => true,
        ]);

        $response = $this
            ->from(route('sales-returns.show', $salesReturn))
            ->actingAs($user)
            ->patch(route('sales-returns.update', $salesReturn), [
                'return_type' => 'refund_cash',
                'items' => [
                    [
                        'transaction_detail_id' => $detail->id,
                        'qty_return' => 1,
                        'return_reason' => 'Ubah alasan',
                        'restock_to_inventory' => true,
                    ],
                ],
            ]);

        $response->assertInvalid(['sales_return']);
    }

    public function test_complete_sales_return_restocks_base_units_for_multi_unit_product(): void
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        // One box = 12 base units; stock currently 4 base units.
        [$transaction, $detail, $product] = $this->createTransaction($user, qty: 1, stock: 4, conversionFactor: 12);
        $this->openShiftFor($user);
        $warehouse = Warehouse::create([
            'code' => 'RET-MU',
            'name' => 'Gudang Retur Multi',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $transaction->update(['warehouse_id' => $warehouse->id]);

        $salesReturn = SalesReturn::create([
            'code' => 'SR-TEST-MU',
            'transaction_id' => $transaction->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $user->id,
            'status' => 'draft',
            'return_type' => 'refund_cash',
            'refund_amount' => 720000,
            'credited_amount' => 0,
            'total_return_amount' => 720000,
        ]);

        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 1,
            'qty_returned_before' => 0,
            'qty_return' => 1,
            'unit_price' => 720000,
            'subtotal' => 720000,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        $this
            ->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        // 4 + (1 box x 12) = 16
        $this->assertSame(16, $product->fresh()->stock);

        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $product->id,
            'reference_type' => 'sales_return',
            'reference_id' => $salesReturn->id,
            'mutation_type' => 'in',
            'qty' => 12,
            'stock_before' => 4,
            'stock_after' => 16,
        ]);

        // Margin reversal: (720000 - 45000*12) x 1 = 180000
        $this->assertDatabaseHas('profits', [
            'transaction_id' => $transaction->id,
            'total' => -180000,
        ]);
    }

    public function test_complete_sales_return_reverses_profit_with_the_frozen_hpp(): void
    {
        [$user, $transaction, $salesReturn] = $this->createPartialReturnDraft();

        $this->actingAs($user)
            ->post(route('sales-returns.complete', $salesReturn))
            ->assertSessionDoesntHaveErrors();

        // Frozen unit cost 99000 / 3 = 33000, not the current buy_price (90000): (60000 - 33000) x 2.
        $this->assertDatabaseHas('profits', [
            'transaction_id' => $transaction->id,
            'total' => -54000,
        ]);
    }

    public function test_api_complete_sales_return_reverses_profit_with_the_frozen_hpp(): void
    {
        [$user, $transaction, $salesReturn] = $this->createPartialReturnDraft();

        Sanctum::actingAs($user, ['*']);
        $this->postJson(route('api.sales-returns.complete', $salesReturn))->assertOk();

        $this->assertDatabaseHas('profits', [
            'transaction_id' => $transaction->id,
            'total' => -54000,
        ]);
    }

    /**
     * Draft returning 2 of 3 units; the line froze cost_total 99000 and the product's buy_price has since moved.
     */
    private function createPartialReturnDraft(): array
    {
        $user = $this->createUserWithPermissions([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
        ]);

        [$transaction, $detail, $product] = $this->createTransaction($user, qty: 3);
        $detail->update(['price' => 180000, 'cost_total' => 99000]);
        $product->update(['buy_price' => 90000]);
        $this->openShiftFor($user);

        $salesReturn = SalesReturn::create([
            'code' => 'SR-TEST-HPP',
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $user->id,
            'status' => 'draft',
            'return_type' => 'refund_cash',
            'refund_amount' => 120000,
            'credited_amount' => 0,
            'total_return_amount' => 120000,
        ]);

        $salesReturn->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $product->id,
            'qty_sold' => 3,
            'qty_returned_before' => 0,
            'qty_return' => 2,
            'unit_price' => 60000,
            'subtotal' => 120000,
            'return_reason' => 'Barang dikembalikan',
            'restock_to_inventory' => true,
        ]);

        return [$user, $transaction, $salesReturn];
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function createTransaction(
        User $user,
        int $qty = 1,
        int $stock = 10,
        bool $withCustomer = true,
        string $paymentMethod = 'cash',
        string $paymentStatus = 'paid',
        bool $withReceivable = false,
        int $conversionFactor = 1
    ): array {
        $category = Category::create([
            'name' => 'Kategori '.Str::upper(Str::random(5)),
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji '.Str::upper(Str::random(4)),
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => $stock,
            'tax_rate' => 0,
        ]);

        $customer = $withCustomer
            ? Customer::create([
                'name' => 'Customer Test',
                'no_telp' => '08123456789',
                'address' => 'Jalan Test',
            ])
            : null;

        $transaction = Transaction::create([
            'cashier_id' => $user->id,
            'cashier_shift_id' => null,
            'customer_id' => $customer?->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => 60000 * $qty,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 60000 * $qty,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'payment_reference' => null,
            'payment_url' => null,
            'bank_account_id' => null,
        ]);

        $detail = $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => $qty,
            'conversion_factor' => $conversionFactor,
            // price is the line total (CheckoutService), not the unit price.
            'price' => 60000 * $conversionFactor * $qty,
        ]);

        $transaction->profits()->create([
            'total' => 15000 * $qty,
        ]);

        if ($withReceivable) {
            Receivable::create([
                'customer_id' => $customer?->id,
                'transaction_id' => $transaction->id,
                'invoice' => $transaction->invoice,
                'total' => $transaction->grand_total,
                'paid' => $transaction->grand_total,
                'due_date' => now()->addDays(7),
                'status' => 'paid',
            ]);
        }

        return [$transaction->fresh(['details', 'receivable']), $detail, $product, $customer];
    }

    private function openShiftFor(User $user)
    {
        return CashierShift::create([
            'user_id' => $user->id,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }
}
