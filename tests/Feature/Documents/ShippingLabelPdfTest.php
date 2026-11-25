<?php

namespace Tests\Feature\Documents;

use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShippingLabelPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_label_prints_for_a_sale_without_a_customer(): void
    {
        $outlet = Outlet::create(['code' => 'SHP', 'name' => 'Shipping']);
        $warehouse = Warehouse::create([
            'code' => 'WH-SHP',
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
            'customer_id' => null,
            'invoice' => 'INV-SHIP-WALKIN',
            'cash' => 10000,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 10000,
            'total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($user)
            ->get(route('pdf.transactions.shipping', $transaction->invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
