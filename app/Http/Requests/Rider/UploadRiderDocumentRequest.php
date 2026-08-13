<?php

namespace App\Http\Requests\Rider;

use Illuminate\Foundation\Http\FormRequest;

class UploadRiderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:national_id,driver_license,guarantor,passport_photo'],
            'storage_key' => ['required', 'string', 'max:500'],
            'mime' => ['nullable', 'string', 'max:100'],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
