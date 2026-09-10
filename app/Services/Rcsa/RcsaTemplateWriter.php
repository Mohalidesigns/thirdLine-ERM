<?php

namespace App\Services\Rcsa;

use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaScaleItem;
use App\Models\Rcsa\RcsaSystem;
use App\Models\User;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The RCSA Universe upload template, generated per request.
 *
 * NOT A STATIC FILE, and §7.1 is right to insist on that. The dropdowns have to
 * list THIS tenant's business units, processes and users, and the Risk Matrix
 * and Control Effectiveness sheets have to show the methodology this tenant is
 * actually scored against. A file shipped in the repository would go stale the
 * first time anyone added a branch, and every row typed against the stale list
 * would fail validation on upload for a reason the user could not see.
 *
 * THE MARKER CELLS ARE A CONTROL, NOT METADATA. `template_version` and
 * `tenant_id` are written to a hidden sheet and read back at upload. A file
 * downloaded from Bank A and uploaded to Bank B is rejected on sight rather
 * than being fuzzy-matched into whatever happens to have similar names — which
 * is a real risk when a consultant works across several institutions from one
 * laptop, and the failure mode is one bank's operational risk profile silently
 * seeded into another's.
 *
 * WHAT IS DELIBERATELY NOT LOCKED. Sheet protection is applied to the header
 * rows so that the column order cannot be shuffled, and NOT to the data rows.
 * PhpSpreadsheet protection without a password is a courtesy, not a security
 * boundary — anyone can turn it off in Excel — so it is used to stop accidents,
 * not to enforce anything. The upload validates the header row regardless.
 */
class RcsaTemplateWriter
{
    /**
     * The version stamped into the file and checked on upload.
     *
     * Bump this whenever the upload sheet's COLUMN SET changes — not when a
     * dropdown's contents change, which happens whenever a tenant adds a unit.
     * An old file with the right columns still imports correctly.
     */
    public const VERSION = '1.0';

    public const SHEET_INSTRUCTIONS = 'Instructions';

    public const SHEET_UPLOAD = 'RCSA Universe';

    public const SHEET_MATRIX = 'Risk Matrix';

    public const SHEET_CE_GRID = 'Control Effectiveness Grid';

    public const SHEET_REFERENCE = 'Reference Data';

    /**
     * The upload sheet's columns, in order, with the definition that becomes
     * the header's cell comment.
     *
     * THIS ARRAY IS THE FILE FORMAT. The importer reads the same constant to
     * map a header row back to fields, so a column added here is a column the
     * parser understands, and the two cannot drift.
     *
     * @var array<string, array{label: string, required: bool, note: string}>
     */
    public const COLUMNS = [
        'risk_no' => [
            'label' => 'Risk No.',
            'required' => false,
            'note' => 'Optional. Leave blank and the system generates {UNIT CODE}-R{n} for you. '
                .'If you supply one it must be unique within the business unit.',
        ],
        'business_unit' => [
            'label' => 'Business Unit',
            'required' => true,
            'note' => 'Required. Choose from the list. The name or the unit code both work.',
        ],
        'process' => [
            'label' => 'Process',
            'required' => false,
            'note' => 'The process this risk sits in. Must already exist for the business unit above.',
        ],
        'sub_process' => [
            'label' => 'Sub-Process',
            'required' => false,
            'note' => 'Must belong to the Process named in the previous column.',
        ],
        'system' => [
            'label' => 'System',
            'required' => false,
            'note' => 'The application(s) involved. Separate several with a semicolon.',
        ],
        'potential_risk' => [
            'label' => 'Potential Risk',
            'required' => true,
            'note' => 'Required, at least 20 characters. What could go wrong, and what would follow from it. '
                .'"System failure" is not a risk statement.',
        ],
        'risk_driver' => [
            'label' => 'Risk Driver (Root cause)',
            'required' => false,
            'note' => 'Why this risk exists — the condition that makes it possible.',
        ],
        'risk_category' => [
            'label' => 'Risk Category',
            'required' => true,
            'note' => 'Required. Choose from the list of approved categories.',
        ],
        'secondary_categories' => [
            'label' => 'If more than one risk category is applicable, specify',
            'required' => false,
            'note' => 'Required only when Risk Category is "Others". Separate several with a semicolon.',
        ],
        'existing_control' => [
            'label' => 'Existing Control',
            'required' => false,
            'note' => 'The control already in place. One control per row; repeat the risk on another row '
                .'to add a second control to the same risk.',
        ],
        'control_type' => [
            'label' => 'Control Type',
            'required' => false,
            'note' => 'Preventive, detective, corrective or directive.',
        ],
        'control_frequency' => [
            'label' => 'Control Frequency',
            'required' => false,
            'note' => 'How often the control is performed.',
        ],
        'control_owner' => [
            'label' => 'Control Owner',
            'required' => false,
            'note' => 'The person accountable for performing the control. Name or email.',
        ],
    ];

    /** Row 1 is the header; data starts at row 2. */
    public const HEADER_ROW = 1;

    public const FIRST_DATA_ROW = 2;

    /** How many rows of dropdown validation to lay down. */
    private const VALIDATED_ROWS = 500;

    private const HEADER_FILL = '1F3864';

    private const REQUIRED_FILL = 'FCE4E4';

    public function __construct(private readonly RcsaCalculationService $calculator) {}

    /**
     * Build the workbook and return its bytes.
     *
     * Returned as a string rather than written to disk, because the only caller
     * streams it straight to the browser and a template is not something to
     * keep — it is stale the moment a unit is added.
     */
    public function universeTemplate(?int $organizationId = null): string
    {
        $organizationId ??= TenantContext::organizationId();
        $methodology = $this->calculator->methodology(organizationId: $organizationId);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Atheris ERM')
            ->setTitle('RCSA Universe upload template')
            ->setDescription('Generated '.now()->toDateTimeString().'. Do not edit the header row.');

        // Reference data first: the named ranges have to exist before the
        // upload sheet's validations can point at them.
        $reference = $this->buildReferenceSheet($spreadsheet, $organizationId, $methodology);
        $this->buildUploadSheet($spreadsheet, $reference);
        $this->buildInstructionsSheet($spreadsheet);
        $this->buildMatrixSheet($spreadsheet, $methodology);
        $this->buildControlEffectivenessSheet($spreadsheet, $methodology);

        // Open on Instructions, not on the sheet added first.
        $spreadsheet->setActiveSheetIndexByName(self::SHEET_INSTRUCTIONS);

        return $this->toString($spreadsheet);
    }

    /* ------------------------------------------------------------------ */
    /*  Sheets */
    /* ------------------------------------------------------------------ */

    private function buildUploadSheet(Spreadsheet $spreadsheet, Worksheet $reference): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_UPLOAD);

        $column = 1;

        foreach (self::COLUMNS as $field => $meta) {
            $letter = $this->letter($column);
            $cell = $letter.self::HEADER_ROW;

            $sheet->setCellValue($cell, $meta['label']);

            // The definition, as a cell comment. §7.1 asks for this and it is
            // the cheapest documentation there is: it travels with the file,
            // so somebody filling it in on a laptop with no connectivity still
            // has the field definition in front of them.
            $sheet->getComment($cell)->getText()->createTextRun($meta['note']);
            $sheet->getComment($cell)->setWidth('260pt')->setHeight('90pt');

            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $meta['required'] ? 'C00000' : self::HEADER_FILL],
                ],
                'alignment' => [
                    'wrapText' => true,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $sheet->getColumnDimension($letter)->setWidth($this->widthFor($field));

            if ($meta['required']) {
                $sheet->getStyle(sprintf('%s%d:%s%d', $letter, self::FIRST_DATA_ROW, $letter, self::VALIDATED_ROWS))
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB(self::REQUIRED_FILL);
            }

            $column++;
        }

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(34);
        $sheet->freezePane('A'.self::FIRST_DATA_ROW);

        $this->applyValidations($sheet, $reference);

        // Protect the header only. Every data cell is explicitly unlocked, so
        // the sheet can be protected without making the file unusable.
        $sheet->getStyle(sprintf('A%d:%s%d', self::FIRST_DATA_ROW, $this->letter(count(self::COLUMNS)), 5000))
            ->getProtection()->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setInsertRows(true);
    }

    /**
     * Bind the dropdowns to named ranges on the reference sheet.
     *
     * A named range rather than an inline list, because Excel's inline list is
     * limited to 255 characters — a bank with forty business units blows past
     * that, and the dropdown silently comes out empty.
     */
    private function applyValidations(Worksheet $sheet, Worksheet $reference): void
    {
        $ranges = [
            'business_unit' => 'RcsaBusinessUnits',
            'process' => 'RcsaProcesses',
            'sub_process' => 'RcsaProcesses',
            'system' => 'RcsaSystems',
            'risk_category' => 'RcsaCategories',
            'control_type' => 'RcsaControlTypes',
            'control_frequency' => 'RcsaControlFrequencies',
            'control_owner' => 'RcsaOwners',
        ];

        $fields = array_keys(self::COLUMNS);

        foreach ($ranges as $field => $rangeName) {
            $index = array_search($field, $fields, true);

            if ($index === false) {
                continue;
            }

            $letter = $this->letter($index + 1);

            for ($row = self::FIRST_DATA_ROW; $row <= self::VALIDATED_ROWS; $row++) {
                $validation = $sheet->getCell($letter.$row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                // WARNING, not STOP. A semicolon-separated System list and a
                // sub-process typed before someone created it are both things
                // the preview screen is better placed to judge than Excel is,
                // and a hard stop would make the template refuse input the
                // importer would have accepted.
                $validation->setErrorStyle(DataValidation::STYLE_WARNING);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('Not on the list');
                $validation->setError('That value is not in the reference data. It will be checked again on upload.');
                $validation->setFormula1('='.$rangeName);
            }
        }
    }

    private function buildReferenceSheet(Spreadsheet $spreadsheet, int $organizationId, ?RcsaMethodology $methodology): Worksheet
    {
        $sheet = $spreadsheet->getActiveSheet();
        $reference = $spreadsheet->createSheet();
        $reference->setTitle(self::SHEET_REFERENCE);

        $lists = [
            'A' => ['RcsaBusinessUnits', 'Business Units', $this->businessUnitLabels()],
            'B' => ['RcsaProcesses', 'Processes', $this->processLabels()],
            'C' => ['RcsaSystems', 'Systems', $this->systemLabels()],
            'D' => ['RcsaCategories', 'Risk Categories', Template::RISK_CATEGORIES],
            'E' => ['RcsaControlTypes', 'Control Types', RcsaRegisterControl::TYPES],
            'F' => ['RcsaControlFrequencies', 'Control Frequencies', RcsaRegisterControl::FREQUENCIES],
            'G' => ['RcsaOwners', 'Users', $this->userLabels($organizationId)],
        ];

        foreach ($lists as $column => [$rangeName, $heading, $values]) {
            $reference->setCellValue($column.'1', $heading);
            $reference->getStyle($column.'1')->getFont()->setBold(true);

            $row = 2;

            foreach ($values as $value) {
                $reference->setCellValueExplicit(
                    $column.$row,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                $row++;
            }

            $reference->getColumnDimension($column)->setWidth(38);

            // A named range must span at least one cell even when the list is
            // empty, or Excel reports the file as corrupt rather than showing
            // an empty dropdown.
            $last = max(2, $row - 1);

            $spreadsheet->addNamedRange(new \PhpOffice\PhpSpreadsheet\NamedRange(
                $rangeName,
                $reference,
                sprintf('$%s$2:$%s$%d', $column, $column, $last)
            ));
        }

        /* --- The marker cells (§7.1) ---------------------------------- */

        $reference->setCellValue('J1', 'template_version');
        // Explicitly a string: "1.0" written as a general value comes back as
        // the float 1.0, and the upload check would compare a float to a
        // string and reject every file the product itself generated.
        $reference->setCellValueExplicit('K1', self::VERSION, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $reference->setCellValue('J2', 'tenant_id');
        $reference->setCellValueExplicit('K2', (string) $organizationId, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $reference->setCellValue('J3', 'methodology');
        $reference->setCellValue('K3', $methodology?->code.' v'.$methodology?->version);
        $reference->setCellValue('J4', 'generated_at');
        $reference->setCellValue('K4', now()->toDateTimeString());

        // Very hidden, not merely hidden: a user who unhides sheets in Excel
        // should still not be invited to edit the tenant marker by hand.
        $reference->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        return $reference;
    }

    private function buildInstructionsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::SHEET_INSTRUCTIONS);
        $spreadsheet->setIndexByName(self::SHEET_INSTRUCTIONS, 0);

        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(95);

        $sheet->setCellValue('B2', 'RCSA Universe — upload template');
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(16);

        $lines = [
            '',
            'This file adds RISKS AND CONTROLS to the RCSA Universe. It does not assess them — '
                .'likelihood, impact and control effectiveness are recorded in the assessment, not here.',
            '',
            'HOW TO FILL IT IN',
            '1. Go to the "'.self::SHEET_UPLOAD.'" sheet. Row 1 is the header — do not edit, reorder or delete it.',
            '2. One row per CONTROL. A risk with three controls is three rows repeating the same risk text; '
                .'they are merged into one risk on upload.',
            '3. Columns shaded red are required. Hover over any header for the full definition of that field.',
            '4. Use the dropdowns where they are offered. A value not on the list is not rejected outright — '
                .'it is flagged on the preview screen so you can decide.',
            '5. Save the file and upload it from the RCSA Universe screen.',
            '',
            'WHAT HAPPENS NEXT',
            'Nothing is added to the universe when you upload. The file is parsed and checked, and you are shown '
                .'a preview: how many rows will be created, how many are duplicates of risks already published, '
                .'and which rows have errors and why. You fix or discard those, and only then do you publish.',
            '',
            'A row that fails is never silently dropped. Download the annotated workbook from the preview to get '
                .'this same file back with an Errors column and the offending cells shaded.',
            '',
            'THINGS THAT CATCH PEOPLE OUT',
            '• Potential Risk must be at least 20 characters. "System failure" describes nothing anyone can assess.',
            '• Sub-Process must belong to the Process named beside it.',
            '• Risk Category must be one of the approved list. If you pick "Others", the next column becomes required.',
            '• Leave Risk No. blank unless you are migrating numbers that already mean something.',
            '',
            'This template was generated for your institution and lists your business units, processes and users. '
                .'Do not share it with another institution — it will be rejected on upload there.',
        ];

        $row = 4;

        foreach ($lines as $line) {
            $sheet->setCellValue('C'.$row, $line);
            $sheet->getStyle('C'.$row)->getAlignment()->setWrapText(true);

            if ($line !== '' && $line === strtoupper($line)) {
                $sheet->getStyle('C'.$row)->getFont()->setBold(true);
            }

            $row++;
        }

        /* --- One worked example row, per §7.1 -------------------------- */

        $sheet->setCellValue('B'.($row + 1), 'WORKED EXAMPLE');
        $sheet->getStyle('B'.($row + 1))->getFont()->setBold(true);

        $example = [
            'Risk No.' => '(leave blank)',
            'Business Unit' => 'Retail Banking',
            'Process' => 'Customer Onboarding',
            'Sub-Process' => 'KYC Verification',
            'System' => 'Finacle',
            'Potential Risk' => 'Customer accounts are opened without complete KYC documentation, exposing the bank to regulatory sanction.',
            'Risk Driver (Root cause)' => 'Manual document checks under branch queue pressure.',
            'Risk Category' => 'Compliance/Regulatory',
            'If more than one risk category is applicable, specify' => '',
            'Existing Control' => 'Dual review of account opening packs before activation.',
            'Control Type' => 'detective',
            'Control Frequency' => 'daily',
            'Control Owner' => 'branch.operations@bank.example',
        ];

        $row += 2;

        foreach ($example as $label => $value) {
            $sheet->setCellValue('B'.$row, $label);
            $sheet->setCellValue('C'.$row, $value);
            $sheet->getStyle('B'.$row)->getFont()->setItalic(true);
            $sheet->getStyle('C'.$row)->getAlignment()->setWrapText(true);
            $row++;
        }
    }

    private function buildMatrixSheet(Spreadsheet $spreadsheet, ?RcsaMethodology $methodology): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::SHEET_MATRIX);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(28);

        // Guarded once. A methodology is always resolvable on an installation
        // whose migrations have run — the seeded system row belongs to every
        // tenant — so this is the honest handling of a nullable type rather
        // than a case anybody expects to hit.
        if ($methodology === null) {
            $sheet->setCellValue('A1', 'No RCSA methodology is configured for this organisation.');

            return;
        }

        $sheet->setCellValue('A1', 'Likelihood');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->setCellValue('A2', 'Rating');
        $sheet->setCellValue('B2', 'Value');
        $sheet->setCellValue('C2', 'Band');
        $sheet->setCellValue('D2', 'Definition');
        $sheet->getStyle('A2:D2')->getFont()->setBold(true);

        $row = 3;

        foreach ($methodology->scale(RcsaScaleItem::TYPE_LIKELIHOOD) as $item) {
            $sheet->setCellValue('A'.$row, $item->label);
            $sheet->setCellValue('B'.$row, $item->value);
            $sheet->setCellValue('C'.$row, $item->percent_band);
            $sheet->setCellValue('D'.$row, $item->description);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue('A'.$row, 'Impact');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13);
        $row++;

        // The impact criteria are a matrix: one column per dimension, so the
        // reader can see that "High" means a regulatory penalty as well as
        // NGN 15–30m. Leaving these out is what makes a five-point impact
        // scale collapse into a naira scale.
        $dimensions = Template::IMPACT_DIMENSIONS;

        $sheet->setCellValue('A'.$row, 'Rating');
        $sheet->setCellValue('B'.$row, 'Value');

        $column = 3;

        foreach ($dimensions as $dimension) {
            $sheet->setCellValue($this->letter($column).$row, Template::IMPACT_DIMENSION_LABELS[$dimension]);
            $sheet->getColumnDimension($this->letter($column))->setWidth(34);
            $column++;
        }

        $sheet->getStyle(sprintf('A%d:%s%d', $row, $this->letter($column - 1), $row))->getFont()->setBold(true);

        $criteria = $methodology->impactCriteria->groupBy('impact_value');
        $row++;

        foreach ($methodology->scale(RcsaScaleItem::TYPE_IMPACT) as $item) {
            $sheet->setCellValue('A'.$row, $item->label);
            $sheet->setCellValue('B'.$row, $item->value);

            $column = 3;

            foreach ($dimensions as $dimension) {
                $descriptor = ($criteria[$item->value] ?? collect())
                    ->firstWhere('dimension', $dimension)?->descriptor;

                $sheet->setCellValue($this->letter($column).$row, $descriptor ?? '');
                $sheet->getStyle($this->letter($column).$row)->getAlignment()->setWrapText(true);
                $column++;
            }

            $sheet->getRowDimension($row)->setRowHeight(40);
            $row++;
        }

        $sheet->getProtection()->setSheet(true);
    }

    private function buildControlEffectivenessSheet(Spreadsheet $spreadsheet, ?RcsaMethodology $methodology): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::SHEET_CE_GRID);

        if ($methodology === null) {
            $sheet->setCellValue('A1', 'No RCSA methodology is configured for this organisation.');

            return;
        }

        $sheet->setCellValue('A1', 'Scale');
        $sheet->setCellValue('B1', 'Rating');
        $sheet->setCellValue('C1', 'Achievement');
        $sheet->setCellValue('D1', 'Modifier');
        $sheet->setCellValue('E1', 'Description');
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);

        foreach (['A' => 8, 'B' => 22, 'C' => 16, 'D' => 11, 'E' => 90] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $row = 2;

        foreach ($methodology->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS) as $item) {
            $sheet->setCellValue('A'.$row, $item->value);
            $sheet->setCellValue('B'.$row, $item->label);
            $sheet->setCellValue('C'.$row, $item->percent_band);
            $sheet->setCellValue('D'.$row, $item->modifier);
            $sheet->setCellValue('E'.$row, $item->description);
            $sheet->getStyle('E'.$row)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(32);
            $row++;
        }

        $sheet->setCellValue('A'.($row + 1), 'The modifier is the percentage of the inherent risk the control is assessed to remove. '
            .'Residual = Inherent × (1 − Modifier ÷ 100).');
        $sheet->getStyle('A'.($row + 1))->getFont()->setItalic(true);

        $sheet->getProtection()->setSheet(true);
    }

    /* ------------------------------------------------------------------ */
    /*  Reference lists */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function businessUnitLabels(): array
    {
        return BusinessUnit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @return list<string> */
    private function processLabels(): array
    {
        return BusinessProcess::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function systemLabels(): array
    {
        return RcsaSystem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @return list<string> */
    private function userLabels(int $organizationId): array
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('email')
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Plumbing */
    /* ------------------------------------------------------------------ */

    private function widthFor(string $field): int
    {
        return match ($field) {
            'potential_risk', 'risk_driver', 'existing_control' => 55,
            'secondary_categories' => 30,
            'business_unit', 'process', 'sub_process', 'control_owner' => 26,
            default => 18,
        };
    }

    private function letter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    private function toString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();

        // PhpSpreadsheet holds the whole workbook in memory and the caller is
        // about to stream several hundred kilobytes; releasing it here keeps a
        // burst of concurrent downloads from stacking.
        $spreadsheet->disconnectWorksheets();

        return $contents;
    }
}
