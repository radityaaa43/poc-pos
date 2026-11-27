<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Spatie\Permission\Models\Permission;

/** Customers, users, receivables and sales for the credit limit tests (REQ-007). */
trait MakesCreditData
{
    private int $seq = 0;

    private function customer(?int $limit = null, bool $mayBuyOnCredit = true): Customer
    {
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => 62800 + ++$this->seq, 'address' => 'Jl. Satu']);
        $customer->forceFill(['may_buy_on_credit' => $mayBuyOnCredit, 'credit_limit' => $limit])->save();

        return $customer;
    }

    private function userWith(string ...$permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return tap(User::factory()->create())->givePermissionTo($permissions);
    }

    private function receivable(?Customer $customer, int $total = 100000, int $paid = 0, array $overrides = []): Receivable
    {
        return Receivable::create($overrides + [
            'customer_id' => $customer?->id,
            'invoice' => 'INV-'.++$this->seq,
            'total' => $total,
            'paid' => $paid,
            'due_date' => today()->addDays(14),
            'status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    private function sale(Warehouse $warehouse): Transaction
    {
        return Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'invoice' => 'TRX-'.++$this->seq,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 100000,
            'payment_method' => 'pay_later',
            'payment_status' => 'unpaid',
        ]);
    }
}
