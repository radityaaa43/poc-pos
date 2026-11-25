<?php

namespace Tests\Feature\Documents;

use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_pdf_renders_in_both_sizes(): void
    {
        $outlet = Outlet::create(['code' => 'RCP', 'name' => 'Receipt']);
        $warehouse = Warehouse::create([
            'code' => 'WH-RCP',
            'name' => 'Warehouse',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 0,
            'outlet_id' => $outlet->id,
        ]);
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->outlets()->attach($outlet->id, ['is_default' => true]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']));
        $transaction = Transaction::create([
            'cashier_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'invoice' => 'INV-RECEIPT-PDF',
            'cash' => 10000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 10000,
            'total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        foreach (['58', '80'] as $size) {
            $this->actingAs($user)
                ->get(route('pdf.transactions.receipt', [$transaction->invoice, $size]))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }

        // 80mm is the route default
        $this->actingAs($user)
            ->get(route('pdf.transactions.receipt', $transaction->invoice))
            ->assertOk();
    }
}
