<?php

namespace App\Http\Requests;

use App\Services\BillSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outlet = $this->route('outlet');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('outlets', 'code')->ignore($outlet)],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'is_sales_enabled' => ['boolean'],
            'pay_later_enabled' => ['boolean'],
            'waste_approval_limit' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'service_charge_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'tax_kind' => ['nullable', Rule::in(BillSettingsService::TAX_KINDS)],
            'pb1_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'cash_rounding_unit' => ['nullable', 'integer', Rule::in(BillSettingsService::CASH_ROUNDING_UNITS)],
        ];
    }

    // The app has no translated default validation messages, so the bill fields are worded here (AC-13).
    public function messages(): array
    {
        return [
            'service_charge_rate' => __('messages.outlet_bill.service_charge_rate'),
            'tax_kind' => __('messages.outlet_bill.tax_kind'),
            'pb1_rate' => __('messages.outlet_bill.pb1_rate'),
            'cash_rounding_unit' => __('messages.outlet_bill.cash_rounding_unit'),
        ];
    }
}
