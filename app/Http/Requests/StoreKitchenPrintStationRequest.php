<?php

namespace App\Http\Requests;

use App\Services\OutletAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKitchenPrintStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route carries the permission middleware
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $outlet = app(OutletAccessService::class)->activeOutlet($this);

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('kitchen_print_stations', 'name')->where('outlet_id', $outlet?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Nama perangkat cetak sudah dipakai di outlet ini'];
    }
}
