<?php

namespace Tests\Feature\Transactions;

use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_checkout_creates_split_tenders(): void
    {
        [$cashier, $product, $bankAccount] = $this->prepareCheckout();

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'tenders' => [
                    ['method' => 'cash', 'amount' => 5000, 'cash_received' => 6000],
                    ['method' => 'bank_transfer', 'amount' => 5000, 'bank_account_id' => $bankAccount->id],
                ],
            ])
            ->assertRedirect();

        $transaction = Transaction::latest('id')->first();

        $this->assertSame('split', $transaction->payment_method);
        $this->assertSame('pending', $transaction->payment_status);
        $this->assertSame(6000, $transaction->cash);
        $this->assertSame(1000, $transaction->change);
        $this->assertCount(2, $transaction->tenders);
        $this->assertSame(5000, $transaction->tenders[0]->amount);
        $this->assertSame(5000, $transaction->tenders[1]->amount);
        $this->assertSame('paid', $transaction->tenders[0]->payment_status);
        $this->assertSame('pending', $transaction->tenders[1]->payment_status);
    }

    public function test_confirming_the_transfer_pays_the_split_sale(): void
    {
        [$cashier, $product, $bankAccount] = $this->prepareCheckout();
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'tenders' => [
                ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
                ['method' => 'bank_transfer', 'amount' => 5000, 'bank_account_id' => $bankAccount->id],
            ],
        ]);
        $transaction = Transaction::latest('id')->first();
        $this->assertSame('pending', $transaction->payment_status);

        Permission::firstOrCreate(['name' => 'transactions-confirm-payment', 'guard_name' => 'web']);
        $cashier->givePermissionTo('transactions-confirm-payment');

        $this->actingAs($cashier)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $transaction))
            ->assertSessionHas('success');

        $transaction->refresh();
        $this->assertSame('paid', $transaction->payment_status);
        $this->assertSame(['paid', 'paid'], $transaction->tenders->pluck('payment_status')->all());
    }

    public function test_pending_split_counts_only_its_cash_part_in_the_shift_drawer(): void
    {
        [$cashier, $product, $bankAccount] = $this->prepareCheckout();
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'tenders' => [
                ['method' => 'cash', 'amount' => 5000, 'cash_received' => 6000],
                ['method' => 'bank_transfer', 'amount' => 5000, 'bank_account_id' => $bankAccount->id],
            ],
        ]);
        $shift = app(CashierShiftService::class)->getActiveShiftForUser($cashier->id);

        $summary = app(CashierShiftService::class)->calculateSummary($shift);

        $this->assertSame(5000, $summary['cash_sales_total']);
        $this->assertSame(5000, $summary['non_cash_sales_total']);
        $this->assertSame(5000, $summary['expected_cash']);
    }

    public function test_single_bank_transfer_stays_pending_until_confirmed(): void
    {
        [$cashier, $product, $bankAccount] = $this->prepareCheckout();
        PaymentSetting::create(['default_gateway' => 'bank_transfer', 'bank_transfer_enabled' => true]);
        $this->actingAs($cashier)->post(route('transactions.store'), [
            'payment_gateway' => 'bank_transfer',
            'bank_account_id' => $bankAccount->id,
        ]);
        $transaction = Transaction::latest('id')->first();

        $this->assertSame('bank_transfer', $transaction->payment_method);
        $this->assertSame('pending', $transaction->payment_status);
        $this->assertCount(0, $transaction->tenders);
    }

    public function test_web_checkout_rejects_tenders_above_two_rows(): void
    {
        [$cashier, $product, $bankAccount] = $this->prepareCheckout();

        $response = $this->actingAs($cashier)->post(route('transactions.store'), [
            'tenders' => [
                ['method' => 'cash', 'amount' => 3000],
                ['method' => 'cash', 'amount' => 3000],
                ['method' => 'cash', 'amount' => 4000],
            ],
        ]);

        $response->assertSessionHasErrors('tenders');
        $this->assertSame(0, Transaction::count());
    }

    private function prepareCheckout(): array
    {
        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $cashier = User::factory()->create();
        $cashier->markEmailAsVerified();
        $cashier->givePermissionTo('transactions-access');

        $warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Utama',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        app(CashierShiftService::class)->openShift($cashier, $cashier, 0, null, $warehouse->id);

        $category = Category::create(['name' => 'Test', 'image' => '', 'description' => '']);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => '',
            'barcode' => 'SPLIT-'.Str::random(8),
            'sku' => 'SPLIT-'.Str::random(8),
            'title' => 'Produk Split',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 10,
            'tax_rate' => 0,
        ]);
        $warehouse->products()->attach($product->id, ['stock' => 10]);
        Cart::create(['cashier_id' => $cashier->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 10000]);

        $bankAccount = BankAccount::create([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_name' => 'Toko Test',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return [$cashier, $product, $bankAccount];
    }
}
