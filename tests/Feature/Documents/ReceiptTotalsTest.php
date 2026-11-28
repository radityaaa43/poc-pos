<?php

namespace Tests\Feature\Documents;

use App\Exports\TransactionsExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ThermalPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every document that derives a subtotal takes the service charge out of it and names the tax by its kind (#450).
 */
class ReceiptTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function sale(array $bill): Transaction
    {
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $product = Product::create([
            'title' => 'Nasi', 'barcode' => 'RT-1', 'sku' => 'RT-1', 'image' => '', 'description' => '',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 5, 'category_id' => $category->id,
        ]);
        $sale = Transaction::create($bill + [
            'cashier_id' => User::factory()->create()->id, 'invoice' => 'TRX-TOTALS', 'access_token' => 'token',
            'cash' => 25000, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $sale->details()->create(['product_id' => $product->id, 'qty' => 2, 'price' => 20000, 'unit_price' => 10000, 'base_unit_price' => 10000]);

        return $sale->load('details.product', 'cashier', 'customer', 'warehouse.outlet');
    }

    /** Each document as one line of text. */
    private function documents(Transaction $sale): array
    {
        $data = [
            'transaction' => $sale, 'barcode' => '', 'locale' => 'id',
            'store' => ['name' => 'Toko', 'logo' => null, 'logo_data' => null, 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'city' => ''],
        ];
        $text = fn (string $html) => trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<style.*?</style>#s', '', $html))));

        return [
            '58' => $text(view('pdf.receipt_58', $data)->render()),
            '80' => $text(view('pdf.receipt_80', $data)->render()),
            'invoice' => $text(view('pdf.invoice', $data)->render()),
            'thermal' => $text(app(ThermalPrintService::class)->generateReceiptText($sale)),
        ];
    }

    public function test_service_and_pb1_lines(): void
    {
        $sale = $this->sale([
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
        ]);

        foreach ($this->documents($sale) as $name => $text) {
            $this->assertMatchesRegularExpression('/Subtotal (Rp )?20\.000/', $text, $name);
            $this->assertMatchesRegularExpression('/Service charge (\+ )?(Rp )?1\.000/', $text, $name);
            $this->assertMatchesRegularExpression('/PB1 10% (\+ )?(Rp )?2\.100/', $text, $name);
            $this->assertStringNotContainsString('PPN', $text, $name);
        }

        $row = (new TransactionsExport(new Request))->map($sale);
        $this->assertSame([20000, 0, 0, 1000, 'PB1', 2100, 23100], array_slice($row, 6));
    }

    public function test_old_sale_renders_as_before(): void
    {
        // A sale made before release: no tax kind and no service charge.
        $sale = $this->sale(['tax_rate' => 11, 'tax_total' => 2200, 'grand_total' => 22200]);

        foreach ($this->documents($sale) as $name => $text) {
            $this->assertMatchesRegularExpression('/Subtotal (Rp )?20\.000/', $text, $name);
            $this->assertStringContainsString('PPN', $text, $name);
            $this->assertStringNotContainsString('Service charge', $text, $name);
            $this->assertStringNotContainsString('PB1', $text, $name);
        }

        $row = (new TransactionsExport(new Request))->map($sale);
        $this->assertSame([20000, 0, 0, 0, 'PPN', 2200, 22200], array_slice($row, 6));
    }

    public function test_public_invoice_page_gets_the_bill_lines(): void
    {
        $sale = $this->sale([
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
        ]);

        $this->get(route('portal.transaction', [$sale->invoice, 'token' => 'token']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transaction.service_charge', 1000)
                ->where('transaction.tax_kind', 'pb1')
                ->where('transaction.tax_rate', 10));
    }
}
