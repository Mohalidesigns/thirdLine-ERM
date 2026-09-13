<?php

namespace App\Services\Tprm\Extraction;

use App\Models\Tprm\Document;
use App\Services\FileUploadService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Getting the words out of the file, before any model sees it.
 *
 * WHAT THIS CAN AND CANNOT READ, STATED PLAINLY.
 *
 *   Text and CSV are read directly.
 *
 *   PDF requires `pdftotext` (poppler-utils) on the host. It is the format
 *   almost every piece of vendor evidence actually arrives in, and this
 *   product carries no PDF *reading* library — `barryvdh/laravel-dompdf`
 *   writes PDFs and cannot read one. So the binary is detected at runtime and,
 *   where it is absent, extraction reports itself unavailable and the manual
 *   entry path stands. It does NOT return an empty string: an empty document
 *   sent to a model produces a confident extraction of nothing, which is the
 *   worst of the available outcomes.
 *
 *   Word and Excel are not read. A .docx is a ZIP of XML and could be parsed,
 *   but a half-parsed document that drops tables would feed a model the parts
 *   of a report that are not the parts that matter — Section 4 of a SOC 2 is a
 *   table.
 *
 * The honest consequence is that a deployment without poppler-utils gets a
 * fully working evidence library with manual entry and no extraction, and is
 * told so in those words rather than seeing extraction quietly return nothing.
 */
class DocumentTextExtractor
{
    /** Guard against a pathological file eating the request. */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Text for a document, or a stated reason there is none.
     *
     * @return array{text: string|null, reason: string|null}
     */
    public function textFor(Document $document): array
    {
        $disk = Storage::disk(FileUploadService::DISK);

        if (! $disk->exists($document->file_path)) {
            return ['text' => null, 'reason' => 'The stored file is missing.'];
        }

        $path = $disk->path($document->file_path);
        $extension = strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt', 'csv' => ['text' => (string) file_get_contents($path), 'reason' => null],
            'pdf' => $this->fromPdf($path),
            default => [
                'text' => null,
                'reason' => "Text cannot be read from a .{$extension} file. Enter the fields by hand, or "
                    .'re-upload the document as a PDF.',
            ],
        };
    }

    /**
     * Whether this host can read PDFs at all.
     *
     * Surfaced on the documents screen so an administrator learns this from a
     * banner rather than from a queue of documents that never extract.
     */
    public function canReadPdf(): bool
    {
        return $this->pdftotext() !== null;
    }

    /**
     * @return array{text: string|null, reason: string|null}
     */
    private function fromPdf(string $path): array
    {
        $binary = $this->pdftotext();

        if ($binary === null) {
            return [
                'text' => null,
                'reason' => 'This server cannot read PDF text: the `pdftotext` utility (poppler-utils) is not '
                    .'installed. Every field can still be entered by hand.',
            ];
        }

        try {
            // `-layout` keeps columns and tables roughly in place, which
            // matters because a SOC 2's exceptions are in a table and a
            // reflowed one interleaves the control reference of one row with
            // the population of the next.
            $process = new Process([$binary, '-layout', '-enc', 'UTF-8', $path, '-']);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->mustRun();

            $text = trim($process->getOutput());

            if ($text === '') {
                return [
                    'text' => null,
                    // The common real case: a scanned certificate. Worth saying
                    // precisely, because the fix is OCR or retyping, not retry.
                    'reason' => 'No text could be read from this PDF. It is most likely a scan or an image, '
                        .'which needs the fields entered by hand.',
                ];
            }

            return ['text' => $text, 'reason' => null];
        } catch (ProcessFailedException $exception) {
            return ['text' => null, 'reason' => 'The PDF could not be read: '.$exception->getMessage()];
        }
    }

    private function pdftotext(): ?string
    {
        foreach (['/opt/homebrew/bin/pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));

        return $which !== '' && is_executable($which) ? $which : null;
    }
}
