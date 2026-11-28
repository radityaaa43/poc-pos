<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesCreditData;
use Tests\TestCase;

/** Pay later against the customer's credit limit and overdue receivables, on the web and in the API (REQ-007). */
class CreditLimitCheckoutTest extends TestCase
{
    use MakesCreditData, RefreshDatabase;

    private const OVERDUE_MESSAGE = 'Lunasi piutang yang lewat jatuh tempo dulu';

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
            'needs_preparation' => true,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 200]);

        $this->cashier = $this->userWith('transactions-access', 'cashier-shifts-access');
        $this->cashier->markEmailAsVerified();
        // The column's default: the web answers in the user's language.
        $this->cashier->forceFill(['locale' => 'id'])->save();
        app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 100000,
            notes: null,
            warehouseId: $this->warehouse->id,
        );
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
    }

    /** A cart worth `$total` rupiah (the product sells at Rp 10.000), replacing what a refused sale left. */
    private function cart(int $total): void
    {
        Cart::where('cashier_id', $this->cashier->id)->delete();
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => $total / 10000,
            'price' => $total,
        ]);
    }

    private function body(?Customer $customer): array
    {
        return ['customer_id' => $customer?->id, 'due_date' => today()->addDays(7)->toDateString(), 'note' => 'Meja 4'];
    }

    private function web(?Customer $customer, int $total)
    {
        $this->cart($total);

        return $this->from(route('transactions.index'))
            ->actingAs($this->cashier)
            ->post(route('transactions.store'), ['pay_later' => '1', 'discount' => 0, 'grand_total' => $total] + $this->body($customer));
    }

    private function api(?Customer $customer, int $total)
    {
        $this->cart($total);
        Sanctum::actingAs($this->cashier, ['*']);

        return $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'pay_later'] + $this->body($customer));
    }

    private function assertSold(?Customer $customer, int $total): void
    {
        $before = Receivable::count();

        $this->api($customer, $total)->assertSuccessful();

        $this->assertSame($before + 1, Receivable::count());
        $this->assertSame($total, (int) Receivable::latest('id')->firstOrFail()->total);
    }

    /** Both channels refuse with the same words; the API adds the code and what is left of the limit. */
    private function assertRefused(Customer $customer, int $total, string $code, int $remaining, string $message): void
    {
        $sales = Transaction::count();

        $this->web($customer, $total)
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasErrors(['credit' => $message]);

        $this->api($customer, $total)
            ->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => $message,
                'errors' => ['code' => $code, 'remaining_limit' => $remaining],
            ]);

        $this->assertSame($sales, Transaction::count());
    }

    public function test_over_limit_is_refused_with_the_remaining_limit(): void
    {
        $budi = $this->customer(500000);
        $this->receivable($budi, 300000);

        $this->assertRefused($budi, 210000, 'credit_limit_exceeded', 200000, 'Melebihi batas piutang — sisa Rp 200.000');
    }

    public function test_within_and_exactly_the_remaining_limit_are_allowed(): void
    {
        $budi = $this->customer(500000);
        // Due today, paid in the past and no due date at all: none of them is overdue.
        $this->receivable($budi, 300000, overrides: ['due_date' => today()]);
        $this->receivable($budi, 100000, 100000, ['due_date' => today()->subDay()]);
        $this->receivable($budi, 10000, overrides: ['due_date' => null]);

        $this->assertSold($budi, 90000);
        $this->assertSold($budi, 100000); // used 400.000 + 100.000 = the limit
        $this->assertRefused($budi, 10000, 'credit_limit_exceeded', 0, 'Melebihi batas piutang — sisa Rp 0');
    }

    public function test_limit_zero_and_a_limit_below_usage_block_new_credit_at_once(): void
    {
        $this->assertRefused($this->customer(0), 10000, 'credit_limit_exceeded', 0, 'Melebihi batas piutang — sisa Rp 0');

        $budi = $this->customer();
        $this->receivable($budi, 300000);
        $this->assertSold($budi, 10000); // no limit yet

        $budi->forceFill(['credit_limit' => 100000])->save(); // lowered below what is owed (BR-9)

        $this->assertRefused($budi, 10000, 'credit_limit_exceeded', 0, 'Melebihi batas piutang — sisa Rp 0');
    }

    public function test_a_customer_without_a_limit_is_never_refused(): void
    {
        $budi = $this->customer();
        $this->receivable($budi, 900000, overrides: ['due_date' => today()->subDays(30)]);

        $this->assertSold($budi, 1000000);
        $this->web($budi, 10000)->assertSessionHasNoErrors();
        $this->assertSame(3, Receivable::count());

        // The POS panel still warns (REQ-007 decision 5).
        $this->actingAs($this->cashier)->getJson(route('customers.credit-summary', $budi))->assertOk()->assertJsonPath('has_overdue', true);
    }

    public function test_an_overdue_customer_with_a_limit_is_refused_even_with_room(): void
    {
        $budi = $this->customer(500000);
        $this->receivable($budi, 100000, 40000, ['due_date' => today()->subDay()]);

        $this->assertRefused($budi, 10000, 'credit_overdue', 440000, self::OVERDUE_MESSAGE);
    }

    public function test_the_web_refusal_follows_the_users_language_and_the_api_stays_indonesian(): void
    {
        $this->cashier->forceFill(['locale' => 'en'])->save();
        $budi = $this->customer(100000);

        $this->web($budi, 210000)->assertSessionHasErrors(['credit' => 'Over the credit limit — Rp 100.000 left']);
        $this->api($budi, 210000)->assertStatus(422)->assertJsonPath('message', 'Melebihi batas piutang — sisa Rp 100.000');

        $this->receivable($budi, 10000, overrides: ['due_date' => today()->subDay()]);
        $this->web($budi, 10000)->assertSessionHasErrors(['credit' => 'Settle the overdue receivable first']);
    }

    public function test_the_limit_is_checked_before_overdue(): void
    {
        $budi = $this->customer(500000);
        $this->receivable($budi, 300000, overrides: ['due_date' => today()->subDay()]);

        $this->assertRefused($budi, 210000, 'credit_limit_exceeded', 200000, 'Melebihi batas piutang — sisa Rp 200.000');
    }

    public function test_usage_counts_other_outlets_and_a_return_lowers_it(): void
    {
        $other = Warehouse::create(['code' => 'WH-2', 'name' => 'Gudang 2', 'status' => 'active']);
        $budi = $this->customer(500000);
        $receivable = $this->receivable($budi, 500000, overrides: ['transaction_id' => $this->sale($other)->id]);

        $this->assertRefused($budi, 10000, 'credit_limit_exceeded', 0, 'Melebihi batas piutang — sisa Rp 0');

        $receivable->update(['total' => 400000]); // a sales return lowers the receivable's total

        $this->assertSold($budi, 100000);
    }

    public function test_a_refused_sale_persists_nothing(): void
    {
        $budi = $this->customer(10000);
        $budi->forceFill(['is_loyalty_member' => true, 'member_code' => 'MBR-1', 'loyalty_points' => 5])->save();

        $this->web($budi, 20000)->assertSessionHasErrors('credit');

        foreach (['transactions', 'transaction_details', 'profits', 'stock_mutations', 'loyalty_point_histories'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, KitchenItem::count());
        $this->assertSame(0, Receivable::count());
        $this->assertSame(1, Cart::where('cashier_id', $this->cashier->id)->active()->count());
        $this->assertSame(200, (int) $this->product->fresh()->stock);
        $this->assertSame(200, (int) $this->product->warehouses()->firstOrFail()->pivot->stock);
        $this->assertSame([5, 0, 0], [
            (int) $budi->fresh()->loyalty_points,
            (int) $budi->fresh()->loyalty_total_spent,
            (int) $budi->fresh()->loyalty_transaction_count,
        ]);
    }

    public function test_only_a_credit_sale_to_a_credit_customer_is_checked(): void
    {
        // A limit left behind on a customer who may no longer buy on credit makes no receivable, so nothing to check.
        $this->api($this->customer(0, mayBuyOnCredit: false), 10000)->assertSuccessful();
        $this->api(null, 10000)->assertSuccessful(); // a guest

        $budi = $this->customer(0);
        $this->cart(10000);
        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'cash', 'cash' => 10000, 'customer_id' => $budi->id])->assertSuccessful();

        $this->assertSame(3, Transaction::count());
        $this->assertSame(0, Receivable::count());
    }

    public function test_the_check_and_the_receivable_share_one_locked_transaction(): void
    {
        $budi = $this->customer(500000);
        $steps = [];
        $outer = DB::transactionLevel();
        DB::listen(function ($query) use (&$steps) {
            // Anchored at the start: the request also runs summary subqueries that mention these tables.
            $patterns = [
                'lock' => '/^select \* from [`"]customers[`"] where/i',
                'carts' => '/^select \* from [`"]carts[`"]/i',
                'usage' => '/^select coalesce\(sum\(total - paid\)/i',
                'create' => '/^insert into [`"]receivables[`"]/i',
            ];
            foreach ($patterns as $step => $pattern) {
                if (preg_match($pattern, $query->sql)) {
                    $steps[] = [$step, DB::transactionLevel(), $query->sql];
                }
            }
        });

        $this->assertSold($budi, 10000);

        $inside = array_values(array_filter($steps, fn (array $step) => $step[1] === $outer + 1));
        $order = array_column($inside, 0);

        // The customer is locked before the transaction's first plain read (the cart), or the sum below is stale (BR-3).
        $this->assertSame(['lock', 'carts'], array_slice($order, 0, 2));
        $this->assertLessThan(array_search('create', $order, true), array_search('usage', $order, true));
        // SQLite renders no lock clause: only the MySQL job can show them.
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', $inside[0][2]);
            // No locking read on receivables: its gap locks would deadlock credit sales of different customers.
            $this->assertStringNotContainsString('lock in share mode', $inside[array_search('usage', $order, true)][2]);
        }
    }
}
