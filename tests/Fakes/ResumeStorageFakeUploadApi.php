<?php

namespace Tests\Fakes;

use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;

final class ResumeStorageFakeUploadApi extends UploadApi
{
    /** @var list<array{file: mixed, options: array<string, mixed>}> */
    public array $uploads = [];

    /** @var list<array{public_id: string, format: string, options: array<string, mixed>}> */
    public array $privateDownloadCalls = [];

    /** @var list<array{public_id: string, options: array<string, mixed>}> */
    public array $destroyCalls = [];

    public string $downloadUrl = 'https://cloudinary.test/signed-download';

    public function __construct()
    {
        parent::__construct('cloudinary://key:secret@example');
    }

    /** @param array<string, mixed> $options */
    public function upload(mixed $file, array $options = []): ApiResponse
    {
        $this->uploads[] = ['file' => $file, 'options' => $options];

        return new ApiResponse(['public_id' => $options['public_id']], []);
    }

    /** @param array<string, mixed> $options */
    public function privateDownloadUrl(string $publicId, string $format, array $options = []): string
    {
        $this->privateDownloadCalls[] = [
            'public_id' => $publicId,
            'format' => $format,
            'options' => $options,
        ];

        return $this->downloadUrl;
    }

    /** @param array<string, mixed> $options */
    public function destroy(string $publicId, array $options = []): ApiResponse
    {
        $this->destroyCalls[] = ['public_id' => $publicId, 'options' => $options];

        return new ApiResponse([], []);
    }
}
