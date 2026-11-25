<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Profit;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scenarios for the reports and documents group (features 70-74).
 *
 * Reports sum every transaction in scope, so each call builds its own invoice prefix, category,
 * customer and products and the steps filter on those; absolute totals then stay exact on reruns.
 */
class Reports
{
    public const OTHER_OUTLET_CODE = 'E2E-B';

    public const OTHER_WAREHOUSE_CODE = 'WH-E2E-B';

    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Three completed sales with fixed amounts and a locked HPP (details.cost_total):
            //   A1 (E2E outlet, 2025-03-10, E2E Cashier):   revenue 35.000, 3 items, profit 18.000
            //   A2 (E2E outlet, 2025-03-20, E2E Manager, customer, discount 3.000): revenue 27.000, 3 items, profit 13.500
            //   B1 (second outlet, 2025-03-12, E2E Cashier): revenue 25.000, 1 item, profit 13.000
            // Afterwards the products' buy_price is raised: reports must keep using the locked HPP.
            'reports.sales' => function (array $options): array {
                $prefix = 'RPT'.strtoupper(Str::random(6));
                $warehouseA = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $warehouseB = self::otherWarehouse();

                $category = Category::create(['name' => "Kategori {$prefix}", 'description' => 'e2e reports']);
                $customer = Customer::create(['name' => "Pelanggan {$prefix}", 'no_telp' => '628'.random_int(100000000, 999999999), 'address' => 'Jl. Laporan 1']);
                $kopi = self::product($category, "Kopi {$prefix}", 10000, $warehouseA);
                $roti = self::product($category, "Roti {$prefix}", 15000, $warehouseA);
                $teh = self::product($category, "Teh {$prefix}", 25000, $warehouseB);

                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $manager = User::where('email', 'manager@e2e.test')->firstOrFail();

                $a1 = self::sale("{$prefix}-A1", '2025-03-10 10:15:00', $warehouseA, $cashier, null, 0, [[$kopi, 2, 20000, 9000], [$roti, 1, 15000, 8000]]);
                $a2 = self::sale("{$prefix}-A2", '2025-03-20 14:30:00', $warehouseA, $manager, $customer, 3000, [[$kopi, 3, 30000, 13500]]);
                $b1 = self::sale("{$prefix}-B1", '2025-03-12 09:00:00', $warehouseB, $cashier, null, 0, [[$teh, 1, 25000, 12000]]);

                Product::whereIn('id', [$kopi->id, $roti->id, $teh->id])->update(['buy_price' => 99999]);

                return [
                    'prefix' => $prefix,
                    'a1' => $a1->invoice,
                    'a2' => $a2->invoice,
                    'b1' => $b1->invoice,
                    'a1_token' => $a1->access_token,
                    'category' => $category->name,
                    'customer' => $customer->name,
                    'kopi' => $kopi->title,
                    'roti' => $roti->title,
                    'teh' => $teh->title,
                    'other_outlet' => self::OTHER_OUTLET_CODE.' - Outlet E2E B',
                ];
            },

            // A pay-later sale in the E2E outlet with its receivable (--arg=outlet=other puts it in the second outlet).
            'reports.receivable' => function (array $options): array {
                $warehouse = ($options['outlet'] ?? '') === 'other'
                    ? self::otherWarehouse()
                    : Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $customer = Customer::create(['name' => 'Pelanggan Piutang '.Str::upper(Str::random(5)), 'no_telp' => '628'.random_int(100000000, 999999999), 'address' => 'Jl. Piutang 2']);
                $product = self::product(Category::firstOrCreate(['name' => 'E2E Kategori']), 'Piutang '.Str::upper(Str::random(6)), 40000, $warehouse);
                $sale = self::sale('RCV'.strtoupper(Str::random(8)), '2025-04-01 08:00:00', $warehouse, $cashier, $customer, 0, [[$product, 1, 40000, 25000]], 'pay_later', 'unpaid');

                $receivable = Receivable::create([
                    'customer_id' => $customer->id,
                    'transaction_id' => $sale->id,
                    'invoice' => $sale->invoice,
                    'total' => 40000,
                    'paid' => 0,
                    'due_date' => '2025-04-30',
                    'status' => 'unpaid',
                ]);

                return ['receivable_id' => $receivable->id, 'invoice' => $sale->invoice, 'customer' => $customer->name];
            },

            // A supplier payable from a purchase order in the E2E outlet, and one entered by hand (no purchase order).
            'reports.payable' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $supplier = Supplier::create(['name' => 'Pemasok '.Str::upper(Str::random(5)), 'phone' => '628'.random_int(100000000, 999999999), 'address' => 'Jl. Pemasok 3']);
                $suffix = Str::upper(Str::random(6));

                $order = PurchaseOrder::create([
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $warehouse->id,
                    'document_number' => "PO-RPT-{$suffix}",
                    'status' => 'completed',
                ]);
                $fromOrder = Payable::create([
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => $order->id,
                    'document_number' => "HTG-RPT-{$suffix}",
                    'total' => 150000,
                    'paid' => 0,
                    'due_date' => '2025-05-15',
                    'status' => 'unpaid',
                ]);
                $manual = Payable::create([
                    'supplier_id' => $supplier->id,
                    'document_number' => "HTG-MAN-{$suffix}",
                    'total' => 70000,
                    'paid' => 0,
                    'due_date' => '2025-05-15',
                    'status' => 'unpaid',
                ]);

                return [
                    'payable_id' => $fromOrder->id,
                    'document_number' => $fromOrder->document_number,
                    'manual_payable_id' => $manual->id,
                    'manual_document_number' => $manual->document_number,
                    'supplier' => $supplier->name,
                ];
            },
        ];
    }

    /** The second outlet and its warehouse; created once and reused. */
    private static function otherWarehouse(): Warehouse
    {
        $outlet = Outlet::firstOrCreate(
            ['code' => self::OTHER_OUTLET_CODE],
            ['name' => 'Outlet E2E B', 'is_active' => true, 'is_sales_enabled' => true],
        );

        return Warehouse::firstOrCreate(
            ['code' => self::OTHER_WAREHOUSE_CODE],
            ['outlet_id' => $outlet->id, 'name' => 'Gudang E2E B', 'type' => 'branch', 'is_active' => true, 'sort_order' => 2],
        );
    }

    private static function product(Category $category, string $title, int $price, Warehouse $warehouse): Product
    {
        return DB::transaction(function () use ($category, $title, $price, $warehouse) {
            $product = Product::create([
                'category_id' => $category->id,
                'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                'title' => $title,
                'buy_price' => $price / 2,
                'sell_price' => $price,
                'stock' => 20,
                'tax_type' => 'exclusive',
                'tax_rate' => 0,
            ]);
            $warehouse->products()->attach($product->id, ['stock' => 20]);

            return $product;
        });
    }

    /**
     * A completed sale written the way CheckoutService writes it: one detail per line with the HPP locked
     * in cost_total, one profit row per line (line price minus the line's share of the manual discount,
     * minus cost_total). Stock is left alone: the reports never read it for these totals.
     *
     * @param  array<int, array{0: Product, 1: int, 2: int, 3: int}>  $lines  product, qty, line price, line cost
     */
    private static function sale(string $invoice, string $at, Warehouse $warehouse, User $cashier, ?Customer $customer, int $discount, array $lines, string $method = 'cash', string $status = 'paid'): Transaction
    {
        $subtotal = array_sum(array_column($lines, 2));
        $grand = $subtotal - $discount;

        $transaction = new Transaction([
            'cashier_id' => $cashier->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer?->id,
            'invoice' => $invoice,
            'cash' => $method === 'cash' ? $grand : 0,
            'change' => 0,
            'discount' => $discount,
            'shipping_cost' => 0,
            'grand_total' => $grand,
            'payment_method' => $method,
            'payment_status' => $status,
            'tax_rate' => 0,
            'tax_total' => 0,
        ]);
        $transaction->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        foreach ($lines as [$product, $qty, $price, $cost]) {
            $transaction->details()->create([
                'product_id' => $product->id,
                'conversion_factor' => 1,
                'qty' => $qty,
                'base_unit_price' => intdiv($price, $qty),
                'unit_price' => intdiv($price, $qty),
                'price' => $price,
                'discount_total' => 0,
                'cost_total' => $cost,
            ]);
            $net = $price - (int) round($discount * $price / $subtotal);
            Profit::create(['transaction_id' => $transaction->id, 'total' => $net - $cost]);
        }

        return $transaction->fresh();
    }
}
