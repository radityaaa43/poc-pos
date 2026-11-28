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
        // Bounded: validated() goes into the audit log as is. No "exists": a sale that is stuck because its
        // product or customer is gone must still be removable.
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
            'sale.items' => ['required', 'array', 'min:1', 'max:200'],
            'sale.items.*.product_id' => ['required', 'integer'],
            'sale.items.*.unit_id' => ['nullable', 'integer'],
            'sale.items.*.qty' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
