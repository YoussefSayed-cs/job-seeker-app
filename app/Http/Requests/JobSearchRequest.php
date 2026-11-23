<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JobSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'location'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([
                    $field => $value === '' ? null : $value,
                ]);
            }
        }

        if ($this->has('job_category_id')) {
            $value = trim((string) $this->input('job_category_id'));
            $this->merge([
                'job_category_id' => $value === '' ? null : $value,
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed|string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'job_category_id' => [
                'nullable',
                'uuid',
                Rule::exists('job_categories', 'id'),
            ],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
