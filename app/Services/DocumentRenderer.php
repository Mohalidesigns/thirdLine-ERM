<?php

namespace App\Services;

use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Turns a Blade view or a tabular dataset into a real document.
 *
 * Before this existed the product validated `format in:pdf,excel,html,pptx` and
 * then wrote a CSV for every one of them — four promises, one delivery. The
 * board, executive and regulatory "reports" had no document output at all; they
 * were on-screen Blade views.
 *
 * The supported set is now exactly what is delivered: **pdf, xlsx, csv**. An
 * unsupported format raises rather than silently degrading, because a caller
 * asking for pptx and receiving a spreadsheet is the same defect in a new coat.
 * docx and pptx are deliberately not offered — adding them means adding
 * phpword/phppresentation and building templates worth reading, which is its
 * own piece of work rather than a footnote to this one.
 */
class DocumentRenderer
{
    public const FORMAT_PDF = 'pdf';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_CSV = 'csv';

    /**
     * Formats this renderer actually produces.
     */
    public const SUPPORTED = [self::FORMAT_PDF, self::FORMAT_XLSX, self::FORMAT_CSV];

    private const MIME = [
        self::FORMAT_PDF => 'application/pdf',
        self::FORMAT_XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        self::FORMAT_CSV => 'text/csv; charset=UTF-8',
    ];

    /**
     * Render a document and return its bytes plus the metadata a caller needs
     * to store or stream it.
     *
     * For pdf, $view is rendered through the branded report layout. For xlsx
     * and csv, $data must carry `headers` and `rows`, because a paginated
     * report layout has no meaning in a spreadsheet — the caller decides what
     * the tabular projection of its report looks like.
     *
     * @param  array<string,mixed>  $data
     * @return array{content: string, mime: string, extension: string}
     */
    public function render(string $view, array $data, string $format): array
    {
        $format = $this->normalise($format);

        return match ($format) {
            self::FORMAT_PDF => [
                'content' => $this->pdf($view, $data),
                'mime' => self::MIME[self::FORMAT_PDF],
                'extension' => 'pdf',
            ],
            self::FORMAT_XLSX => [
                'content' => $this->xlsx(
                    $this->requireTabular($data, 'headers'),
                    $this->requireTabular($data, 'rows'),
                    $data['sheet_name'] ?? 'Report',
                    $data['meta'] ?? []
                ),
                'mime' => self::MIME[self::FORMAT_XLSX],
                'extension' => 'xlsx',
            ],
            self::FORMAT_CSV => [
                'content' => $this->csv(
                    $this->requireTabular($data, 'headers'),
                    $this->requireTabular($data, 'rows')
                ),
                'mime' => self::MIME[self::FORMAT_CSV],
                'extension' => 'csv',
            ],
        };
    }

    /**
     * Accepts the aliases the existing UI posts ("excel") and rejects anything
     * this class cannot actually produce.
     */
    public function normalise(string $format): string
    {
        $format = strtolower(trim($format));

        $format = match ($format) {
            'excel', 'xls' => self::FORMAT_XLSX,
            default => $format,
        };

        if (! in_array($format, self::SUPPORTED, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported document format [%s]. This renderer produces: %s.',
                $format,
                implode(', ', self::SUPPORTED)
            ));
        }

        return $format;
    }

    public function mimeFor(string $format): string
    {
        return self::MIME[$this->normalise($format)];
    }

    /* ------------------------------------------------------------------ */
    /*  PDF */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string,mixed>  $data
     */
    public function pdf(string $view, array $data): string
    {
        $data['branding'] = $data['branding'] ?? $this->branding($data['organization'] ?? null);
        $data['generatedAt'] = $data['generatedAt'] ?? CarbonImmutable::now();

        $pdf = Pdf::loadView($view, $data)
            ->setPaper($data['paper'] ?? 'a4', $data['orientation'] ?? 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,       // no outbound fetches from a report
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',   // carries ₦ and the rest of Unicode
                'dpi' => 96,
                'chroot' => public_path(),
            ]);

        return $pdf->output();
    }

    /**
     * Branding for the cover page and running header.
     *
     * Reads organizations.settings->org_profile, written by the admin settings
     * screen. Falls back to the organisation's own name — never to a
     * placeholder logo or an invented institution code.
     *
     * @return array<string,mixed>
     */
    public function branding(?Organization $organization): array
    {
        $profile = (array) ($organization?->settings['org_profile'] ?? []);

        $logoPath = $profile['logo_path'] ?? null;
        $logoData = null;

        // Inline the logo as a data URI. dompdf runs with isRemoteEnabled off
        // and a chroot, so a stored path is the only thing that reliably
        // resolves — and inlining means a moved file cannot break an already
        // generated document.
        if ($logoPath) {
            $absolute = storage_path('app/public/'.ltrim($logoPath, '/'));
            if (is_readable($absolute) && filesize($absolute) < 2_000_000) {
                $mime = mime_content_type($absolute) ?: 'image/png';
                $logoData = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($absolute));
            }
        }

        return [
            'organization_name' => $organization?->name ?? config('app.name'),
            'short_name' => $organization?->short_name,
            'institution_type' => $organization?->institution_type,
            'cbn_institution_code' => $organization?->cbn_institution_code,
            'rc_number' => $organization?->rc_number,
            'address' => $profile['address'] ?? null,
            'logo' => $logoData,
            // Brand navy and gold, overridable per tenant.
            'primary_colour' => $profile['primary_colour'] ?? '#1A365D',
            'accent_colour' => $profile['accent_colour'] ?? '#D4AF37',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Spreadsheets */
    /* ------------------------------------------------------------------ */

    /**
     * A real .xlsx — a styled header row, frozen panes, auto-sized columns and
     * an optional metadata block, written by PhpSpreadsheet.
     *
     * @param  list<string>  $headers
     * @param  iterable<array-key, array<array-key, mixed>>  $rows
     * @param  array<string,string>  $meta  key => value pairs stamped above the table
     */
    public function xlsx(array $headers, iterable $rows, string $sheetName = 'Report', array $meta = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        // Excel rejects sheet names over 31 chars or carrying []:*?/\
        $sheet->setTitle(mb_substr(preg_replace('/[\[\]\*\/\\\\\?:]/', '-', $sheetName), 0, 31));

        $row = 1;

        foreach ($meta as $label => $value) {
            $sheet->setCellValue([1, $row], $label);
            $sheet->setCellValue([2, $row], $value);
            $sheet->getStyle([1, $row, 1, $row])->getFont()->setBold(true);
            $row++;
        }

        if ($meta !== []) {
            $row++; // blank spacer between the stamp and the table
        }

        $headerRow = $row;
        foreach (array_values($headers) as $i => $header) {
            $sheet->setCellValue([$i + 1, $headerRow], $header);
        }

        $lastColumn = max(1, count($headers));
        $headerStyle = $sheet->getStyle([1, $headerRow, $lastColumn, $headerRow]);
        $headerStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1A365D');
        $headerStyle->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($headerRow)->setRowHeight(20);

        $row = $headerRow + 1;
        foreach ($rows as $dataRow) {
            foreach (array_values((array) $dataRow) as $i => $value) {
                // setCellValueExplicit is avoided so numbers stay numeric and
                // sort correctly, but a leading = must never be evaluated:
                // a risk title starting with "=" would otherwise become a
                // formula in the recipient's Excel.
                if (is_string($value) && str_starts_with($value, '=')) {
                    $value = "'".$value;
                }
                $sheet->setCellValue([$i + 1, $row], $value);
            }
            $row++;
        }

        $lastRow = max($headerRow, $row - 1);

        if ($lastRow > $headerRow) {
            $sheet->getStyle([1, $headerRow, $lastColumn, $lastRow])
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFD9D9D9');
        }

        $sheet->setAutoFilter([1, $headerRow, $lastColumn, $lastRow]);
        $sheet->freezePane([1, $headerRow + 1]);

        for ($column = 1; $column <= $lastColumn; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        // PhpSpreadsheet writes to a stream or a path; capture the bytes so the
        // caller can store or stream them without a temp file of its own.
        $writer = new XlsxWriter($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $content;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array-key, array<array-key, mixed>>  $rows
     */
    public function csv(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads ₦ correctly
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                // Same formula-injection guard as the xlsx path.
                fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value,
                array_values((array) $row)
            ));
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string,mixed>  $data
     */
    private function requireTabular(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            throw new InvalidArgumentException(
                "Spreadsheet output requires '{$key}' in the render payload."
            );
        }

        return is_array($data[$key]) ? $data[$key] : iterator_to_array($data[$key]);
    }
}
