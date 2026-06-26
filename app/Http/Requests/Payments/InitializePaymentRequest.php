<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'email' => ['nullable', 'email'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
