<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Warehouse;
use App\Services\CustomerCreditService;
use App\Support\Credit\CreditStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesCreditData;
use Tests\TestCase;

/** REQ-007 BR-4: Terpakai is the balance left on every unpaid receivable of the customer, across all outlets. */
class CustomerCreditServiceTest extends TestCase
{
    use MakesCreditData, RefreshDatabase;

    private Customer $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->budi = $this->customer(500000);
    }

    private function standing(): CreditStanding
    {
        return app(CustomerCreditService::class)->standing($this->budi->fresh());
    }

    /** @return array<int, string> the SQL run by the callback */
    private function queriesOf(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = array_map('strtolower', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        return $queries;
    }

    public function test_used_sums_receivables_across_warehouses(): void
    {
        foreach (['WH-1', 'WH-2'] as $code) {
            $sale = $this->sale(Warehouse::create(['code' => $code, 'name' => $code]));
            $this->receivable($this->budi, overrides: ['transaction_id' => $sale->id]);
        }

        $this->assertSame(200000, $this->standing()->used);
        $this->assertSame(300000, $this->standing()->remaining());
    }

    public function test_used_counts_only_the_balance_left(): void
    {
        $this->receivable($this->budi);
        $this->receivable($this->budi, paid: 30000);
        $this->receivable($this->budi, paid: 100000);
        $this->receivable($this->budi, 50000, 80000, ['status' => 'partial']); // more paid than owed: adds nothing
        $this->receivable($this->customer()); // someone else's

        $this->assertSame(170000, $this->standing()->used);
    }

    public function test_a_return_lowers_used(): void
    {
        $this->receivable($this->budi)->update(['total' => 60000]); // a sales return lowers the receivable's total

        $this->assertSame(60000, $this->standing()->used);
    }

    public function test_overdue_flag_comes_from_the_due_date_not_the_stored_status(): void
    {
        // The stored "overdue" status is only written when a list is opened.
        $this->receivable($this->budi, overrides: ['due_date' => today()->subDay()]);

        $this->assertTrue($this->standing()->hasOverdue);
    }

    public function test_paid_or_due_today_is_not_overdue(): void
    {
        $this->receivable($this->budi, overrides: ['due_date' => today()]);
        $this->receivable($this->budi, paid: 100000, overrides: ['due_date' => today()->subDay()]);
        $this->receivable($this->budi, paid: 100000, overrides: ['due_date' => today()->subDay(), 'status' => 'partial']);
        $this->receivable($this->budi, overrides: ['due_date' => null]);

        $this->assertFalse($this->standing()->hasOverdue);
    }

    public function test_locked_standing_reads_the_fresh_limit(): void
    {
        DB::table('customers')->where('id', $this->budi->id)->update(['credit_limit' => 900000, 'may_buy_on_credit' => false]);

        $standing = DB::transaction(fn () => app(CustomerCreditService::class)->lockedStanding($this->budi->id));

        $this->assertSame(900000, $standing->limit);
        $this->assertFalse($standing->mayBuyOnCredit);
    }

    public function test_only_the_locked_standing_locks_the_customer_and_the_receivables_are_read_plainly(): void
    {
        $this->receivable($this->budi);
        $customer = $this->budi->fresh();

        $plain = $this->queriesOf(fn () => app(CustomerCreditService::class)->standing($customer));
        $locked = $this->queriesOf(fn () => DB::transaction(fn () => app(CustomerCreditService::class)->lockedStanding($customer->id)));

        // One query for display. SQLite quotes names with ", MySQL (the CI backend job) with `: match both.
        $this->assertCount(1, $plain);
        $this->assertCount(2, $locked);
        $this->assertMatchesRegularExpression('/from [`"]customers[`"]/', $locked[0]);
        $this->assertMatchesRegularExpression('/from [`"]receivables[`"]/', $locked[1]);

        // SQLite renders no lock clause: only the MySQL job can show them.
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringNotContainsString('lock in share mode', $plain[0]);
            $this->assertStringContainsString('for update', $locked[0]);
            // No gap locks on receivables (#483): the customer lock is the mutex.
            $this->assertStringNotContainsString('lock in share mode', $locked[1]);
        }
    }
}
