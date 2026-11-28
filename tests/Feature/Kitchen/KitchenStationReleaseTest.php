<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// REQ-006 AC-09 (clauses 1 and 2), BR-6: at release there is one default station, old items are in it, products stay unmapped.
class KitchenStationReleaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the station migrations, in the order they run */
    private const MIGRATIONS = [
        '2026_12_02_100001_create_kitchen_stations_table.php',
        '2026_12_02_100002_add_kitchen_station_to_products_table.php',
        '2026_12_02_100003_add_kitchen_station_to_kitchen_items_table.php',
    ];

    /** @param  list<string>  $files */
    private function runMigrations(string $direction, array $files = self::MIGRATIONS): void
    {
        foreach ($direction === 'down' ? array_reverse($files) : $files as $file) {
            (require database_path("migrations/$file"))->$direction();
        }
    }

    public function test_release_gives_one_default_station_and_puts_old_items_and_products_in_it(): void
    {
        // The shop as it was before the release: an item already in the kitchen, with no station column yet.
        // Only the items migration is rewound: SQLite rebuilds `products` to add a foreign key and refuses to
        // while kitchen_items rows point at it (MySQL, the production database, does not rebuild).
        $items = [self::MIGRATIONS[2]];
        $this->runMigrations('down', $items);

        $warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $product = Product::create([
            'title' => 'Nasi goreng', 'sku' => 'SKU-REL-1', 'barcode' => 'BC-REL-1', 'description' => 'x',
            'image' => 'products/t.jpg', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'tax_rate' => 0, 'category_id' => $category->id, 'needs_preparation' => true,
        ]);
        $transaction = Transaction::create([
            'cashier_id' => User::factory()->create()->id, 'warehouse_id' => $warehouse->id, 'invoice' => 'TRX-REL-1',
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $detail = $transaction->details()->create(['product_id' => $product->id, 'qty' => 1, 'price' => 10000]);
        $item = KitchenItem::create([
            'transaction_id' => $transaction->id, 'transaction_detail_id' => $detail->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'qty' => 1, 'status' => KitchenItem::STATUS_PREPARING,
        ]);

        $this->runMigrations('up', $items);

        $dapur = KitchenStation::sole();
        $this->assertSame('Dapur', $dapur->name);
        $this->assertTrue($dapur->is_default);
        $this->assertSame($dapur->id, $item->fresh()->kitchen_station_id);
        $this->assertNull($product->fresh()->kitchen_station_id);
        $this->assertSame(1, KitchenStation::where('is_default', true)->count());
    }

    public function test_both_station_columns_are_nullable(): void
    {
        foreach (['products', 'kitchen_items'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'kitchen_station_id');

            $this->assertNotNull($column, "$table has kitchen_station_id");
            $this->assertTrue($column['nullable'], "$table.kitchen_station_id is nullable");
        }
    }

    public function test_the_migrations_reverse_and_run_again(): void
    {
        // #345's pivot references kitchen_stations, so its own migration is rewound first and run again last.
        $pivot = require database_path('migrations/2026_12_29_300001_create_kitchen_print_device_station_table.php');
        $pivot->down();
        $this->runMigrations('down');

        $this->assertFalse(Schema::hasTable('kitchen_stations'));
        $this->assertFalse(Schema::hasColumn('products', 'kitchen_station_id'));
        $this->assertFalse(Schema::hasColumn('kitchen_items', 'kitchen_station_id'));

        $this->runMigrations('up');
        $pivot->up();

        $this->assertTrue(Schema::hasTable('kitchen_print_device_station'));
        $this->assertSame(1, KitchenStation::count());
        $this->assertTrue(Schema::hasColumn('products', 'kitchen_station_id'));
        $this->assertTrue(Schema::hasColumn('kitchen_items', 'kitchen_station_id'));
    }
}
