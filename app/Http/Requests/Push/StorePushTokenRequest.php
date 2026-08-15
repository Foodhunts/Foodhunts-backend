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
            'device_id' => ['required', 'string', 'max:255'],
            'expo_push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios,android,web'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ];
    }
}
