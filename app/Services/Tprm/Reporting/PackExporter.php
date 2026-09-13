<?php

namespace App\Services\Tprm\Reporting;

use Illuminate\Http\Request;
use InvalidArgumentException;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * Turns a sectioned regulatory pack into a file.
 *
 * The NDPA Compliance Audit Return pack and the PCI 12.8 pack have different
 * content and the same shape: a list of sections, each with a code, a title, a
 * citation, a coverage declaration and a table. Writing the xlsx/csv/pdf
 * handling twice would mean two places for the provenance stamp to drift out
 * of agreement, which is the failure AC-13 exists to prevent — and it applies
 * to every pack, not only to the register the criterion names.
 *
 * CSV TAKES ONE SECTION AT A TIME AND WILL NOT GUESS WHICH. A seven-section
 * evidence pack flattened into one CSV is not the pack; silently exporting the
 * largest section is worse than refusing.
 */
class PackExporter
{
    public function __construct(private readonly DocumentRenderer $renderer) {}

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array{content: string, extension: string, mime: string}
     */
    public function export(
        Request $request,
        array $sections,
        ReportProvenance $provenance,
        string $format,
        string $pdfTitle,
        string $pdfSubtitle,
    ): array {
        return match ($format) {
            DocumentRenderer::FORMAT_XLSX => [
                'content' => $this->renderer->workbook($this->sheets($sections, $provenance)),
                'extension' => 'xlsx',
                'mime' => $this->renderer->mimeFor(DocumentRenderer::FORMAT_XLSX),
            ],
            DocumentRenderer::FORMAT_CSV => $this->singleSectionCsv($request, $sections, $provenance),
            DocumentRenderer::FORMAT_PDF => [
                'content' => $this->renderer->pdf('reports.pdf.tprm-regulatory-pack', [
                    'title' => $pdfTitle,
                    'subtitle' => $pdfSubtitle,
                    'organization' => $request->user()->organization,
                    'periodAsAt' => $provenance->asAt,
                    'generatedBy' => $request->user()->name,
                    'preparedBy' => $provenance->preparedBy,
                    'reviewedBy' => $provenance->reviewedBy,
                    'reviewRequired' => true,
                    'provenance' => $provenance->filterProvenance(),
                    // NOT `sections`: the PDF layout reserves that name for
                    // its own table of contents.
                    'packSections' => $sections,
                    'paper' => 'a3',
                    'orientation' => 'landscape',
                ]),
                'extension' => 'pdf',
                'mime' => 'application/pdf',
            ],
            // Unreachable: DocumentRenderer::normalise() has already refused
            // anything outside the supported set. Spelt out rather than folded
            // into a branch, because rendering the wrong format for an
            // unrecognised one is the defect that renderer exists to stop.
            default => throw new InvalidArgumentException("Unsupported pack format [{$format}]."),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    public function sheets(array $sections, ReportProvenance $provenance): array
    {
        $sheets = [[
            'name' => 'Cover',
            'rows' => $this->coverRows($sections, $provenance),
        ]];

        foreach ($sections as $section) {
            $sheets[] = [
                'name' => $section['code'].' '.$section['title'],
                'headers' => $section['headers'],
                'rows' => $section['rows'],
                'meta' => array_filter([
                    'Section' => $section['code'].' — '.$section['title'],
                    'Authority' => $section['citation'] ?? null,
                    'Coverage' => ucfirst((string) $section['coverage']),
                    'Note' => $section['note'] ?? null,
                ], fn (?string $value) => $value !== null && $value !== ''),
            ];
        }

        return $sheets;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<list<string>>
     */
    private function coverRows(array $sections, ReportProvenance $provenance): array
    {
        $rows = [[$provenance->title]];

        if ($provenance->authority !== null) {
            $rows[] = [$provenance->authority];
        }

        $rows[] = [];

        foreach ($provenance->toMeta() as $label => $value) {
            $rows[] = [$label, $value];
        }

        $rows[] = [];
        $rows[] = ['Section', 'Title', 'Citation', 'Rows', 'Coverage', 'Note'];

        foreach ($sections as $section) {
            $rows[] = [
                $section['code'],
                $section['title'],
                (string) ($section['citation'] ?? ''),
                (string) count($section['rows']),
                ucfirst((string) $section['coverage']),
                (string) ($section['note'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array{content: string, extension: string, mime: string}
     */
    private function singleSectionCsv(Request $request, array $sections, ReportProvenance $provenance): array
    {
        $code = (string) $request->query('section', '');
        $section = collect($sections)->firstWhere('code', $code);

        abort_if($section === null, 422, 'Name the section to export, for example section='
            .($sections[0]['code'] ?? 'CAR-1').'. This pack has '.count($sections)
            .' sections and a CSV holds one.');

        return [
            'content' => $this->renderer->csv(
                $section['headers'],
                $section['rows'],
                // Section keys are prefixed rather than merged over the pack's
                // own stamp: `+` keeps the left operand's keys, so a bare
                // "Authority" here would be silently dropped in favour of the
                // pack's — and the section's citation is the one a reader of
                // a single-section CSV needs.
                $provenance->toMeta() + array_filter([
                    'Section' => $section['code'].' — '.$section['title'],
                    'Section authority' => $section['citation'] ?? null,
                    'Section coverage' => ucfirst((string) $section['coverage']),
                    'Section note' => $section['note'] ?? null,
                ], fn (?string $value) => $value !== null && $value !== ''),
            ),
            'extension' => 'csv',
            'mime' => $this->renderer->mimeFor(DocumentRenderer::FORMAT_CSV),
        ];
    }
}
