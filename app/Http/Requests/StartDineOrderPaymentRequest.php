<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartDineOrderPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public: the order token in the URL is the only credential.
        return true;
    }

    /** Only the method. The amount is never read: the server charges the bill's remaining total. */
    public function rules(): array
    {
        return [
            'method' => ['nullable', 'in:qris,gateway'],
        ];
    }
}
