<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

class ApplyRiderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_type' => ['required', 'string', 'in:motorcycle,bicycle,car'],
            'plate_number' => ['nullable', 'string', 'max:20'],
        ];
    }
}
