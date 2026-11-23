<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed|string>>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'logo' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'name' => ['prohibited'],
            'industry' => ['prohibited'],
            'id' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'registration_status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
