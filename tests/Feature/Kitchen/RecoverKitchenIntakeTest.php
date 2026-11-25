<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\KitchenItem;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KitchenIntakeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

// #289: a paid sale whose kitchen intake failed (or never ran) is picked up again by kitchen:recover-intake.
class RecoverKitchenIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
    }

    private function product(bool $needsPreparation = true): Product
    {
        $n = ++$this->seq;

        return Product::create([
            'title' => "Produk $n", 'sku' => "SKU-RK-$n", 'barcode' => "BC-RK-$n", 'description' => 'x',
            'image' => 'products/t.jpg', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'tax_rate' => 0, 'category_id' => $this->category->id, 'needs_preparation' => $needsPreparation,
        ]);
    }

    /**
     * A sale as the payment left it when the kitchen step never ran: created paid (no update hook), one line of qty 2
     * per product (a single flagged product when none is given).
     *
     * @param  array<int, Product>|null  $products
     * @param  array<string, mixed>  $attributes
     */
    private function sale(?array $products = null, array $attributes = []): Transaction
    {
        $n = ++$this->seq;
        $transaction = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => "TRX-RK-$n",
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 20000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            ...$attributes,
        ]);
        foreach ($products ?? [$this->product()] as $product) {
            $transaction->details()->create(['product_id' => $product->id, 'qty' => 2, 'price' => 20000]);
        }

        return $transaction;
    }

    /** A return of $qty units of the sale's first line, completed unless a status is given. */
    private function salesReturn(Transaction $transaction, int $qty, string $status = 'completed'): void
    {
        $detail = $transaction->details()->firstOrFail();
        $return = SalesReturn::create(['code' => 'SR-RK-'.++$this->seq, 'transaction_id' => $transaction->id, 'status' => $status]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id, 'product_id' => $detail->product_id, 'qty_sold' => 2, 'qty_return' => $qty,
            'unit_price' => 10000, 'subtotal' => 10000 * $qty, 'return_reason' => 'x',
        ]);
    }

    /** The intake service with a switch: sales listed in `failFor` throw like a kitchen that is down. */
    private function flakyIntake(): KitchenIntakeService
    {
        $intake = new class extends KitchenIntakeService
        {
            /** @var array<int, int> */
            public array $failFor = [];

            public function intake(Transaction $transaction): void
            {
                if (in_array($transaction->id, $this->failFor, true)) {
                    throw new RuntimeException('kitchen down');
                }

                parent::intake($transaction);
            }
        };
        $this->app->instance(KitchenIntakeService::class, $intake);

        return $intake;
    }

    public function test_paid_sale_without_kitchen_items_is_recovered(): void
    {
        Exceptions::fake();
        $intake = $this->flakyIntake();
        $sale = $this->sale([$this->product(), $this->product(false)]);
        $flaggedLine = $sale->details()->orderBy('id')->firstOrFail();

        // The first intake failed: the error is reported and the sale is left for the next run.
        $intake->failFor = [$sale->id];
        $this->artisan('kitchen:recover-intake')->assertSuccessful();
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'kitchen down');
        $this->assertSame(0, KitchenItem::count());

        $intake->failFor = [];
        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $item = KitchenItem::sole();
        $this->assertSame($sale->id, $item->transaction_id);
        $this->assertSame($flaggedLine->id, $item->transaction_detail_id);
        $this->assertSame($flaggedLine->product_id, $item->product_id);
        $this->assertSame($this->warehouse->id, $item->warehouse_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
    }

    public function test_running_twice_creates_items_once(): void
    {
        $this->sale([$this->product(), $this->product()]);

        $this->artisan('kitchen:recover-intake')->assertSuccessful();
        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertSame(2, KitchenItem::count());
        $this->assertSame(2, KitchenItem::distinct()->count('transaction_detail_id'));
    }

    public function test_skips_old_pay_later_no_warehouse_and_fully_returned_sales(): void
    {
        $old = $this->sale();
        Transaction::whereKey($old->id)->update(['updated_at' => now()->subDays(2)]);
        $this->sale(attributes: ['payment_method' => 'pay_later', 'is_pay_later' => true]);
        $this->sale(attributes: ['warehouse_id' => null]);
        $this->sale(attributes: ['payment_status' => 'expired']);
        $this->salesReturn($this->sale(), 2);

        // Controls: nothing else may stop these from being recovered, so the skips above come from the rules.
        $plain = $this->sale();
        $partlyReturned = $this->sale();
        $this->salesReturn($partlyReturned, 1);
        $draftReturn = $this->sale();
        $this->salesReturn($draftReturn, 2, 'draft');

        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$plain->id, $partlyReturned->id, $draftReturn->id],
            KitchenItem::pluck('transaction_id')->all(),
        );
    }

    public function test_one_failing_sale_does_not_stop_the_others(): void
    {
        Exceptions::fake();
        Log::spy();
        $intake = $this->flakyIntake();
        $first = $this->sale();
        $failing = $this->sale();
        $last = $this->sale();
        $intake->failFor = [$failing->id];

        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$first->id, $last->id], KitchenItem::pluck('transaction_id')->all());
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'kitchen down');
        Log::shouldHaveReceived('info')->once()->with('kitchen:recover-intake', ['recovered' => 2, 'failed' => 1]);
    }

    public function test_the_window_is_30_minutes_29_recovered_31_skipped(): void
    {
        $inside = $this->sale();
        $outside = $this->sale();
        Transaction::whereKey($inside->id)->update(['updated_at' => now()->subMinutes(29)]);
        Transaction::whereKey($outside->id)->update(['updated_at' => now()->subMinutes(31)]);

        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertSame([$inside->id], KitchenItem::pluck('transaction_id')->all());
    }

    public function test_a_line_without_a_snapshot_reads_the_product_flag_while_the_sale_is_inside_the_window(): void
    {
        // Sales written before #341 have no snapshot on their lines (as built here), so the flag is read when the job runs.
        $product = $this->product(false);
        $recent = $this->sale([$product]);
        $old = $this->sale([$product]);
        Transaction::whereKey($old->id)->update(['created_at' => now()->subMinutes(31), 'updated_at' => now()->subMinutes(31)]);
        $this->artisan('kitchen:recover-intake')->assertSuccessful();
        $this->assertSame(0, KitchenItem::count(), 'nothing needs preparation yet');

        $product->update(['needs_preparation' => true]);
        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertSame([$recent->id], KitchenItem::pluck('transaction_id')->all());
    }

    public function test_a_product_flagged_after_the_sale_is_not_recovered_when_the_line_was_frozen_as_unflagged(): void
    {
        $product = $this->product(false);
        $sale = $this->sale([$product]);
        $sale->details()->update(['needs_preparation' => false]); // what checkout writes since #341

        $product->update(['needs_preparation' => true]);
        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertSame(0, KitchenItem::count());
    }

    public function test_a_product_unflagged_after_the_sale_is_still_recovered_when_the_line_was_frozen_as_flagged(): void
    {
        $product = $this->product();
        $sale = $this->sale([$product]);
        $sale->details()->update(['needs_preparation' => true]);

        $product->update(['needs_preparation' => false]);
        $this->artisan('kitchen:recover-intake')->assertSuccessful();

        $this->assertSame([$sale->id], KitchenItem::pluck('transaction_id')->all());
    }

    public function test_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'kitchen:recover-intake'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
