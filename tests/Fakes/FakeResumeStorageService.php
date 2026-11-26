<?php

namespace Tests\Fakes;

use App\Services\ResumeStorageService;
use Closure;
use Illuminate\Http\UploadedFile;
use Throwable;

final class FakeResumeStorageService extends ResumeStorageService
{
    /** @var list<array{file: UploadedFile, folder: string, name: string}> */
    public array $uploads = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array{source: string, folder: string, name: string}> */
    public array $copies = [];

    /** @var list<array{public_id: string, seconds: int}> */
    public array $signedUrls = [];

    public ?Throwable $copyFailure = null;

    public ?Closure $afterCopy = null;

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

    public function copy(string $sourcePublicId, string $folder, string $name): string
    {
        if ($this->copyFailure !== null) {
            throw $this->copyFailure;
        }

        $this->copies[] = [
            'source' => $sourcePublicId,
            'folder' => $folder,
            'name' => $name,
        ];
        $this->afterCopy?->__invoke($sourcePublicId, $folder, $name);

        return $folder.'/'.$name.'.pdf';
    }

    public function delete(string $publicId): void
    {
        $this->deleted[] = $publicId;
    }
}
