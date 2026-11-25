<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GoodsReceiving;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\GoodsReceivingService;
use App\Services\PurchaseOrderService;
use App\Services\ReorderService;
use App\Services\StockMutationService;
use App\Services\SupplierReturnService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arrange scenarios of the purchasing group (REQ-002): raw materials with a kg purchase unit,
 * suppliers, purchase orders, goods receivings and the reorder drafts. Names start with `purchasing.`.
 */
class Purchasing
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // A raw material in grams, bought in kg (1 kg = 1000 g) at --arg=price=<Rp per kg, default 250000>.
            // --arg=title, --arg=purchased_by=central|outlet (default central), --arg=min_stock, --arg=max_stock.
            // Stock per warehouse (grams) at an average cost per gram (default: the reference price):
            // --arg=stock_outlet / avg_outlet (WH-E2E), --arg=stock_central / avg_central (PUSAT).
            // --arg=opening=1 records the stock as an opening-stock mutation (a material that may still be deleted).
            // --arg=recipe=1 uses it (18 g) as the component of a new recipe "Resep <title>" sold at Rp 20.000.
            'purchasing.material' => fn (array $options): array => self::material($options),

            // A plain product bought in cartons of 24 pcs, the way products were before purchase prices per unit existed:
            // the carton has no price of its own, so the default is the pcs buy price (Rp 5.000) x 24.
            'purchasing.carton-product' => function (array $options): array {
                $product = Product::create([
                    'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => $options['title'] ?? 'Produk Karton '.Str::upper(Str::random(5)),
                    'buy_price' => 5000,
                    'sell_price' => 10000,
                    'stock' => 0,
                    'tax_type' => 'exclusive',
                    'tax_rate' => 0,
                ]);
                $product->units()->sync([
                    Unit::where('code', 'PCS')->value('id') => ['is_base' => true, 'is_purchase_default' => false, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000],
                    Unit::where('code', 'KARTON')->value('id') => ['is_base' => false, 'is_purchase_default' => false, 'conversion_factor' => 24, 'buy_price' => 0, 'sell_price' => 0],
                ]);

                return ['product_id' => $product->id, 'title' => $product->title];
            },

            // A plain product sold at Rp 10.000 whose WH-E2E average cost (--arg=avg=<default 4000>) differs from its buy price (Rp 5.000).
            'purchasing.plain-product' => function (array $options): array {
                $product = Product::create([
                    'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => $options['title'] ?? 'Produk Biasa '.Str::upper(Str::random(5)),
                    'buy_price' => 5000,
                    'sell_price' => 10000,
                    'stock' => 10,
                    'tax_type' => 'exclusive',
                    'tax_rate' => 0,
                ]);
                self::warehouse(E2eSeeder::WAREHOUSE_CODE)->products()->attach($product->id, ['stock' => 10, 'avg_cost' => $options['avg'] ?? 4000]);

                return ['product_id' => $product->id, 'title' => $product->title];
            },

            // A till ready to sell: the cashier's cart emptied (carts are kept per cashier between steps), a shift
            // open in WH-E2E, and a customer to pick (the till asks for one): --arg=name=<text> (unique default).
            'purchasing.till' => function (array $options): array {
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                Cart::where('cashier_id', $cashier->id)->delete();
                CashierShift::open()->where('user_id', $cashier->id)->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);
                app(CashierShiftService::class)->openShift($cashier, $cashier, 100000, null, self::warehouse(E2eSeeder::WAREHOUSE_CODE)->id);
                $customer = Customer::create(['name' => $options['name'] ?? 'Pelanggan E2E '.Str::upper(Str::random(5)), 'no_telp' => '08'.random_int(100000000, 999999999), 'address' => 'Jl. Uji 1']);

                return ['customer_id' => $customer->id, 'name' => $customer->name];
            },

            // --arg=name=<text> (unique default).
            'purchasing.supplier' => function (array $options): array {
                $supplier = Supplier::create(['name' => $options['name'] ?? 'Supplier E2E '.Str::upper(Str::random(5))]);

                return ['supplier_id' => $supplier->id, 'name' => $supplier->name];
            },

            // A purchase order of one material: --arg=material_id, --arg=supplier_id (created when missing),
            // --arg=qty=<kg, default 5>, --arg=price=<Rp per kg, default 250000>, --arg=warehouse=WH-E2E|PUSAT (default: PUSAT for a central-bought material, else WH-E2E; outlets cannot order central-bought ones),
            // --arg=status=draft|ordered (default ordered), --arg=receive=<kg> receives that much through the goods receiving service.
            'purchasing.po' => fn (array $options): array => self::purchaseOrder($options),

            // A supplier return of --arg=qty=<kg, default 1> of the first line of --arg=gr_id; --arg=complete=1 also completes it.
            'purchasing.return' => function (array $options): array {
                $receiving = GoodsReceiving::with('items')->findOrFail($options['gr_id']);
                $line = $receiving->items->first();
                $user = User::where('email', 'superadmin@e2e.test')->firstOrFail();

                $return = app(SupplierReturnService::class)->createReturn(
                    ['supplier_id' => $receiving->supplier_id, 'goods_receiving_id' => $receiving->id],
                    [['goods_receiving_item_id' => $line->id, 'product_id' => $line->product_id, 'qty_returned' => (int) ($options['qty'] ?? 1), 'reason' => 'Rusak']],
                    $user->id,
                );
                if (! empty($options['complete'])) {
                    app(SupplierReturnService::class)->complete($return, $user->id);
                }

                return ['sr_id' => $return->id, 'document_number' => $return->document_number];
            },

            // Three materials and a plain product that all fall below their minimum in both warehouses, then the
            // reorder draft generation (the nightly `reorder:generate`, limited to these products) for PUSAT and WH-E2E.
            'purchasing.reorder' => fn (array $options): array => self::reorder($options),
        ];
    }

    private static function warehouse(string $code): Warehouse
    {
        return Warehouse::where('code', $code)->firstOrFail();
    }

    private static function material(array $options): array
    {
        $kg = Unit::where('code', 'KG')->firstOrFail();
        $gram = Unit::where('code', 'GRAM')->firstOrFail();
        $pricePerKg = (int) ($options['price'] ?? 250000);
        $category = Category::firstOrCreate(['name' => 'Bahan Baku'], ['description' => 'Bahan baku untuk resep dan produksi.']);
        $code = 'RM-'.Str::upper(Str::random(6));
        $stocks = [
            E2eSeeder::WAREHOUSE_CODE => [(int) ($options['stock_outlet'] ?? 0), $options['avg_outlet'] ?? null],
            'PUSAT' => [(int) ($options['stock_central'] ?? 0), $options['avg_central'] ?? null],
        ];

        $ids = DB::transaction(function () use ($options, $kg, $gram, $pricePerKg, $category, $code, $stocks) {
            $material = Product::create([
                'category_id' => $category->id,
                'barcode' => $code,
                'sku' => $code,
                'title' => $options['title'] ?? 'Bahan E2E '.Str::upper(Str::random(5)),
                'buy_price' => intdiv($pricePerKg, 1000),
                'sell_price' => 0,
                'stock' => array_sum(array_column($stocks, 0)),
                'min_stock' => (int) ($options['min_stock'] ?? 0),
                'max_stock' => (int) ($options['max_stock'] ?? 0),
                'tax_type' => 'exclusive',
                'tax_rate' => 0,
                'is_raw_material' => true,
                'purchased_by' => $options['purchased_by'] ?? 'central',
            ]);
            $material->units()->sync([
                $gram->id => ['is_base' => true, 'is_purchase_default' => false, 'conversion_factor' => 1, 'buy_price' => intdiv($pricePerKg, 1000), 'sell_price' => 0],
                $kg->id => ['is_base' => false, 'is_purchase_default' => true, 'conversion_factor' => 1000, 'buy_price' => $pricePerKg, 'sell_price' => 0],
            ]);

            foreach ($stocks as $warehouseCode => [$stock, $avg]) {
                if ($stock <= 0) {
                    continue;
                }
                $warehouse = self::warehouse($warehouseCode);
                // A null average cost is filled with the reference price (ProductWarehouse model).
                $warehouse->products()->attach($material->id, ['stock' => $stock, 'avg_cost' => $avg]);
                if (! empty($options['opening'])) {
                    app(StockMutationService::class)->recordInitialStock($material, null, $warehouse->id);
                }
            }

            $recipeId = $recipeTitle = null;
            if (! empty($options['recipe'])) {
                $recipe = Product::create([
                    'category_id' => $category->id,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => 'Resep '.$material->title,
                    'buy_price' => 0,
                    'sell_price' => 20000,
                    'stock' => 0,
                    'tax_type' => 'exclusive',
                    'tax_rate' => 0,
                    'is_composite' => true,
                ]);
                $recipe->components()->attach([$material->id => ['qty' => 18]]);
                $recipeId = $recipe->id;
                $recipeTitle = $recipe->title;
            }

            return ['material_id' => $material->id, 'title' => $material->title, 'sku' => $material->sku, 'recipe_id' => $recipeId, 'recipe_title' => $recipeTitle];
        });

        return $ids;
    }

    private static function purchaseOrder(array $options): array
    {
        $material = Product::findOrFail($options['material_id']);
        $supplierId = $options['supplier_id'] ?? Supplier::create(['name' => 'Supplier E2E '.Str::upper(Str::random(5))])->id;
        $warehouse = self::warehouse($options['warehouse'] ?? ($material->purchased_by === 'central' ? 'PUSAT' : E2eSeeder::WAREHOUSE_CODE));
        $kg = Unit::where('code', 'KG')->firstOrFail();
        $user = User::where('email', 'superadmin@e2e.test')->firstOrFail();

        $order = app(PurchaseOrderService::class)->createOrder(
            ['supplier_id' => $supplierId, 'warehouse_id' => $warehouse->id],
            [['product_id' => $material->id, 'unit_id' => $kg->id, 'qty_ordered' => (int) ($options['qty'] ?? 5), 'unit_price' => (int) ($options['price'] ?? 250000)]],
            $user->id,
        );

        if (($options['status'] ?? 'ordered') === 'ordered') {
            app(PurchaseOrderService::class)->placeOrder($order);
        }

        $result = ['po_id' => $order->id, 'document_number' => $order->document_number, 'supplier_id' => (int) $supplierId, 'supplier' => Supplier::find($supplierId)->name];

        if (! empty($options['receive'])) {
            $order = PurchaseOrder::with('items')->findOrFail($order->id);
            $receiving = app(GoodsReceivingService::class)->receive(
                $order,
                [['purchase_order_item_id' => $order->items->first()->id, 'qty_received' => (int) $options['receive']]],
                null,
                $user->id,
            );
            $result += ['gr_id' => $receiving->id, 'gr_document_number' => $receiving->document_number, 'payable_id' => $order->payable()->value('id')];
        }

        return $result;
    }

    private static function reorder(array $options): array
    {
        $tag = $options['tag'] ?? Str::upper(Str::random(5));
        $materials = [
            'central' => self::material(['title' => "Kopi $tag", 'purchased_by' => 'central', 'min_stock' => 8000, 'max_stock' => 10000, 'stock_outlet' => 7700, 'stock_central' => 7700]),
            'outlet' => self::material(['title' => "Es $tag", 'purchased_by' => 'outlet', 'price' => 20000, 'min_stock' => 8000, 'max_stock' => 10000, 'stock_outlet' => 7700, 'stock_central' => 7700]),
        ];
        $mine = array_column($materials, 'material_id');

        $plain = Product::create([
            'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
            'barcode' => 'E2E-'.Str::upper(Str::random(10)),
            'title' => "Gelas $tag",
            'buy_price' => 500,
            'sell_price' => 1000,
            'stock' => 8,
            'min_stock' => 10,
            'max_stock' => 50,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
        ]);
        foreach ([E2eSeeder::WAREHOUSE_CODE, 'PUSAT'] as $code) {
            self::warehouse($code)->products()->attach($plain->id, ['stock' => 4]);
        }
        $mine[] = $plain->id;

        $reorder = app(ReorderService::class);
        $user = User::where('email', 'superadmin@e2e.test')->firstOrFail();
        $result = ['tag' => $tag];
        foreach (['central' => 'PUSAT', 'outlet' => E2eSeeder::WAREHOUSE_CODE] as $key => $code) {
            $warehouse = self::warehouse($code);
            // The command takes every low-stock product; limited to this scenario's products so other steps' data stays out.
            $products = $reorder->getLowStockProducts($warehouse->id)->whereIn('id', $mine)->values();
            $order = $reorder->createDraftPurchaseOrder($products, $user->id, $warehouse->id);
            $result["{$key}_po"] = $order?->document_number;
        }

        return $result + ['coffee' => $materials['central']['title'], 'ice' => $materials['outlet']['title'], 'plain' => $plain->title];
    }
}
