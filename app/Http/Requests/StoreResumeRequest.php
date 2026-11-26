<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreResumeRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:5120'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $file = $this->file('file');

            if (! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                return;
            }

            $expectedMimeType = match (strtolower($file->getClientOriginalExtension())) {
                'pdf' => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => null,
            };

            if ($expectedMimeType === null || $file->getMimeType() !== $expectedMimeType) {
                $validator->errors()->add('file', 'The file extension and file contents must match a PDF or DOCX file.');
            }
        }];
    }
}
