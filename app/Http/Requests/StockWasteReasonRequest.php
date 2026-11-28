<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The reason a reject or cancel needs; the route's permission and the controller's warehouse check authorize. */
class StockWasteReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => __('messages.waste.reason_required'),
        ];
    }
}
