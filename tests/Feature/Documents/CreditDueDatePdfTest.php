<?php

namespace Tests\Feature\Documents;

use App\Models\Receivable;
use App\Models\Transaction;
use Tests\TestCase;

class CreditDueDatePdfTest extends TestCase
{
    /** #441 BR-1: both credit PDFs print the due date as dd/mm/yyyy. The views are rendered, as a PDF cannot be read back. */
    public function test_credit_pdfs_show_the_due_date_as_dd_mm_yyyy(): void
    {
        $receivable = new Receivable(['invoice' => 'INV-DUE', 'total' => 10000, 'paid' => 0, 'due_date' => '2026-12-05']);
        $sale = (new Transaction(['invoice' => 'INV-DUE', 'grand_total' => 10000]))
            ->setRelation('receivable', $receivable)->setRelation('details', collect())
            ->setRelation('cashier', null)->setRelation('customer', null)->setRelation('warehouse', null);
        $receivable->setRelation('transaction', $sale)->setRelation('customer', null)->setRelation('payments', collect());
        $data = [
            'store' => ['name' => 'Toko', 'logo' => null, 'logo_data' => null, 'address' => '', 'phone' => '', 'email' => '', 'website' => ''],
            'barcode' => '',
        ];

        $this->assertStringContainsString(
            '<strong>Jatuh tempo:</strong> 05/12/2026',
            view('pdf.invoice', $data + ['transaction' => $sale])->render(),
        );
        $this->assertStringContainsString(
            'Jatuh tempo: 05/12/2026',
            view('pdf.receivable', $data + ['receivable' => $receivable])->render(),
        );
    }
}
