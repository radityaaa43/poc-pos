<?php

namespace Tests\Feature\Receivables;

use App\Models\Outlet;
use App\Models\Receivable;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesCreditData;
use Tests\TestCase;

/** REQ-007 AC-07: Batas and Terpakai % on the receivables list, and the three credit filters. */
class ReceivableCreditUsageTest extends TestCase
{
    use MakesCreditData, RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // refusals render the error page
        $this->viewer = $this->userWith('receivables-access');
        Warehouse::create(['code' => 'WH-1', 'name' => 'Gudang 1']); // no warehouse, no rows
    }

    /** The listed rows, keyed by invoice. */
    private function rows(array $query = []): Collection
    {
        $rows = collect();

        $this->actingAs($this->viewer)
            ->get(route('receivables.index', $query + ['per_page' => 100]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = collect($page->toArray()['props']['receivables']['data'])->keyBy('invoice');
            });

        return $rows;
    }

    private function listed(array $query): array
    {
        return $this->rows($query)->keys()->sort()->values()->all();
    }

    private function invoices(Receivable ...$receivables): array
    {
        return collect($receivables)->pluck('invoice')->sort()->values()->all();
    }

    public function test_rows_carry_limit_used_and_percent(): void
    {
        $customer = $this->customer(1000000);
        $first = $this->receivable($customer, 500000);
        $second = $this->receivable($customer, 300000);

        $rows = $this->rows();

        foreach ([$first, $second] as $receivable) {
            $this->assertSame(
                ['limit' => 1000000, 'used' => 800000, 'percent' => 80, 'state' => 'near'],
                $rows[$receivable->invoice]['credit']
            );
        }
    }

    public function test_no_limit_row_has_no_percent(): void
    {
        $receivable = $this->receivable($this->customer(), 300000);

        $this->assertSame(
            ['limit' => null, 'used' => 300000, 'percent' => null, 'state' => 'none'],
            $this->rows()[$receivable->invoice]['credit']
        );
    }

    public function test_limit_zero_is_over_only_when_something_is_owed(): void
    {
        $owes = $this->receivable($this->customer(0), 1000);
        $settled = $this->receivable($this->customer(0), 1000, 1000);

        $rows = $this->rows();

        $this->assertSame(['limit' => 0, 'used' => 1000, 'percent' => null, 'state' => 'over'], $rows[$owes->invoice]['credit']);
        $this->assertSame(['limit' => 0, 'used' => 0, 'percent' => null, 'state' => 'ok'], $rows[$settled->invoice]['credit']);
        $this->assertSame($this->invoices($owes), $this->listed(['credit' => 'over']));
        $this->assertSame($this->invoices($owes), $this->listed(['credit' => 'near']));
    }

    /** @return array<string, array{int, int, string}> */
    public static function boundaries(): array
    {
        return [
            '79 percent' => [790000, 79, 'ok'],
            '79.6 percent is rounded down' => [796000, 79, 'ok'],
            '80 percent' => [800000, 80, 'near'],
            '100 percent is not over' => [1000000, 100, 'near'],
            'one rupiah over' => [1000001, 100, 'over'],
            '101 percent' => [1010000, 101, 'over'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_state_boundaries(int $owed, int $percent, string $state): void
    {
        $receivable = $this->receivable($this->customer(1000000), $owed);

        $credit = $this->rows()[$receivable->invoice]['credit'];

        $this->assertSame([$percent, $state], [$credit['percent'], $credit['state']]);
        // The filters agree with the label.
        $this->assertCount($state === 'ok' ? 0 : 1, $this->listed(['credit' => 'near']));
        $this->assertCount($state === 'over' ? 1 : 0, $this->listed(['credit' => 'over']));
    }

    public function test_used_counts_only_what_is_left_and_a_return_lowers_it(): void
    {
        $customer = $this->customer(1000000);
        $partial = $this->receivable($customer, 500000, 200000);
        $this->receivable($customer, 400000, 400000);
        $this->receivable($customer, 100000, 150000, ['status' => 'partial']); // more paid than owed: adds nothing

        $this->assertSame(30, $this->rows()[$partial->invoice]['credit']['percent']);

        $partial->update(['total' => 300000]); // a sales return lowers the receivable's total

        $this->assertSame(10, $this->rows()[$partial->invoice]['credit']['percent']);
    }

    public function test_receivable_without_customer_has_no_credit(): void
    {
        $receivable = $this->receivable(null);

        $this->assertNull($this->rows()[$receivable->invoice]['credit']);
        foreach (['near', 'over', 'none'] as $filter) {
            $this->assertSame([], $this->listed(['credit' => $filter]), $filter);
        }
    }

    public function test_a_customer_who_may_not_buy_on_credit_and_has_no_limit_is_not_shown_as_without_a_limit(): void
    {
        $receivable = $this->receivable($this->customer(mayBuyOnCredit: false));

        $this->assertNull($this->rows()[$receivable->invoice]['credit']);
        $this->assertSame([], $this->listed(['credit' => 'none']));
    }

    public function test_revoked_customer_rows_keep_their_figures(): void
    {
        $receivable = $this->receivable($this->customer(100000, mayBuyOnCredit: false), 90000);

        $this->assertSame(90, $this->rows()[$receivable->invoice]['credit']['percent']);
        $this->assertSame([], $this->listed(['credit' => 'none']));
        $this->assertSame($this->invoices($receivable), $this->listed(['credit' => 'near']));
    }

    public function test_each_filter_lists_only_its_customers_and_near_includes_over(): void
    {
        $this->receivable($this->customer(100000), 50000);
        $near = $this->receivable($this->customer(100000), 80000);
        // Over only through the sum of two receivables.
        $split = $this->customer(100000);
        $first = $this->receivable($split, 60000);
        $second = $this->receivable($split, 60000);
        $open = $this->receivable($this->customer(), 120000);
        $settled = $this->receivable($this->customer(), 50000, 50000);

        $this->assertSame($this->invoices($near, $first, $second), $this->listed(['credit' => 'near']));
        $this->assertSame($this->invoices($first, $second), $this->listed(['credit' => 'over']));
        $this->assertSame($this->invoices($open, $settled), $this->listed(['credit' => 'none']));
    }

    public function test_credit_filter_combines_with_status(): void
    {
        $customer = $this->customer(100000);
        $open = $this->receivable($customer, 90000);
        $paid = $this->receivable($customer, 20000, 20000);
        $this->receivable($this->customer(), 20000, 20000);

        $this->assertSame($this->invoices($open, $paid), $this->listed(['credit' => 'near']));
        $this->assertSame($this->invoices($paid), $this->listed(['credit' => 'near', 'status' => 'paid']));
    }

    public function test_credit_filter_survives_pagination(): void
    {
        $customer = $this->customer(100000);
        foreach (range(1, 6) as $i) {
            $this->receivable($customer, 20000);
        }
        $this->receivable($this->customer(), 20000);

        $this->actingAs($this->viewer)
            ->get(route('receivables.index', ['credit' => 'near', 'per_page' => 5]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.credit', 'near')
                ->where('receivables.total', 6)
                ->where('receivables.next_page_url', fn (string $url) => str_contains($url, 'credit=near')));
    }

    public function test_unknown_credit_value_is_ignored(): void
    {
        $this->receivable($this->customer(100000), 20000);
        $this->receivable($this->customer(), 20000);

        $this->actingAs($this->viewer)
            ->get(route('receivables.index', ['credit' => 'semua']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.credit', null)->where('receivables.total', 2));
    }

    public function test_credit_columns_need_receivables_access(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('receivables.index', ['credit' => 'near']))
            ->assertForbidden();
    }

    public function test_rows_are_outlet_scoped_but_used_is_customer_wide(): void
    {
        $customer = $this->customer(1000000);
        $invoices = [];
        foreach (['A' => 300000, 'B' => 600000] as $code => $total) {
            $outlet = Outlet::create(['code' => "OUT-{$code}", 'name' => "Outlet {$code}", 'is_active' => true]);
            $warehouse = Warehouse::create(['code' => "WH-{$code}", 'name' => "Gudang {$code}", 'outlet_id' => $outlet->id]);
            $sale = $this->sale($warehouse);
            $invoices[$code] = $this->receivable($customer, $total, overrides: ['transaction_id' => $sale->id])->invoice;
            if ($code === 'A') {
                $this->viewer->outlets()->attach($outlet->id, ['is_default' => true]);
            }
        }

        $rows = $this->rows();

        $this->assertSame([$invoices['A']], $rows->keys()->all());
        $this->assertSame(900000, $rows[$invoices['A']]['credit']['used']);
    }

    public function test_credit_columns_add_a_constant_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->rows();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $this->receivable($this->customer(100000), 20000);
        $this->receivable($this->customer(100000), 20000);
        $count(); // warm the permission cache and the viewer's relations
        $two = $count();

        foreach (range(1, 10) as $i) {
            $this->receivable($this->customer(100000), 20000);
        }

        $this->assertSame($two, $count());
    }
}
