<?php

namespace App\Ai;

use App\Services\ResumeStorageService;
use Illuminate\Support\Facades\Http;
use Shagalni\Shared\Ai\DocumentParser;
use Shagalni\Shared\Ai\UnsupportedResumeException;
use Shagalni\Shared\Models\ResumeSnapshot;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

class PdfDocxDocumentParser implements DocumentParser
{
    private const MAX_CHARS = 20000;

    private const MIN_CHARS = 50;

    public function __construct(private readonly ResumeStorageService $storage) {}

    public function extract(ResumeSnapshot $snapshot): string
    {
        $extension = strtolower(pathinfo($snapshot->file_path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['pdf', 'docx'], true)) {
            throw new UnsupportedResumeException('Unsupported resume file type.');
        }

        // network failure here is transient, so let it throw normally
        $contents = Http::timeout(30)
            ->get($this->storage->signedUrl($snapshot->file_path))
            ->throw()
            ->body();

        $tmp = tempnam(sys_get_temp_dir(), 'resume');

        try {
            file_put_contents($tmp, $contents);
            $text = $extension === 'pdf' ? $this->fromPdf($tmp) : $this->fromDocx($tmp);
        } finally {
            @unlink($tmp);
        }

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = trim(preg_replace('/\R{3,}/', "\n\n", $text) ?? '');

        if (mb_strlen($text) < self::MIN_CHARS) {
            throw new UnsupportedResumeException('No readable text was found in this resume. It may be a scanned document.');
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    private function fromPdf(string $path): string
    {
        try {
            return (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            throw new UnsupportedResumeException('The PDF file could not be read.');
        }
    }

    private function fromDocx(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new UnsupportedResumeException('The DOCX file could not be read.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new UnsupportedResumeException('The DOCX file could not be read.');
        }

        $xml = str_replace('</w:p>', "\n", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
