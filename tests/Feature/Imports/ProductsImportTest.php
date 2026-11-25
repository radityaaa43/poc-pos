<?php

namespace Tests\Feature\Imports;

use App\Imports\ProductsImport;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ProductsImportTest extends TestCase
{
    use RefreshDatabase;

    private function importCsv(string $rows): ProductsImport
    {
        $path = tempnam(sys_get_temp_dir(), 'products').'.csv';
        file_put_contents($path, "barcode,nama,kategori,harga_beli,harga_jual,stok,tarif_pajak\n".$rows);

        $import = new ProductsImport;
        Excel::import($import, $path);
        unlink($path);

        return $import;
    }

    public function test_import_creates_a_new_product(): void
    {
        $import = $this->importCsv("8990001,Kopi Susu,Minuman,8000,15000,10,0\n");

        $this->assertSame(1, $import->getRowCount());
        $this->assertDatabaseHas('products', ['barcode' => '8990001', 'title' => 'Kopi Susu', 'sell_price' => 15000]);
    }

    public function test_import_updates_the_product_with_the_same_barcode(): void
    {
        $this->importCsv("8990001,Kopi Susu,Minuman,8000,15000,10,0\n");

        $this->importCsv("8990001,Kopi Susu Besar,Minuman,9000,18000,10,0\n");

        $this->assertSame(1, Product::where('barcode', '8990001')->count());
        $this->assertDatabaseHas('products', ['barcode' => '8990001', 'title' => 'Kopi Susu Besar', 'sell_price' => 18000]);
    }
}
