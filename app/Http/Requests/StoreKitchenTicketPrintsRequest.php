<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKitchenTicketPrintsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route carries the permission middleware
    }

    public function rules(): array
    {
        // No "exists": ids of other outlets or gone items are ignored by the service, so a retry never 422s forever.
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['integer', 'min:1'],
        ];
    }
}
