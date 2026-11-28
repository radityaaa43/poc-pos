<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\ThermalPrintService;
use App\Support\Checkout\CheckoutFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** REQ-009 AC-20 / BR-2 (#445): the optional order name typed at POS checkout. */
class OrderNameTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access']);

        $warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Utama', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $warehouse->id);

        $category = Category::create(['name' => 'Sembako', 'description' => 'x', 'image' => '']);
        $this->product = Product::create([
            'category_id' => $category->id, 'image' => 'product.png', 'barcode' => 'BRCD-NAME', 'title' => 'Produk Uji',
            'description' => 'x', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 25, 'tax_rate' => 0,
        ]);
        $warehouse->products()->attach($this->product->id, ['stock' => 25]);
        Cart::create(['cashier_id' => $this->cashier->id, 'product_id' => $this->product->id, 'qty' => 1, 'price' => 10000]);
    }

    public static function typedNames(): array
    {
        return [
            'trimmed' => ['  Budi 7  ', 'Budi 7'],
            'only spaces' => ['   ', null],
            'empty' => ['', null],
            'buzzer zero' => ['0', '0'],
            'twenty characters' => [str_repeat('é', 20), str_repeat('é', 20)],
        ];
    }

    #[DataProvider('typedNames')]
    public function test_web_checkout_stores_the_trimmed_name(string $typed, ?string $stored): void
    {
        $this->webCheckout(['order_name' => $typed])->assertSessionHasNoErrors();

        $this->assertSame($stored, Transaction::sole()->order_name);
    }

    #[DataProvider('typedNames')]
    public function test_api_checkout_stores_the_trimmed_name(string $typed, ?string $stored): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/checkout', ['cash' => 100000, 'payment_method' => 'cash', 'order_name' => $typed])
            ->assertCreated();

        $this->assertSame($stored, Transaction::sole()->order_name);
    }

    public function test_a_checkout_without_the_field_stores_null(): void
    {
        $this->webCheckout([])->assertSessionHasNoErrors();

        $this->assertNull(Transaction::sole()->order_name);
    }

    public function test_sync_keeps_the_order_name(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/transactions/sync', ['transactions' => [$this->queued(' Budi 7 ')]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'synced');

        $this->assertSame('Budi 7', Transaction::sole()->order_name);
    }

    public function test_a_reused_uuid_with_another_name_is_a_conflict_not_a_duplicate(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $send = fn (string $name) => $this->postJson('/api/v1/pos/transactions/sync', ['transactions' => [$this->queued($name)]])->assertOk();

        $send('Budi 7')->assertJsonPath('data.results.0.status', 'synced');
        $send(' Budi 7 ')->assertJsonPath('data.results.0.status', 'duplicate');
        $send('Budi 8')->assertJsonPath('data.results.0.status', 'conflict');
        $send('')->assertJsonPath('data.results.0.status', 'conflict');

        $this->assertSame('Budi 7', Transaction::sole()->order_name);
    }

    public function test_a_sale_queued_without_a_name_keeps_the_fingerprint_it_had_before_the_field(): void
    {
        $payload = ['items' => [['product_id' => 1, 'qty' => 1]], 'cash' => 100000];
        // Computed by the code before #445: a sale synced then and retried now must still read as a duplicate.
        $before = 'fb24e0b0187c75f6c5e738b70bc122f4648ead9c06c85f04ddb36195c2374743';

        foreach ([[], ['order_name' => null], ['order_name' => '  ']] as $unnamed) {
            $this->assertSame($before, CheckoutFingerprint::fromSyncPayload($payload + $unnamed));
        }
        $this->assertNotSame($before, CheckoutFingerprint::fromSyncPayload($payload + ['order_name' => '0']));
    }

    public function test_more_than_twenty_characters_are_refused_on_web(): void
    {
        $this->webCheckout(['order_name' => str_repeat('a', 21)])->assertSessionHasErrors('order_name');

        $this->assertSame(0, Transaction::count());
    }

    public function test_more_than_twenty_characters_are_refused_on_api_and_sync(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/checkout', ['cash' => 100000, 'payment_method' => 'cash', 'order_name' => str_repeat('a', 21)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_name');
        $this->postJson('/api/v1/pos/transactions/sync', ['transactions' => [$this->queued(str_repeat('a', 21))]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.order_name');

        $this->assertSame(0, Transaction::count());
    }

    public function test_every_server_receipt_shows_the_escaped_name_in_both_languages(): void
    {
        $this->webCheckout(['order_name' => '<b>Budi</b>']);
        $sale = Transaction::sole();

        foreach (['id' => 'Nama pesanan', 'en' => 'Order name'] as $locale => $label) {
            foreach ($this->receipts($sale, $locale) as $name => $receipt) {
                $this->assertStringContainsString($label, $receipt, "$name $locale");
                $this->assertStringContainsString('&lt;b&gt;Budi&lt;/b&gt;', $receipt, "$name $locale");
                $this->assertStringNotContainsString('<b>Budi</b>', $receipt, "$name $locale");
            }
        }
    }

    public function test_a_receipt_without_a_name_has_no_order_name_line(): void
    {
        $this->webCheckout([]);

        foreach ($this->receipts(Transaction::sole(), 'id') as $name => $receipt) {
            $this->assertStringNotContainsString('Nama pesanan', $receipt, $name);
        }
    }

    // BR-3: not on the A4 invoice, which is also the public invoice.
    public function test_the_a4_invoice_does_not_show_the_order_name(): void
    {
        $this->webCheckout(['order_name' => 'Budi 7']);
        $store = ['name' => 'Toko', 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'logo' => null, 'logo_data' => null];

        $invoice = view('pdf.invoice', ['transaction' => Transaction::sole(), 'store' => $store, 'barcode' => '', 'locale' => 'id'])->render();

        $this->assertStringNotContainsString('Budi 7', $invoice);
    }

    /** @return array<string, string> the two PDF receipt views and the thermal browser preview */
    private function receipts(Transaction $sale, string $locale): array
    {
        app()->setLocale($locale);
        $store = ['name' => 'Toko', 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'logo' => null, 'logo_data' => null];
        $data = ['transaction' => $sale, 'store' => $store, 'barcode' => '', 'locale' => $locale];

        return [
            'receipt_58' => view('pdf.receipt_58', $data)->render(),
            'receipt_80' => view('pdf.receipt_80', $data)->render(),
            'thermal' => app(ThermalPrintService::class)->generateReceiptHtml($sale),
        ];
    }

    private function webCheckout(array $extra)
    {
        return $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['grand_total' => 10000, 'cash' => 100000, 'change' => 90000] + $extra);
    }

    private function queued(string $orderName): array
    {
        return [
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440045',
            'items' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_id' => null]],
            'cash' => 100000,
            'order_name' => $orderName,
        ];
    }
}
