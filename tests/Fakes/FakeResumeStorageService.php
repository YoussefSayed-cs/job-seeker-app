<?php

namespace Tests\Fakes;

use App\Services\ResumeStorageService;
use Illuminate\Http\UploadedFile;

final class FakeResumeStorageService extends ResumeStorageService
{
    /** @var list<array{file: UploadedFile, folder: string, name: string}> */
    public array $uploads = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{public_id: string, seconds: int}> */
    public array $signedUrls = [];

    public function upload(UploadedFile $file, string $folder, string $name): string
    {
        $this->uploads[] = compact('file', 'folder', 'name');

        return $folder.'/'.$name.'.'.strtolower($file->getClientOriginalExtension());
    }

    public function signedUrl(string $publicId, int $seconds = 300): string
    {
        $this->signedUrls[] = ['public_id' => $publicId, 'seconds' => $seconds];

        return 'https://cloudinary.test/'.$publicId;
    }

    public function delete(string $publicId): void
    {
        $this->deleted[] = $publicId;
    }
}
