<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** #473: a background request between a checkout POST and its redirect must not eat the flashed outcome. */
class KeepFlashForPollsTest extends TestCase
{
    use RefreshDatabase;

    /** What the browser sends on an axios or fetch call; without it Laravel treats the GET as a page visit. */
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private User $cashier;

    private Product $product;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();

        $warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $category = Category::create(['name' => 'Kategori', 'image' => 'test.png', 'description' => 'desc']);

        $this->product = Product::create([
            'title' => 'Produk Poll', 'sku' => 'SKU-POLL', 'barcode' => 'BC-POLL', 'image' => 'product.png', 'description' => 'desc',
            'category_id' => $category->id, 'buy_price' => 1000, 'sell_price' => 2000, 'stock' => 5, 'tax_rate' => 0,
        ]);
        $this->product->warehouses()->attach($warehouse->id, ['stock' => 5]);

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $warehouse->id);

        // More than the stock, past the add-to-cart guard: checkout must refuse it.
        $this->cart = Cart::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $warehouse->id, 'product_id' => $this->product->id,
            'qty' => 10, 'price' => 20000, 'conversion_factor' => 1,
        ]);

        $this->actingAs($this->cashier);
    }

    private function refusedCheckout(): void
    {
        $this->from(route('transactions.index'))
            ->post(route('transactions.store'), ['cash' => 50000])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasErrors('stock')
            ->assertSessionMissing('success');
        $this->assertSame(0, Transaction::count());
    }

    private function poll(): void
    {
        $this->getJson(route('transactions.open-bills'), self::AJAX)->assertOk();
    }

    private function posPage(): TestResponse
    {
        return $this->get(route('transactions.index'))->assertOk();
    }

    public function test_poll_between_post_and_redirect_keeps_errors(): void
    {
        $this->refusedCheckout();
        $this->poll();

        $this->posPage()->assertInertia(fn (Assert $page) => $page->has('errors.stock'));
    }

    public function test_flash_is_gone_after_the_page_shows_it(): void
    {
        $this->refusedCheckout();
        $this->posPage()->assertInertia(fn (Assert $page) => $page->has('errors.stock'));
        $this->poll();

        $this->posPage()->assertInertia(fn (Assert $page) => $page->missing('errors.stock'));
    }

    public static function backgroundRequests(): array
    {
        return [
            'customer credit summary' => ['GET', 'customers.credit-summary'],
            'customer history' => ['GET', 'customers.history'],
            'pricing preview' => ['POST', 'transactions.pricing-preview'],
            'offline sync' => ['POST', 'transactions.sync'],
            'receipt payment status, in another tab' => ['GET', 'transactions.status'],
        ];
    }

    #[DataProvider('backgroundRequests')]
    public function test_other_background_requests_keep_errors(string $method, string $route): void
    {
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
        // Only the sync reads a body; short of stock too, so it saves nothing.
        $body = $route === 'transactions.sync'
            ? ['transactions' => [['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'qty' => 100]]]]]
            : [];

        $this->refusedCheckout();
        $parameter = match (true) {
            $route === 'transactions.status' => Transaction::create([
                'cashier_id' => $this->cashier->id, 'invoice' => 'INV-POLL', 'cash' => 0, 'change' => 0, 'discount' => 0,
                'shipping_cost' => 0, 'grand_total' => 2000, 'payment_method' => 'cash', 'payment_status' => 'paid',
            ])->invoice,
            $method === 'GET' => $customer,
            default => [],
        };
        $url = route($route, $parameter);
        $this->json($method, $url, $body, self::AJAX)->assertOk();

        $this->posPage()->assertInertia(fn (Assert $page) => $page->has('errors.stock'));
    }

    public function test_saved_sale_keeps_its_success_flash_through_a_poll(): void
    {
        $this->cart->update(['qty' => 1, 'price' => 2000]);

        $response = $this->post(route('transactions.store'), ['cash' => 50000]);
        $invoice = Transaction::sole()->invoice;
        $response->assertRedirect(route('transactions.print', $invoice))->assertSessionHas('success', 'Transaksi berhasil!');
        $this->poll();

        $this->get(route('transactions.print', $invoice))
            ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Transaksi berhasil!'));
    }
}
