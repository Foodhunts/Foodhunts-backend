<?php

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;

class StorePushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios,android,web'],
        ];
    }
}
