<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KitchenSpeedReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// #243: the kitchen speed report, read from kitchen_items (#236).
class KitchenSpeedReportTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-05-10';

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permission::firstOrCreate(['name' => 'reports-access', 'guard_name' => 'web']);
        $this->outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true]);
        $this->warehouse = $this->warehouse($this->outlet, 'WH-A1');
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('reports-access');
        $this->user->outlets()->attach($this->outlet->id, ['is_default' => true]);
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
    }

    public function test_report_shows_count_median_average_target_and_share_per_menu(): void
    {
        $odd = $this->product('Nasi goreng', target: 5);
        foreach ([2, 4, 9] as $make) {
            $this->item($odd, ['prep' => 1, 'ready' => 1 + $make]);
        }
        $even = $this->product('Mie goreng', target: 5);
        foreach ([1, 2, 3, 10] as $make) {
            $this->item($even, ['prep' => 2, 'ready' => 2 + $make]);
        }
        $noTarget = $this->product('Es teh');
        $this->item($noTarget, ['prep' => 0, 'ready' => 3]);

        $rows = collect($this->build()['rows'])->keyBy('title');

        $this->assertSame([
            'product_id' => $odd->id, 'title' => 'Nasi goreng', 'count_rows' => 3, 'count_portions' => 3,
            'make_median' => 4.0, 'make_avg' => 5.0, 'wait_make_median' => 5.0, 'wait_make_avg' => 6.0,
            'target' => 5, 'within_target_pct' => 66.7,
        ], $rows['Nasi goreng']);
        $this->assertSame(
            [4, 2.5, 4.0, 4.5, 6.0, 75.0],
            [$rows['Mie goreng']['count_rows'], $rows['Mie goreng']['make_median'], $rows['Mie goreng']['make_avg'],
                $rows['Mie goreng']['wait_make_median'], $rows['Mie goreng']['wait_make_avg'], $rows['Mie goreng']['within_target_pct']],
        );
        $this->assertNull($rows['Es teh']['target']);
        $this->assertNull($rows['Es teh']['within_target_pct']);
    }

    public function test_report_counts_rows_and_portions(): void
    {
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi, ['qty' => 3, 'prep' => 1, 'ready' => 5]);

        $row = $this->build()['rows'][0];

        $this->assertSame(1, $row['count_rows']);
        $this->assertSame(3, $row['count_portions']);
        $this->assertSame(4.0, $row['make_avg']); // timing is per row, not per portion
    }

    public function test_average_payment_to_full_handover_per_order(): void
    {
        $nasi = $this->product('Nasi goreng');
        $first = $this->transaction();
        $this->item($nasi, ['transaction' => $first, 'served' => 6]);
        $this->item($nasi, ['transaction' => $first, 'served' => 10]);
        $this->item($nasi, ['accepted' => self::DAY.' 11:00:00', 'ready' => 4, 'served' => 5]);

        $this->assertSame(['orders' => 2, 'avg_minutes' => 7.5], $this->build()['orders']);
    }

    public function test_order_with_an_unserved_or_not_collected_item_is_left_out_of_handover(): void
    {
        $nasi = $this->product('Nasi goreng');
        $notCollected = $this->transaction();
        $this->item($nasi, ['transaction' => $notCollected]);
        $this->item($nasi, ['transaction' => $notCollected, 'status' => 'not_collected', 'ready' => null, 'served' => null]);
        $stillOpen = $this->transaction();
        $this->item($nasi, ['transaction' => $stillOpen]);
        $this->item($nasi, ['transaction' => $stillOpen, 'status' => 'ready', 'served' => null]);
        $withCancelled = $this->transaction();
        $this->item($nasi, ['transaction' => $withCancelled, 'served' => 8]);
        $this->item($nasi, ['transaction' => $withCancelled, 'status' => 'cancelled', 'ready' => null, 'served' => null]);

        $this->assertSame(['orders' => 1, 'avg_minutes' => 8.0], $this->build()['orders']);
    }

    public function test_cancelled_not_collected_and_release_rows_are_left_out(): void
    {
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi);
        $this->item($nasi, ['status' => 'cancelled', 'ready' => null, 'served' => null]);
        $this->item($nasi, ['status' => 'not_collected', 'ready' => null, 'served' => null]);
        $this->item($nasi, ['status' => 'preparing', 'ready' => null, 'served' => null]);
        // Carried over by the release backfill: no accepted_at and no timings.
        $this->item($nasi, ['status' => 'ready', 'accepted' => null, 'prep' => null, 'ready' => null, 'served' => null]);

        $report = $this->build();

        $this->assertCount(1, $report['rows']);
        $this->assertSame(1, $report['rows'][0]['count_rows']);
    }

    public function test_item_without_preparing_at_counts_only_in_wait_plus_make(): void
    {
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi, ['prep' => 2, 'ready' => 6]);
        $this->item($nasi, ['prep' => null, 'ready' => 10]);

        $row = $this->build()['rows'][0];

        $this->assertSame(2, $row['count_rows']);
        $this->assertSame(4.0, $row['make_avg']);
        $this->assertSame(8.0, $row['wait_make_avg']);
    }

    public function test_empty_period_returns_empty_flag_not_zeros(): void
    {
        $this->assertSame(
            ['rows' => [], 'orders' => ['orders' => 0, 'avg_minutes' => null], 'empty' => true],
            $this->build(),
        );
        $this->item($this->product('Nasi goreng'));
        $this->assertFalse($this->build()['empty']);
    }

    public function test_median_helper(): void
    {
        $medians = [];
        foreach ([[7], [3, 1], [5, 1, 3], [4, 1, 3, 2]] as $i => $makes) {
            $product = $this->product("Menu $i");
            foreach ($makes as $make) {
                $this->item($product, ['prep' => 0, 'ready' => $make]);
            }
            $medians[] = collect($this->build()['rows'])->firstWhere('product_id', $product->id)['make_median'];
        }

        $this->assertSame([7.0, 2.0, 3.0, 2.5], $medians);
    }

    public function test_period_uses_app_timezone_day_boundaries(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi, ['accepted' => self::DAY.' 23:30:00']);
        $this->item($nasi, ['accepted' => '2026-05-11 00:30:00']);

        $this->assertSame(1, $this->page(['start_date' => self::DAY, 'end_date' => self::DAY])['rows'][0]['count_rows']);
        $this->assertSame(1, $this->page(['start_date' => '2026-05-11', 'end_date' => '2026-05-11'])['rows'][0]['count_rows']);
        $this->assertSame(2, $this->page(['start_date' => self::DAY, 'end_date' => '2026-05-11'])['rows'][0]['count_rows']);
    }

    public function test_period_boundaries_use_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi, ['accepted' => self::DAY.' 00:00:00']);
        $this->item($nasi, ['accepted' => self::DAY.' 23:59:59']);
        $this->item($nasi, ['accepted' => '2026-05-11 00:00:00']);
        $this->item($nasi, ['accepted' => '2026-05-09 23:59:59']);

        $page = $this->page(['start_date' => self::DAY, 'end_date' => self::DAY]);

        $this->assertSame(2, $page['rows'][0]['count_rows']);
    }

    public function test_item_counts_on_the_day_it_was_accepted(): void
    {
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi, ['accepted' => self::DAY.' 23:55:00', 'prep' => 1, 'ready' => 10]);

        $this->assertSame(1, $this->page(['start_date' => self::DAY, 'end_date' => self::DAY])['rows'][0]['count_rows']);
        $this->assertSame([], $this->page(['start_date' => '2026-05-11', 'end_date' => '2026-05-11'])['rows']);
    }

    public function test_outlet_filter_covers_all_warehouses_of_the_outlet(): void
    {
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);
        $this->user->outlets()->attach($other->id);
        $nasi = $this->product('Nasi goreng');
        $this->item($nasi);
        $this->item($nasi, ['warehouse' => $this->warehouse($this->outlet, 'WH-A2')]);
        $this->item($nasi, ['warehouse' => $this->warehouse($other, 'WH-B1')]);

        $this->assertSame(2, $this->page(['outlet_id' => $this->outlet->id])['rows'][0]['count_rows']);
        $this->assertSame(1, $this->page(['outlet_id' => $other->id])['rows'][0]['count_rows']);
    }

    public function test_user_without_reports_access_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reports.kitchen-speed.index'))
            ->assertForbidden();
    }

    public function test_user_sees_only_their_own_outlets(): void
    {
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);
        $this->item($this->product('Nasi goreng'), ['warehouse' => $this->warehouse($other, 'WH-B1')]);

        $this->actingAs($this->user)
            ->get(route('reports.kitchen-speed.index', ['start_date' => self::DAY, 'end_date' => self::DAY]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/KitchenSpeed')
                ->has('outlets', 1)
                ->where('outlets.0.id', $this->outlet->id)
                ->where('filters.outlet_id', $this->outlet->id)
                ->where('rows', [])
                ->where('empty', true));
    }

    public function test_forged_outlet_id_is_rejected(): void
    {
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);

        $this->actingAs($this->user)
            ->getJson(route('reports.kitchen-speed.index', ['outlet_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outlet_id');
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('reports.kitchen-speed.index', ['start_date' => '2025-04-20', 'end_date' => '2025-04-10']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    /** The report as the page receives it. */
    private function page(array $query = []): array
    {
        $query += ['start_date' => self::DAY, 'end_date' => self::DAY];
        $props = [];
        $this->actingAs($this->user)
            ->get(route('reports.kitchen-speed.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function build(): array
    {
        $day = Carbon::parse(self::DAY);

        return app(KitchenSpeedReportService::class)->build([$this->warehouse->id], $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    private function warehouse(Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => $code, 'name' => $code, 'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function product(string $title, ?int $target = null): Product
    {
        return Product::create([
            'title' => $title, 'sku' => 'SKU-'.uniqid(), 'barcode' => 'BC-'.uniqid(), 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => true, 'prep_target_minutes' => $target,
        ]);
    }

    private function transaction(?Warehouse $warehouse = null): Transaction
    {
        return Transaction::create([
            'cashier_id' => $this->user->id, 'warehouse_id' => ($warehouse ?? $this->warehouse)->id, 'invoice' => 'INV-'.uniqid(),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
    }

    /**
     * One kitchen item, served by default: `accepted` is a date-time, `prep`/`ready`/`served` are minutes after it
     * (null = never happened).
     */
    private function item(Product $product, array $o = []): KitchenItem
    {
        $warehouse = $o['warehouse'] ?? $this->warehouse;
        $transaction = $o['transaction'] ?? $this->transaction($warehouse);
        $detail = TransactionDetail::create([
            'transaction_id' => $transaction->id, 'product_id' => $product->id, 'qty' => $o['qty'] ?? 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000,
        ]);
        $o += ['accepted' => self::DAY.' 10:00:00', 'prep' => 1, 'ready' => 5, 'served' => 6];
        $accepted = $o['accepted'] === null ? null : Carbon::parse($o['accepted']);
        $after = fn (?float $minutes) => $accepted && $minutes !== null ? $accepted->copy()->addSeconds((int) round($minutes * 60)) : null;

        return KitchenItem::create([
            'transaction_id' => $transaction->id, 'transaction_detail_id' => $detail->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'qty' => $o['qty'] ?? 1, 'status' => $o['status'] ?? 'served',
            'accepted_at' => $accepted, 'preparing_at' => $after($o['prep']), 'ready_at' => $after($o['ready']), 'served_at' => $after($o['served']),
        ]);
    }
}
