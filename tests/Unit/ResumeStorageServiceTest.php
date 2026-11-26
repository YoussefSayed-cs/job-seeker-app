<?php

use App\Services\ResumeStorageService;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\ResumeStorageFakeUploadApi;
use Tests\TestCase;

uses(TestCase::class);

function resumeStorageServiceUsing(UploadApi $uploadApi): ResumeStorageService
{
    return new class($uploadApi) extends ResumeStorageService
    {
        public function __construct(private UploadApi $fakeUploadApi) {}

        protected function cloudinaryUploadApi(): UploadApi
        {
            return $this->fakeUploadApi;
        }
    };
}

test('upload stores a raw authenticated asset and returns its extension-bearing public ID', function () {
    $file = UploadedFile::fake()->create('resume.pdf', 1, 'application/pdf');
    $uploadApi = new ResumeStorageFakeUploadApi;

    expect(resumeStorageServiceUsing($uploadApi)->upload($file, 'resumes', 'resume-123'))
        ->toBe('resumes/resume-123.pdf')
        ->and($uploadApi->uploads)->toBe([
            [
                'file' => $file->getRealPath(),
                'options' => [
                    'public_id' => 'resumes/resume-123.pdf',
                    'resource_type' => 'raw',
                    'type' => 'authenticated',
                ],
            ],
        ]);
});

test('copy creates a new authenticated raw asset from a signed source URL', function () {
    $sourcePublicId = 'resumes/original.docx';
    $uploadApi = new ResumeStorageFakeUploadApi;

    expect(resumeStorageServiceUsing($uploadApi)->copy($sourcePublicId, 'snapshots', 'snapshot-456'))
        ->toBe('snapshots/snapshot-456.docx')
        ->and($uploadApi->privateDownloadCalls[0]['public_id'])->toBe($sourcePublicId)
        ->and($uploadApi->privateDownloadCalls[0]['format'])->toBe('')
        ->and($uploadApi->privateDownloadCalls[0]['options']['resource_type'])->toBe('raw')
        ->and($uploadApi->privateDownloadCalls[0]['options']['type'])->toBe('authenticated')
        ->and($uploadApi->uploads[0]['file'])->toBe($uploadApi->downloadUrl)
        ->and($uploadApi->uploads[0]['options'])->toBe([
            'public_id' => 'snapshots/snapshot-456.docx',
            'resource_type' => 'raw',
            'type' => 'authenticated',
        ]);
});

test('signed URL uses an extension-bearing ID and the raw authenticated download options', function () {
    $publicId = 'resumes/resume-123.pdf';
    $uploadApi = new ResumeStorageFakeUploadApi;

    expect(resumeStorageServiceUsing($uploadApi)->signedUrl($publicId))
        ->toBe($uploadApi->downloadUrl)
        ->and($uploadApi->privateDownloadCalls[0]['public_id'])->toBe($publicId)
        ->and($uploadApi->privateDownloadCalls[0]['format'])->toBe('')
        ->and($uploadApi->privateDownloadCalls[0]['options']['resource_type'])->toBe('raw')
        ->and($uploadApi->privateDownloadCalls[0]['options']['type'])->toBe('authenticated')
        ->and($uploadApi->privateDownloadCalls[0]['options']['expires_at'])->toBeGreaterThanOrEqual(time() + 299)
        ->and($uploadApi->privateDownloadCalls[0]['options']['expires_at'])->toBeLessThanOrEqual(time() + 301);
});

test('delete removes an authenticated raw asset', function () {
    $uploadApi = new ResumeStorageFakeUploadApi;

    resumeStorageServiceUsing($uploadApi)->delete('resumes/resume-123.pdf');

    expect($uploadApi->destroyCalls)->toBe([
        [
            'public_id' => 'resumes/resume-123.pdf',
            'options' => [
                'resource_type' => 'raw',
                'type' => 'authenticated',
            ],
        ],
    ]);
});

test('OPTIONAL MANUAL: authenticated upload can be downloaded and deleted from Cloudinary', function () {
    $service = new ResumeStorageService;
    $file = UploadedFile::fake()->createWithContent('manual-resume.pdf', "%PDF-1.4\nmanual test\n%%EOF");
    $name = (string) Str::uuid();
    $publicId = null;

    try {
        $publicId = $service->upload($file, 'manual-tests', $name);
        $download = Http::timeout(30)->get($service->signedUrl($publicId));

        expect($download->successful())->toBeTrue()
            ->and($download->body())->toStartWith('%PDF-1.4');
    } finally {
        if ($publicId !== null) {
            $service->delete($publicId);
        }
    }
})->skip(
    ! filter_var(getenv('RUN_CLOUDINARY_MANUAL_TEST') ?: false, FILTER_VALIDATE_BOOL),
    'Set RUN_CLOUDINARY_MANUAL_TEST=true and CLOUDINARY_URL to run the real Cloudinary check.',
);
