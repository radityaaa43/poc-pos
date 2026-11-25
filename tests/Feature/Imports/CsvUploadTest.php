<?php

namespace Tests\Feature\Imports;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $user = User::factory()->create();
        $user->givePermissionTo(['customers-import', 'products-import']);

        return $user;
    }

    // The validator sniffs the content, and libmagic can call a small CSV text/plain (it does on
    // some hosts); UploadedFile::fake() would guess from the extension instead, so pin the sniff.
    private function sniffedAsPlainText(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, "nama,telepon,alamat\nBudi,081200000001,Jl. Contoh 1\n");

        return new class($path, $name, null, null, true) extends UploadedFile
        {
            public function getMimeType(): string
            {
                return 'text/plain';
            }

            public function guessExtension(): string
            {
                return 'txt';
            }
        };
    }

    public function test_a_csv_sniffed_as_plain_text_is_accepted_by_the_customer_import(): void
    {
        $this->actingAs($this->admin())
            ->post(route('import.customers'), ['file' => $this->sniffedAsPlainText('customer.csv')])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Customer::where('name', 'Budi')->count());
    }

    public function test_a_file_with_another_extension_is_still_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('import.customers'), ['file' => $this->sniffedAsPlainText('customer.pdf')])
            ->assertSessionHasErrors('file');
    }
}
