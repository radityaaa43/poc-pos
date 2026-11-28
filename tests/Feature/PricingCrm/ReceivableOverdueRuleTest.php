<?php

namespace Tests\Feature\PricingCrm;

use App\Models\Receivable;
use Tests\TestCase;

// REQ-007 BR-4 (2026-11-29): overdue means the due date has passed before today; a receivable due today is current.
class ReceivableOverdueRuleTest extends TestCase
{
    public function test_a_receivable_due_today_is_not_overdue(): void
    {
        $receivable = new Receivable(['due_date' => today(), 'status' => 'partial']);

        $this->assertSame('current', $receivable->aging_bucket);
    }

    public function test_a_receivable_due_yesterday_is_overdue(): void
    {
        $receivable = new Receivable(['due_date' => today()->subDay(), 'status' => 'partial']);

        $this->assertSame('0-30', $receivable->aging_bucket);
    }
}
