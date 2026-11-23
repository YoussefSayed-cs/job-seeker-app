<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ResubmitCompanyRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('company_name')) {
            $this->merge([
                'company_name' => trim((string) $this->input('company_name')),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed|string>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string'],
            'industry' => ['required', 'string'],
            'location' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'website' => ['nullable', 'string'],
            'logo' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $protectedFields = [
                'id',
                'owner_id',
                'registration_status',
                'rejection_reason',
                'reviewed_by',
                'reviewed_at',
                'created_at',
                'updated_at',
            ];

            $submittedProtectedFields = array_values(array_intersect(
                $protectedFields,
                array_keys($this->all()),
            ));

            if ($submittedProtectedFields !== []) {
                throw ValidationException::withMessages([
                    'registration' => 'Protected Company registration fields cannot be submitted.',
                ]);
            }
        });
    }
}
