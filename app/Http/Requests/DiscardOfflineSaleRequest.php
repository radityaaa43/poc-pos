<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscardOfflineSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route carries the permission middleware
    }

    public function rules(): array
    {
        // Bounded: validated() goes into the audit log as is. The rules stay looser than the sync's (no "exists",
        // no "min"): a sale stuck because of a bad or vanished value must still be removable.
        $number = ['nullable', 'numeric', 'between:-999999999999999,999999999999999'];

        return [
            'client_uuid' => ['required', 'uuid'],
            'queue_status' => ['required', 'in:failed,conflict,discarding'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attempts' => ['required', 'integer', 'min:0'],
            'queued_at' => ['required', 'date'],
            'sale' => ['required', 'array'],
            'sale.grand_total' => ['required', 'integer'],
            'sale.payment_method' => ['required', 'string', 'max:30'],
            'sale.customer_id' => ['nullable', 'integer'],
            'sale.customer_voucher_id' => ['nullable', 'integer'],
            'sale.discount' => $number,
            'sale.redeem_points' => $number,
            'sale.shipping_cost' => $number,
            'sale.cash' => $number,
            'sale.bank_account_id' => ['nullable', 'integer'],
            'sale.order_type' => ['nullable', 'string', 'max:30'],
            'sale.note' => ['nullable', 'string', 'max:1000'],
            // Only on entries queued before pay later stopped working offline.
            'sale.pay_later' => ['nullable', 'boolean'],
            'sale.due_date' => ['nullable', 'string', 'max:30'],
            'sale.items' => ['required', 'array', 'min:1', 'max:200'],
            'sale.items.*.product_id' => ['required', 'integer'],
            'sale.items.*.unit_id' => ['nullable', 'integer'],
            'sale.items.*.qty' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
