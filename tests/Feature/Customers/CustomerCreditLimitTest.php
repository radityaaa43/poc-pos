<?php

namespace Tests\Feature\Customers;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesCreditData;
use Tests\TestCase;

/** REQ-007 AC-01, AC-02, AC-03 (summary) and AC-08: setting, auditing and reading a customer's credit limit. */
class CustomerCreditLimitTest extends TestCase
{
    use MakesCreditData, RefreshDatabase;

    private const EVENT = 'customer.credit_limit_updated';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // refusals render the error page
    }

    public function test_approver_sets_and_clears_the_limit_and_zero_stays_zero(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->userWith('customers-credit-approve'));

        // An emptied field arrives as an empty string: no limit. 0 is a limit of its own (no credit).
        foreach ([[2000000, 2000000], [0, 0], ['', null]] as [$sent, $stored]) {
            $this->patch(route('customers.credit-limit', $customer), ['credit_limit' => $sent])
                ->assertRedirect()
                ->assertSessionHas('success');
            $this->assertSame($stored, $customer->fresh()->credit_limit);
        }
    }

    public function test_user_without_permission_is_refused_and_limit_unchanged(): void
    {
        $customer = $this->customer(500000);

        $this->actingAs($this->userWith('customers-access', 'customers-edit', 'transactions-access'))
            ->patchJson(route('customers.credit-limit', $customer), ['credit_limit' => 9000000])
            ->assertForbidden();

        $this->assertSame(500000, $customer->fresh()->credit_limit);
        $this->assertSame(0, AuditLog::where('event', self::EVENT)->count());
    }

    public function test_limit_refused_for_customer_who_may_not_buy_on_credit(): void
    {
        $customer = $this->customer(mayBuyOnCredit: false);

        $this->actingAs($this->userWith('customers-credit-approve'))
            ->patchJson(route('customers.credit-limit', $customer), ['credit_limit' => 100000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credit_limit');

        $this->assertNull($customer->fresh()->credit_limit);
        $this->assertSame(0, AuditLog::where('event', self::EVENT)->count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidLimits(): array
    {
        return [
            'negative' => [['credit_limit' => -1]],
            'decimal' => [['credit_limit' => 1000.5]],
            'text' => [['credit_limit' => 'banyak']],
            'above the maximum' => [['credit_limit' => 1000000000000]],
            'key missing' => [[]],
        ];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_limits_are_refused(array $payload): void
    {
        $customer = $this->customer(500000);

        $this->actingAs($this->userWith('customers-credit-approve'))
            ->patchJson(route('customers.credit-limit', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credit_limit');

        $this->assertSame(500000, $customer->fresh()->credit_limit);
        $this->assertSame(0, AuditLog::where('event', self::EVENT)->count());
    }

    public function test_same_value_writes_no_audit_row(): void
    {
        $customer = $this->customer(500000);

        $this->actingAs($this->userWith('customers-credit-approve'))
            ->patch(route('customers.credit-limit', $customer), ['credit_limit' => '500000'])
            ->assertRedirect();

        $this->assertSame(0, AuditLog::where('event', self::EVENT)->count());
    }

    public function test_change_is_audited_with_old_and_new(): void
    {
        $customer = $this->customer();
        $approver = $this->userWith('customers-credit-approve');
        $this->actingAs($approver);

        foreach ([1000000, 2000000, null] as $limit) {
            $this->patch(route('customers.credit-limit', $customer), ['credit_limit' => $limit])->assertRedirect();
        }

        $logs = AuditLog::where('event', self::EVENT)->orderBy('id')->get();
        $this->assertSame(
            [[null, 1000000], [1000000, 2000000], [2000000, null]],
            $logs->map(fn (AuditLog $log) => [$log->before['credit_limit'], $log->after['credit_limit']])->all()
        );
        foreach ($logs as $log) {
            $this->assertSame($approver->id, $log->user_id);
            $this->assertSame($customer->id, $log->auditable_id);
            $this->assertNotNull($log->created_at);
        }
    }

    public function test_limit_below_usage_is_accepted(): void
    {
        $customer = $this->customer();
        $this->receivable($customer, 300000);

        $this->actingAs($this->userWith('customers-credit-approve'))
            ->patch(route('customers.credit-limit', $customer), ['credit_limit' => 100000])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(100000, $customer->fresh()->credit_limit);
        $this->actingAs($this->userWith('transactions-access'))
            ->getJson(route('customers.credit-summary', $customer))
            ->assertJson(['used' => 300000, 'remaining' => 0]);
    }

    public function test_the_limit_cannot_be_changed_through_the_regular_edit_form(): void
    {
        $customer = $this->customer(500000);

        $this->actingAs($this->userWith('customers-edit'))
            ->put(route('customers.update', $customer), [
                'name' => 'Budi',
                'no_telp' => '62811',
                'address' => 'Jl. Satu',
                'credit_limit' => 9000000,
            ]);

        $this->assertSame(500000, $customer->fresh()->credit_limit);
    }

    public function test_credit_summary_returns_figures(): void
    {
        $customer = $this->customer(500000);
        $this->receivable($customer, 300000);

        $this->actingAs($this->userWith('transactions-access'))
            ->getJson(route('customers.credit-summary', $customer))
            ->assertOk()
            ->assertExactJson([
                'may_buy_on_credit' => true,
                'credit_limit' => 500000,
                'used' => 300000,
                'remaining' => 200000,
                'has_overdue' => false,
            ]);
    }

    public function test_credit_summary_without_a_limit_or_without_the_credit_flag(): void
    {
        $this->actingAs($this->userWith('transactions-access'));

        $this->getJson(route('customers.credit-summary', $this->customer()))
            ->assertExactJson([
                'may_buy_on_credit' => true,
                'credit_limit' => null,
                'used' => 0,
                'remaining' => null,
                'has_overdue' => false,
            ]);
        $this->getJson(route('customers.credit-summary', $this->customer(mayBuyOnCredit: false)))
            ->assertJson(['may_buy_on_credit' => false]);
    }

    public function test_credit_summary_needs_transaction_access(): void
    {
        $this->actingAs($this->userWith('customers-access'))
            ->getJson(route('customers.credit-summary', $this->customer()))
            ->assertForbidden();
    }

    public function test_detail_page_carries_the_standing_for_credit_customers_only(): void
    {
        $credit = $this->customer(500000);
        $this->receivable($credit, 300000);
        $this->actingAs($this->userWith('customers-access'));

        $this->get(route('customers.show', $credit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('creditStanding.credit_limit', 500000)
                ->where('creditStanding.used', 300000));

        $this->get(route('customers.show', $this->customer(mayBuyOnCredit: false)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('creditStanding', null));
    }

    public function test_release_leaves_every_credit_customer_without_a_limit(): void
    {
        $customer = $this->customer(500000);
        $this->receivable($customer, 300000);

        // As on release day: the column is added to existing credit customers with running receivables.
        $migration = require database_path('migrations/2026_12_09_100001_add_credit_limit_to_customers_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('customers', 'credit_limit'));
        $migration->up();

        $row = DB::table('customers')->where('id', $customer->id)->first();
        $this->assertNull($row->credit_limit);
        $this->assertTrue((bool) $row->may_buy_on_credit);
    }

    public function test_the_receivables_index_migration_runs_down_then_up(): void
    {
        $migration = require database_path('migrations/2026_12_09_100002_add_customer_status_index_to_receivables_table.php');

        $this->assertTrue(Schema::hasIndex('receivables', 'receivables_customer_status_index'));
        $migration->down();
        $this->assertFalse(Schema::hasIndex('receivables', 'receivables_customer_status_index'));
        $migration->up();
        $this->assertTrue(Schema::hasIndex('receivables', 'receivables_customer_status_index'));
    }
}
