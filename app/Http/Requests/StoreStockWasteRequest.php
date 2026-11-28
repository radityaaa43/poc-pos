<?php

namespace App\Http\Requests;

use App\Models\StockWaste;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockWasteRequest extends FormRequest
{
    /** The route's permission and the controller's warehouse check do the authorizing. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'reason' => ['required', Rule::in(StockWaste::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'client_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'list', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
