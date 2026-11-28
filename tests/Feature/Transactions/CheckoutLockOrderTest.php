<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCreditData;
use Tests\TestCase;

/** #494: every customer checkout locks the customer row after the shift and before the first stock row. */
class CheckoutLockOrderTest extends TestCase
{
    use MakesCreditData, RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Gudang 1', 'status' => 'active', 'outlet_id' => $this->outlet->id]);
        $this->product = Product::create([
            'title' => 'Produk Kasir',
            'barcode' => 'CL-001',
            'sku' => 'SKU-CL-001',
            'image' => '',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 200,
            'category_id' => Category::create(['name' => 'Kategori', 'image' => '', 'description' => ''])->id,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 200]);

        $this->cashier = $this->userWith('transactions-access', 'cashier-shifts-access');
        $this->cashier->markEmailAsVerified();
        app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 100000,
            notes: null,
            warehouseId: $this->warehouse->id,
        );
    }

    /** A paid cash sale for `$customer` (null = walk-in), returning the lock steps taken inside the checkout transaction. */
    private function lockSteps(?Customer $customer): array
    {
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
        ]);

        $steps = [];
        $outer = DB::transactionLevel();
        DB::listen(function ($query) use (&$steps) {
            // Anchored at the start: the request also runs summary subqueries that mention these tables.
            $patterns = [
                'customer' => '/^select \* from [`"]customers[`"] where/i',
                'stock' => '/^select \* from [`"]product_warehouse[`"]/i',
            ];
            foreach ($patterns as $step => $pattern) {
                if (preg_match($pattern, $query->sql)) {
                    $steps[] = [$step, DB::transactionLevel(), $query->sql];
                }
            }
        });

        $this->actingAs($this->cashier)
            ->from(route('transactions.index'))
            ->post(route('transactions.store'), ['cash' => 10000, 'discount' => 0, 'customer_id' => $customer?->id])
            ->assertSessionHasNoErrors();

        return array_values(array_filter($steps, fn (array $step) => $step[1] === $outer + 1));
    }

    public function test_paid_sale_locks_the_customer_before_stock(): void
    {
        $budi = $this->customer();
        $inside = $this->lockSteps($budi);
        $order = array_column($inside, 0);

        $customerAt = array_search('customer', $order, true);
        $this->assertNotFalse($customerAt, 'the customer row is read');
        $stockAt = array_search('stock', $order, true);
        $this->assertNotFalse($stockAt, 'a stock row is locked in the same transaction');
        $this->assertLessThan($stockAt, $customerAt, 'the customer is locked before the first stock row');
        if (DB::getDriverName() === 'mysql') {
            $locked = false;
            foreach ($inside as $i => [$step, , $sql]) {
                if ($step === 'customer' && $i < $stockAt && str_contains($sql, 'for update')) {
                    $locked = true;
                }
            }
            $this->assertTrue($locked, 'a customer select before the stock lock takes for update');
        }
    }

    public function test_no_customer_no_lock(): void
    {
        $inside = $this->lockSteps(null);

        $this->assertNotContains('customer', array_column($inside, 0), 'a walk-in sale locks no customer');
    }
}
