<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Receivable;
use App\Services\CustomerSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomAutoSegmentTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $name, array $attributes = []): Customer
    {
        return Customer::create([
            'name' => $name,
            'no_telp' => '6281'.random_int(10000000, 99999999),
            'address' => 'Jl. Uji',
            ...$attributes,
        ]);
    }

    private function segment(string $rule, array $config): CustomerSegment
    {
        return CustomerSegment::create([
            'name' => 'Custom '.$rule,
            'slug' => 'custom-'.$rule,
            'type' => CustomerSegment::TYPE_AUTO,
            'is_active' => true,
            'auto_rule_type' => $rule,
            'rule_config' => $config,
        ]);
    }

    private function memberNames(CustomerSegment $segment): array
    {
        return $segment->customers()->pluck('name')->sort()->values()->all();
    }

    public function test_custom_spending_segment_collects_customers_above_its_threshold(): void
    {
        $segment = $this->segment(CustomerSegment::RULE_SPENDING, ['min_total_spent' => '100000']);
        $this->customer('Besar', ['loyalty_total_spent' => 150000]);
        $this->customer('Kecil', ['loyalty_total_spent' => 50000]);

        app(CustomerSegmentationService::class)->syncAutoSegments();

        $this->assertSame(['Besar'], $this->memberNames($segment));
    }

    public function test_custom_frequency_segment_uses_count_and_recent_days(): void
    {
        $segment = $this->segment(CustomerSegment::RULE_PURCHASE_FREQUENCY, ['min_transaction_count' => '3', 'recent_days' => '30']);
        $this->customer('Rajin', ['loyalty_transaction_count' => 4, 'last_purchase_at' => now()->subDays(5)]);
        $this->customer('Lama', ['loyalty_transaction_count' => 4, 'last_purchase_at' => now()->subDays(60)]);
        $this->customer('Jarang', ['loyalty_transaction_count' => 1, 'last_purchase_at' => now()->subDay()]);

        app(CustomerSegmentationService::class)->syncAutoSegments();

        $this->assertSame(['Rajin'], $this->memberNames($segment));
    }

    public function test_custom_receivable_segment_honours_overdue_only(): void
    {
        $segment = $this->segment(CustomerSegment::RULE_RECEIVABLE_BEHAVIOR, ['require_outstanding_receivable' => true, 'overdue_only' => true]);
        $overdue = $this->customer('Telat');
        $current = $this->customer('Lancar');
        foreach ([[$overdue, now()->subDay(), 'RCV-1'], [$current, now()->addDays(10), 'RCV-2']] as [$customer, $due, $invoice]) {
            Receivable::create(['customer_id' => $customer->id, 'invoice' => $invoice, 'total' => 100000, 'paid' => 0, 'due_date' => $due, 'status' => 'unpaid']);
        }

        app(CustomerSegmentationService::class)->syncAutoSegments();

        $this->assertSame(['Telat'], $this->memberNames($segment));
    }
}
