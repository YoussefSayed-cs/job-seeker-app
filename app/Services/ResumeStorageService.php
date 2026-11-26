<?php

namespace App\Services;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ResumeStorageService
{
    private ?UploadApi $uploadApi = null;

    public function upload(UploadedFile $file, string $folder, string $name): string
    {
        $extension = $file->getClientOriginalExtension();

        if ($extension === '') {
            throw new RuntimeException('The resume file must have an extension.');
        }

        $response = $this->cloudinaryUploadApi()->upload($file->getRealPath(), [
            'public_id' => $this->publicId($folder, $name, $extension),
            'resource_type' => 'raw',
            'type' => 'authenticated',
        ]);

        return $this->responsePublicId($response);
    }

    public function copy(string $sourcePublicId, string $folder, string $name): string
    {
        $extension = pathinfo($sourcePublicId, PATHINFO_EXTENSION);

        if ($extension === '') {
            throw new RuntimeException('The source resume public ID must include its file extension.');
        }

        $response = $this->cloudinaryUploadApi()->upload($this->signedUrl($sourcePublicId), [
            'public_id' => $this->publicId($folder, $name, $extension),
            'resource_type' => 'raw',
            'type' => 'authenticated',
        ]);

        return $this->responsePublicId($response);
    }

    public function signedUrl(string $publicId, int $seconds = 300): string
    {
        return $this->cloudinaryUploadApi()->privateDownloadUrl($publicId, '', [
            'expires_at' => time() + $seconds,
            'resource_type' => 'raw',
            'type' => 'authenticated',
        ]);
    }

    public function delete(string $publicId): void
    {
        $this->cloudinaryUploadApi()->destroy($publicId, [
            'resource_type' => 'raw',
            'type' => 'authenticated',
        ]);
    }

    private function publicId(string $folder, string $name, string $extension): string
    {
        $name = trim($name, '/');
        $extension = ltrim($extension, '.');

        if (! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        return implode('/', array_filter([trim($folder, '/'), $name], static fn (string $segment): bool => $segment !== ''));
    }

    private function responsePublicId(ApiResponse $response): string
    {
        $publicId = $response['public_id'] ?? null;

        if (! is_string($publicId) || $publicId === '') {
            throw new RuntimeException('Cloudinary did not return a public ID for the resume asset.');
        }

        return $publicId;
    }

    protected function cloudinaryUploadApi(): UploadApi
    {
        return $this->uploadApi ??= (new Cloudinary(config('services.cloudinary.url')))->uploadApi();
    }
}
