<?php

use App\Ai\GeminiResumeAnalyzer;
use App\Ai\PdfDocxDocumentParser;
use App\Services\ResumeStorageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shagalni\Shared\Ai\AnalysisResult;
use Shagalni\Shared\Ai\UnsupportedResumeException;
use Shagalni\Shared\Models\ResumeSnapshot;

beforeEach(function () {
    app()->instance(ResumeStorageService::class, new class extends ResumeStorageService
    {
        public function signedUrl(string $publicId, int $seconds = 300): string
        {
            return 'https://files.test/resume';
        }
    });
});

test('a valid DOCX resume is extracted', function () {
    $text = 'This resume contains enough readable text to pass the minimum length validation in the parser.';
    $contents = resumeAiDocxBytes($text);

    Http::fake(['https://files.test/resume' => Http::response($contents)]);

    $snapshot = new ResumeSnapshot(['file_path' => 'resume-snapshots/x.docx']);

    expect(app(PdfDocxDocumentParser::class)->extract($snapshot))
        ->toContain($text);
});

test('a PDF with unreadable contents is rejected permanently', function () {
    Http::fake(['https://files.test/resume' => Http::response('random garbage')]);

    $snapshot = new ResumeSnapshot(['file_path' => 'resume-snapshots/x.pdf']);

    expect(fn () => app(PdfDocxDocumentParser::class)->extract($snapshot))
        ->toThrow(UnsupportedResumeException::class);
});

test('a DOCX with fewer than 50 text characters is rejected permanently', function () {
    $contents = resumeAiDocxBytes('Short resume text.');

    Http::fake(['https://files.test/resume' => Http::response($contents)]);

    $snapshot = new ResumeSnapshot(['file_path' => 'resume-snapshots/x.docx']);

    expect(fn () => app(PdfDocxDocumentParser::class)->extract($snapshot))
        ->toThrow(UnsupportedResumeException::class);
});

test('unsupported resume file extensions are rejected permanently', function () {
    $snapshot = new ResumeSnapshot(['file_path' => 'resume-snapshots/x.txt']);

    expect(fn () => app(PdfDocxDocumentParser::class)->extract($snapshot))
        ->toThrow(UnsupportedResumeException::class);
});

test('Gemini responses produce an analysis result', function () {
    configureGeminiForTest();
    Http::fake(['*' => Http::response(geminiTextResponse('{"score":82,"feedback":"Good fit"}'))]);

    expect(app(GeminiResumeAnalyzer::class)->analyze('Resume contents', ['title' => 'Engineer']))
        ->toEqual(new AnalysisResult(82, 'Good fit'));
});

test('Gemini scores are clamped to the supported range', function (int $score, int $expected) {
    configureGeminiForTest();
    Http::fake(['*' => Http::response(geminiTextResponse(json_encode([
        'score' => $score,
        'feedback' => 'Feedback',
    ], JSON_THROW_ON_ERROR)))]);

    expect(app(GeminiResumeAnalyzer::class)->analyze('Resume contents', ['title' => 'Engineer'])->score)
        ->toBe($expected);
})->with([
    'upper bound' => [150, 100],
    'lower bound' => [-5, 0],
]);

test('malformed Gemini response text throws a retryable exception', function () {
    configureGeminiForTest();
    Http::fake(['*' => Http::response(geminiTextResponse('not JSON'))]);

    try {
        app(GeminiResumeAnalyzer::class)->analyze('Resume contents', ['title' => 'Engineer']);
        test()->fail('Expected the invalid response to throw.');
    } catch (RuntimeException $exception) {
        expect($exception)->not->toBeInstanceOf(UnsupportedResumeException::class);
    }
});

test('Gemini HTTP 500 throws a retryable exception', function () {
    configureGeminiForTest();
    Http::fake(['*' => Http::response('Server error', 500)]);

    $exception = null;

    try {
        app(GeminiResumeAnalyzer::class)->analyze('Resume contents', ['title' => 'Engineer']);
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    expect($exception)->toBeInstanceOf(Throwable::class)
        ->not->toBeInstanceOf(UnsupportedResumeException::class);
});

test('Gemini HTTP 400 with a normal API error is a permanent input failure', function () {
    configureGeminiForTest();
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid resume input']], 400)]);

    expect(fn () => app(GeminiResumeAnalyzer::class)->analyze('Resume contents', ['title' => 'Engineer']))
        ->toThrow(UnsupportedResumeException::class);
});

test('Gemini request includes job and resume text without user contact fields', function () {
    configureGeminiForTest();
    Http::fake(['*' => Http::response(geminiTextResponse('{"score":82,"feedback":"Good fit"}'))]);

    app(GeminiResumeAnalyzer::class)->analyze('Distinctive resume text', [
        'title' => 'Senior Engineer',
        'description' => 'Build reliable systems.',
    ]);

    Http::assertSent(function (Request $request): bool {
        $body = json_encode($request->data(), JSON_THROW_ON_ERROR);

        return str_contains($body, 'Senior Engineer')
            && str_contains($body, 'Distinctive resume text')
            && preg_match('/\b(email|phone)\b/i', $body) === 0;
    });
});

function configureGeminiForTest(): void
{
    config([
        'services.gemini.key' => 'test',
        'services.gemini.model' => 'test-model',
    ]);
}

/**
 * @return array{candidates: list<array{content: array{parts: list<array{text: string}>}}>}
 */
function geminiTextResponse(string $text): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => [['text' => $text]],
            ],
        ]],
    ];
}

function resumeAiDocxBytes(string $text): string
{
    $path = tempnam(sys_get_temp_dir(), 'resume-test-');

    if ($path === false) {
        throw new RuntimeException('Could not create a temporary DOCX file.');
    }

    try {
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create a temporary DOCX archive.');
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p></w:body>'
            .'</w:document>';
        $archive->addFromString('word/document.xml', $xml);
        $archive->close();

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Could not read the temporary DOCX archive.');
        }

        return $contents;
    } finally {
        unlink($path);
    }
}
