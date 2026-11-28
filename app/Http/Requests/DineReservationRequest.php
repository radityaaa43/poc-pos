<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DineReservationRequest extends FormRequest
{
    // The route's permission:dine-reservations-manage middleware authorizes.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guest_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\- ()]{6,30}$/'],
            'party_size' => ['required', 'integer', 'between:1,100'],
            'reserved_date' => ['required', 'date_format:Y-m-d'],
            'reserved_time' => ['required', 'date_format:H:i'],
            'dine_table_id' => ['required', 'integer', Rule::exists('dine_tables', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    // There is no Indonesian validation file, so the errors a user can reach are translated here.
    public function messages(): array
    {
        return [
            'required' => __('messages.dine_reservations.required'),
            'phone.regex' => __('messages.dine_reservations.invalid_phone'),
            'party_size.between' => __('messages.dine_reservations.invalid_party_size'),
            'dine_table_id.exists' => __('messages.dine_reservations.table_unavailable'),
        ];
    }
}
