<?php

use App\Ai\GeminiResumeAnalyzer;
use App\Ai\PdfDocxDocumentParser;
use App\Services\ResumeStorageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shagalni\Shared\Ai\AnalysisResult;
use Shagalni\Shared\Ai\DocumentParser;
use Shagalni\Shared\Ai\ResumeAnalyzer;
use Shagalni\Shared\Ai\UnsupportedResumeException;
use Shagalni\Shared\Models\ResumeSnapshot;
use Tests\TestCase;

uses(TestCase::class);

test('AI interfaces resolve to the configured adapters', function () {
    expect(app(DocumentParser::class))->toBeInstanceOf(PdfDocxDocumentParser::class)
        ->and(app(ResumeAnalyzer::class))->toBeInstanceOf(GeminiResumeAnalyzer::class);
});

test('Gemini receives only resume text and allowlisted job fields', function () {
    config([
        'services.gemini.key' => 'test-api-key',
        'services.gemini.model' => 'test-model',
    ]);

    Http::fake([
        '*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => '{"score":87,"feedback":"Relevant experience."}']],
                ],
            ]],
        ]),
    ]);

    $result = app(GeminiResumeAnalyzer::class)->analyze('Resume contents', [
        'title' => 'Engineer',
        'description' => 'Build software.',
        'required_skills' => 'PHP',
        'email' => 'private@example.test',
        'user' => ['name' => 'Private Name'],
    ]);

    expect($result)->toEqual(new AnalysisResult(87, 'Relevant experience.'));

    Http::assertSent(function (Request $request): bool {
        $body = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return $request->hasHeader('x-goog-api-key', 'test-api-key')
            && str_contains($body, 'Resume contents')
            && str_contains($body, 'Engineer')
            && ! str_contains($body, 'private@example.test')
            && ! str_contains($body, 'Private Name')
            && ! str_contains($body, 'email');
    });
});

test('Gemini request failures remain retryable and do not expose credentials', function () {
    config([
        'services.gemini.key' => 'secret-api-key',
        'services.gemini.model' => 'test-model',
    ]);

    Http::fake(['*' => Http::response([], 503)]);

    try {
        app(GeminiResumeAnalyzer::class)->analyze('Private resume text', ['title' => 'Engineer']);
        test()->fail('Expected the AI service failure to be retryable.');
    } catch (RuntimeException $exception) {
        expect($exception)->not->toBeInstanceOf(UnsupportedResumeException::class)
            ->and($exception->getMessage())->not->toContain('secret-api-key')
            ->and($exception->getMessage())->not->toContain('Private resume text');
    }
});

test('unsupported resume formats are permanent input failures', function () {
    $storage = new class extends ResumeStorageService
    {
        public function signedUrl(string $publicId, int $seconds = 300): string
        {
            throw new RuntimeException('Storage should not be called for unsupported formats.');
        }
    };

    $snapshot = new ResumeSnapshot(['file_path' => 'resume.txt']);

    expect(fn () => (new PdfDocxDocumentParser($storage))->extract($snapshot))
        ->toThrow(UnsupportedResumeException::class);
});

test('DOCX resumes are downloaded and their document text is extracted', function () {
    $temporaryFile = tempnam(sys_get_temp_dir(), 'resume-test-');
    expect($temporaryFile)->not->toBeFalse();

    $archive = new ZipArchive;
    expect($archive->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $archive->addFromString(
        'word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>First section</w:t></w:r><w:r><w:t>Second</w:t></w:r></w:p></w:body></w:document>',
    );
    $archive->close();
    $contents = file_get_contents($temporaryFile);
    unlink($temporaryFile);

    Http::fake(['https://cloudinary.test/*' => Http::response($contents)]);

    $storage = new class extends ResumeStorageService
    {
        public function signedUrl(string $publicId, int $seconds = 300): string
        {
            return 'https://cloudinary.test/'.$publicId;
        }
    };

    $snapshot = new ResumeSnapshot(['file_path' => 'resume.docx']);

    expect((new PdfDocxDocumentParser($storage))->extract($snapshot))
        ->toBe('First section Second');
});
