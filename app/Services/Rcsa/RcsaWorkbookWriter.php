<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The workbook the bank hands to the regulator — columns A to W of
 * `SB_RCSA Template 2026`, in the workbook's order, under the workbook's merged
 * group headers, plus the eight system columns §10.1 requires.
 *
 * THIS IS THE DESIGN RULE OF §2 MADE LITERAL: "a user must be able to take a
 * completed RCSA out of the system as Excel, hand it to the regulator or the
 * Board Risk Committee, and have it look and calculate identically to
 * SB_RCSA Template 2026." Everything here is subordinate to that — the column
 * order is the workbook's, not the database's, and the two header rows exist
 * because the source has two.
 *
 * IT IS NOT THE UNIVERSE TEMPLATE. RcsaTemplateWriter writes the thirteen-column
 * UPLOAD sheet for master data (§7.1); this writes the twenty-three-column
 * ASSESSMENT sheet for download (§10.1). They share a workbook family and
 * almost nothing else, and merging them would mean one column list serving two
 * different documents.
 *
 * THE ACTION-PLAN COLUMNS FLATTEN. The workbook has one "Control to be
 * Implemented" cell per risk and this module has many plans per line (defect
 * D5), so U, V and W are newline-joined in line order. That is template parity
 * rather than a data model — anything wanting the plans individually reads
 * `rcsa_action_plans`, and the offline round-trip deliberately does not accept
 * these columns back (see `workingCopy()`).
 *
 * THE GROUP HEADER SPANS ARE READ FROM `GROUPS` AND NOWHERE ELSE, so the merge,
 * the fill and the column count cannot drift from each other. **Their
 * boundaries are taken from §10.1's list of five groups against §4's column
 * table, not from the .xlsx, which was not available** — the same open
 * verification gap the truth table carries. `_meta` on the export names it.
 */
class RcsaWorkbookWriter
{
    public const SHEET_RCSA = 'RCSA Sheet';

    /**
     * The workbook's own columns, A to W (§4).
     *
     * The key is the field the row builder resolves; the value is the header
     * exactly as the workbook prints it. Changing a label here changes the
     * document a regulator reads, so labels are copied, not paraphrased.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'risk_no' => 'Risk No.',
        'business_unit_name' => 'Business Unit',
        'process_name' => 'Process',
        'sub_process_name' => 'Sub-Process',
        'system_names' => 'System',
        'potential_risk' => 'Potential Risk',
        'risk_driver' => 'Risk Driver (Root cause)',
        'risk_category' => 'Risk Category',
        'secondary_categories' => 'If more than one risk category is applicable, specify',
        'inherent_likelihood' => 'Likelihood (without control)',
        'inherent_impact' => 'Impact (without control)',
        'inherent_score' => 'Risk Score',
        'inherent_level' => 'Inherent Risk Level',
        'existing_control' => 'Existing Control',
        'control_effectiveness' => 'Control Effectiveness',
        'ce_modifier' => 'C.E Modifier',
        'residual_score' => 'Residual Risk',
        'residual_level' => 'Residual Risk Level',
        'risk_treatment' => 'Risk Treatment',
        'appetite_status' => 'Risk Appetite Alignment',
        'control_to_implement' => 'Control To Be Implemented',
        'action_owner' => 'Person to Act / Risk Owner',
        'action_target_date' => 'Implementation Date',
    ];

    /**
     * The eight columns §10.1 appends after W — what the workbook cannot say
     * because it has no workflow.
     *
     * "Last Review Date (date of download)" is the PDF's own phrasing and it is
     * deliberate: the regulator is being told when this extract was taken, not
     * when somebody last opened the assessment.
     *
     * @var array<string, string>
     */
    public const SYSTEM_COLUMNS = [
        'cycle' => 'Assessment Cycle',
        'assessor' => 'Assessor',
        'submitted_at' => 'Submitted Date',
        'orm_status' => 'ORM Status',
        'reviewer' => 'Reviewer',
        'action_plan_status' => 'Action Plan Status',
        'days_overdue' => 'Days Overdue',
        'last_review_date' => 'Last Review Date',
    ];

    /**
     * The merged banner over row 1 — `[label, first field, last field]`.
     *
     * Written as FIELD NAMES rather than column letters so that inserting a
     * column into COLUMNS moves the merge with it instead of silently
     * mis-spanning the one after.
     *
     * TAKEN FROM THE WORKBOOK, NOT FROM THE PLAN. P6 built these by reading
     * §10.1's list of five group names against §4's column table, because the
     * `.xlsx` was not available; three of the five spans were wrong. The file's
     * actual merges are `A1:I1`, `J1:M1`, `N1:O1` and `S1:W1`, with `R1`
     * carrying "Residual Risk" as a single unmerged cell.
     *
     * Two consequences are worth stating because they look like mistakes:
     *
     *   - **P and Q sit under no banner at all.** `C.E modifier` and
     *     `Residual risk` are the two derived columns the workbook leaves
     *     ungrouped, and reproducing that is the point — the document is
     *     supposed to look like the one the bank already uses.
     *   - **RISK TREATMENT PLAN starts at S, not U.** The workbook groups
     *     `Risk Treatment` and `Risk appetite alignment` into the treatment
     *     block rather than leaving them with the residual figures, which reads
     *     as the more sensible arrangement of the two.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const GROUPS = [
        ['Process', 'risk_no', 'secondary_categories'],                 // A1:I1
        ['INHERENT RISK', 'inherent_likelihood', 'inherent_level'],     // J1:M1
        ['Control assessment', 'existing_control', 'control_effectiveness'], // N1:O1
        ['Residual Risk', 'residual_level', 'residual_level'],          // R1, unmerged
        ['RISK TREATMENT PLAN', 'risk_treatment', 'action_target_date'], // S1:W1
        // Ours, over the eight columns §10.1 appends after W.
        ['ASSESSMENT RECORD', 'cycle', 'last_review_date'],
    ];

    public const GROUP_ROW = 1;

    public const HEADER_ROW = 2;

    public const FIRST_DATA_ROW = 3;

    /**
     * The working copy's hidden columns (§10.4).
     *
     * The line id is what the re-import matches on — matching on Risk No. would
     * break the moment two units used the same numbering, and matching on the
     * risk statement would break the moment somebody fixed a typo. The version
     * is what makes "somebody else changed this since you exported" detectable
     * at all: it is the same optimistic-locking counter the grid sends, so a
     * line whose version moved has been written by somebody between the export
     * and the upload.
     */
    public const HIDDEN_COLUMNS = ['line_id' => '__line_id', 'version' => '__version'];

    private const HEADER_FILL = '1F3864';

    private const GROUP_FILL = '2E5496';

    private const CALCULATED_FILL = 'EDEDED';

    /**
     * The columns a person does not fill in: calculated by the engine, or
     * snapshotted master data.
     *
     * Greyed in every sheet and, in the working copy, locked — §10.4 asks for
     * "formula-locked computed columns" and the honest version of that is a
     * cell the round-trip refuses to read back rather than one Excel merely
     * discourages typing into. `workingCopy()` does both.
     *
     * @var list<string>
     */
    public const CALCULATED = [
        'inherent_score', 'inherent_level', 'ce_modifier',
        'residual_score', 'residual_level', 'risk_treatment', 'appetite_status',
    ];

    /**
     * The only columns the offline round-trip reads back.
     *
     * The assessor's three answers plus the two free-text fields they may
     * defend them with. Everything else in the file is either computed here or
     * snapshotted from master data, and accepting it back would let a
     * spreadsheet rewrite a risk statement, a business unit or a residual score.
     *
     * @var list<string>
     */
    public const ROUND_TRIP_FIELDS = [
        'inherent_likelihood',
        'inherent_impact',
        'control_effectiveness',
        'assessment_rationale',
        'treatment_override',
    ];

    /* ------------------------------------------------------------------ */
    /*  The mandated export (§10.1) */
    /* ------------------------------------------------------------------ */

    /**
     * The bulk download: many assessments, one sheet, as bytes.
     *
     * @param  Collection<int, RcsaAssessmentLine>  $lines
     * @param  array<string, mixed>  $filters  Echoed onto the cover sheet, so the file says what it is.
     */
    public function bulkExport(Collection $lines, array $filters = [], ?string $exportedBy = null): string
    {
        $spreadsheet = $this->newSpreadsheet('RCSA export');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_RCSA);

        $columns = array_merge(self::COLUMNS, self::SYSTEM_COLUMNS);

        $this->writeHeader($sheet, $columns);
        $this->writeRows($sheet, $lines, $columns, firstDataRow: self::FIRST_DATA_ROW);
        $this->finishSheet($sheet, $columns, $lines->count());

        $this->writeCoverSheet($spreadsheet, $filters, $lines->count(), $exportedBy);

        $spreadsheet->setActiveSheetIndexByName(self::SHEET_RCSA);

        return $this->toString($spreadsheet);
    }

    /* ------------------------------------------------------------------ */
    /*  The offline working copy (§10.4) */
    /* ------------------------------------------------------------------ */

    /**
     * One in-progress assessment, to be edited offline and uploaded back.
     *
     * "A genuine differentiator in the Nigerian market where branch
     * connectivity is unreliable" — and the thing that makes it safe rather
     * than merely possible is that the file carries the line id and the version
     * it was exported at. Everything computed is greyed AND excluded from the
     * read-back, so a branch manager who overtypes a residual score has
     * changed nothing.
     *
     * @param  Collection<int, RcsaAssessmentLine>  $lines
     */
    public function workingCopy(RcsaAssessment $assessment, Collection $lines): string
    {
        $spreadsheet = $this->newSpreadsheet('RCSA working copy');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_RCSA);

        // The hidden pair goes FIRST, not last: appended to the right it would
        // be the first thing an Excel user deletes while "tidying up the empty
        // columns", and the upload would then have nothing to match on.
        // array_merge would renumber nothing here (both are string-keyed) but
        // array_combine states the intent — two ordered lists zipped, in this
        // order.
        $columns = array_combine(
            array_merge(array_keys(self::HIDDEN_COLUMNS), array_keys(self::COLUMNS)),
            array_merge(array_values(self::HIDDEN_COLUMNS), array_values(self::COLUMNS)),
        );

        // One header row, not two: the group banner belongs on the document a
        // regulator reads, and on a working copy it would only push the
        // assessor's rows further from the top of the screen.
        $this->writeHeader($sheet, $columns, groups: false);
        $this->writeRows($sheet, $lines, $columns, firstDataRow: self::HEADER_ROW);
        $this->finishSheet($sheet, $columns, $lines->count(), headerRow: self::GROUP_ROW);

        // Hidden, and narrow rather than zero-width, so that a user who unhides
        // them sees something explicable instead of a mystery.
        foreach (array_keys(self::HIDDEN_COLUMNS) as $index => $field) {
            $sheet->getColumnDimension($this->letter($index + 1))->setVisible(false);
        }

        $this->lockCalculatedColumns($sheet, $columns, $lines->count(), headerRow: self::GROUP_ROW);
        $this->writeWorkingCopyInstructions($spreadsheet, $assessment, $lines->count());

        $spreadsheet->setActiveSheetIndexByName(self::SHEET_RCSA);

        return $this->toString($spreadsheet);
    }

    /* ------------------------------------------------------------------ */
    /*  One row */
    /* ------------------------------------------------------------------ */

    /**
     * A line as the workbook prints it.
     *
     * @return array<string, mixed>
     */
    public function row(RcsaAssessmentLine $line): array
    {
        $assessment = $line->getRelationValue('assessment');
        $plans = $line->relationLoaded('actionPlans') ? $line->actionPlans : collect();

        return [
            /* --- The hidden pair, present only in the working copy ------- */
            'line_id' => $line->id,
            'version' => $line->version,

            /* --- A to I: master data as it was snapshotted -------------- */
            'risk_no' => $line->risk_no,
            'business_unit_name' => $line->business_unit_name,
            'process_name' => $line->process_name,
            'sub_process_name' => $line->sub_process_name,
            'system_names' => implode('; ', (array) ($line->system_names ?? [])),
            'potential_risk' => $line->potential_risk,
            'risk_driver' => $line->risk_driver,
            'risk_category' => $line->risk_category,
            'secondary_categories' => implode('; ', (array) ($line->secondary_categories ?? [])),

            /* --- J to M: inherent --------------------------------------- */
            'inherent_likelihood' => $line->inherent_likelihood,
            'inherent_impact' => $line->inherent_impact,
            'inherent_score' => $line->inherent_score,
            'inherent_level' => $this->humanise($line->inherent_level),

            /* --- N to P: control ---------------------------------------- */
            'existing_control' => $line->existing_control,
            'control_effectiveness' => $line->control_effectiveness,
            'ce_modifier' => $line->ce_modifier,

            /* --- Q to T: residual --------------------------------------- */
            'residual_score' => $line->residual_score,
            'residual_level' => $this->humanise($line->residual_level),
            // The treatment IN FORCE — an override is what the bank decided, and
            // exporting the calculated value beside an override nobody can see
            // would misreport the decision.
            'risk_treatment' => $line->effectiveTreatment(),
            'appetite_status' => $line->appetite_status,

            /* --- U to W: the plan, flattened ----------------------------- */
            'control_to_implement' => $plans->pluck('control_to_implement')->filter()->implode("\n"),
            'action_owner' => $plans
                ->map(fn (RcsaActionPlan $plan) => $plan->getRelationValue('owner')?->name)
                ->filter()
                ->implode("\n"),
            'action_target_date' => $plans
                ->map(fn (RcsaActionPlan $plan) => $plan->target_date?->toDateString())
                ->filter()
                ->implode("\n"),

            /* --- The eight system columns (§10.1) ------------------------ */
            'cycle' => $assessment?->getRelationValue('cycle')?->name,
            'assessor' => $this->assessorName($line, $assessment),
            'submitted_at' => $assessment?->submitted_at?->toDateString(),
            'orm_status' => $this->humanise((string) $assessment?->status),
            'reviewer' => $assessment?->getRelationValue('reviewer')?->name,
            'action_plan_status' => $this->planStatus($plans),
            'days_overdue' => $this->daysOverdue($plans),
            // The date of download, per the process-flow PDF.
            'last_review_date' => now()->toDateString(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Sheet building */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, string>  $columns
     */
    private function writeHeader(Worksheet $sheet, array $columns, bool $groups = true): void
    {
        $headerRow = $groups ? self::HEADER_ROW : self::GROUP_ROW;

        if ($groups) {
            $this->writeGroupRow($sheet, $columns);
        }

        $index = 1;

        foreach ($columns as $field => $label) {
            $letter = $this->letter($index);

            $sheet->setCellValue($letter.$headerRow, $label);

            $sheet->getStyle($letter.$headerRow)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]],
                'alignment' => [
                    'wrapText' => true,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $sheet->getColumnDimension($letter)->setWidth($this->widthFor($field));

            $index++;
        }

        $sheet->getRowDimension($headerRow)->setRowHeight(38);
    }

    /**
     * The merged banner of §10.1.
     *
     * @param  array<string, string>  $columns
     */
    private function writeGroupRow(Worksheet $sheet, array $columns): void
    {
        $positions = array_flip(array_keys($columns));

        foreach (self::GROUPS as [$label, $from, $to]) {
            if (! isset($positions[$from], $positions[$to])) {
                continue;
            }

            $first = $this->letter($positions[$from] + 1);
            $last = $this->letter($positions[$to] + 1);

            // A one-column group is NOT merged. The workbook's "Residual Risk"
            // sits alone over R, and PhpSpreadsheet treats a same-cell merge as
            // an error rather than a no-op.
            if ($first !== $last) {
                $sheet->mergeCells(sprintf('%s%d:%s%d', $first, self::GROUP_ROW, $last, self::GROUP_ROW));
            }

            $sheet->setCellValue($first.self::GROUP_ROW, $label);

            $sheet->getStyle(sprintf('%s%d:%s%d', $first, self::GROUP_ROW, $last, self::GROUP_ROW))
                ->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GROUP_FILL]],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);
        }

        $sheet->getRowDimension(self::GROUP_ROW)->setRowHeight(22);
    }

    /**
     * @param  Collection<int, RcsaAssessmentLine>  $lines
     * @param  array<string, string>  $columns
     */
    private function writeRows(Worksheet $sheet, Collection $lines, array $columns, int $firstDataRow): void
    {
        $row = $firstDataRow;

        foreach ($lines as $line) {
            $values = $this->row($line);
            $index = 1;

            foreach (array_keys($columns) as $field) {
                $sheet->setCellValue($this->letter($index).$row, $values[$field] ?? null);
                $index++;
            }

            $sheet->getStyle(sprintf('A%d:%s%d', $row, $this->letter(count($columns)), $row))
                ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            $row++;
        }
    }

    /**
     * Freeze panes, grey the calculated block, and turn on the filter.
     *
     * @param  array<string, string>  $columns
     */
    private function finishSheet(Worksheet $sheet, array $columns, int $rows, ?int $headerRow = null): void
    {
        $headerRow ??= self::HEADER_ROW;
        $lastColumn = $this->letter(count($columns));
        $lastRow = $headerRow + max($rows, 1);

        // Freeze below the header and to the right of the risk number: a
        // twenty-three column sheet is unreadable if the identity of the row
        // scrolls away from the numbers.
        $sheet->freezePane('B'.($headerRow + 1));
        $sheet->setAutoFilter(sprintf('A%d:%s%d', $headerRow, $lastColumn, $lastRow));

        $positions = array_flip(array_keys($columns));

        foreach (self::CALCULATED as $field) {
            if (! isset($positions[$field])) {
                continue;
            }

            $letter = $this->letter($positions[$field] + 1);

            $sheet->getStyle(sprintf('%s%d:%s%d', $letter, $headerRow + 1, $letter, $lastRow))
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::CALCULATED_FILL);
        }
    }

    /**
     * Excel's own protection over the computed columns.
     *
     * A COURTESY, NOT A BOUNDARY — PhpSpreadsheet protection without a password
     * is off in two clicks, which is exactly why the round-trip refuses to read
     * these columns back rather than trusting the lock. It is here to stop
     * accidents on a laptop, and `ROUND_TRIP_FIELDS` is what stops the rest.
     *
     * @param  array<string, string>  $columns
     */
    private function lockCalculatedColumns(Worksheet $sheet, array $columns, int $rows, int $headerRow): void
    {
        $lastColumn = $this->letter(count($columns));

        // The working copy's header is row 1, the export's is row 2. Assuming
        // the export's here styled one row PAST the data, and PhpSpreadsheet
        // reports a styled row as a row — so the file the product produced came
        // back through its own parser with a phantom blank line on the end.
        $lastRow = $headerRow + max($rows, 1);

        $sheet->getProtection()->setSheet(true);

        // Everything unlocked first, then the computed block locked back: the
        // default is a locked cell, and a sheet where only the answers are
        // editable is the opposite of what an assessor needs.
        $sheet->getStyle(sprintf('A1:%s%d', $lastColumn, $lastRow))
            ->getProtection()
            ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);

        $positions = array_flip(array_keys($columns));

        foreach (array_merge(self::CALCULATED, array_keys(self::HIDDEN_COLUMNS)) as $field) {
            if (! isset($positions[$field])) {
                continue;
            }

            $letter = $this->letter($positions[$field] + 1);

            $sheet->getStyle(sprintf('%s1:%s%d', $letter, $letter, $lastRow))
                ->getProtection()
                ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED);
        }
    }

    /**
     * The cover sheet — what this file is, who took it and under what filters.
     *
     * §10.2 logs the same facts to `rcsa_export_jobs`, and they are repeated
     * here because the log stays in the system while the file goes to the
     * regulator. A spreadsheet on somebody's desktop with no provenance is how
     * a stale extract gets quoted in a board paper.
     *
     * @param  array<string, mixed>  $filters
     */
    private function writeCoverSheet(Spreadsheet $spreadsheet, array $filters, int $rows, ?string $exportedBy): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('About this export');

        $facts = [
            ['Document', 'RCSA assessment export'],
            ['Generated', now()->toDayDateTimeString()],
            ['Exported by', $exportedBy ?? 'Unknown'],
            ['Rows', (string) $rows],
            ['Layout', 'SB_RCSA Template 2026 — columns A to W, plus eight assessment-record columns'],
        ];

        foreach ($filters as $key => $value) {
            if (blank($value)) {
                continue;
            }

            $facts[] = [
                'Filter — '.$this->humanise((string) $key),
                is_array($value) ? implode(', ', $value) : (string) $value,
            ];
        }

        $row = 1;

        foreach ($facts as [$label, $value]) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->setCellValue('B'.$row, $value);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(70);
        $sheet->getStyle('A1:B'.($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    private function writeWorkingCopyInstructions(Spreadsheet $spreadsheet, RcsaAssessment $assessment, int $rows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Read me first');

        $unitModel = $assessment->getRelationValue('businessUnit');
        $cycleModel = $assessment->getRelationValue('cycle');

        $unit = $unitModel === null ? 'this business unit' : (string) $unitModel->name;
        $cycle = $cycleModel === null ? 'the current cycle' : (string) $cycleModel->name;

        $lines = [
            ['Working copy', sprintf('%s — %s', $unit, $cycle)],
            ['Exported', now()->toDayDateTimeString()],
            ['Risks', (string) $rows],
            ['', ''],
            ['Fill in', 'Likelihood (without control), Impact (without control) and Control Effectiveness.'],
            ['Also read back', 'Risk Treatment and the assessment rationale, where you change them.'],
            ['Ignored on upload', 'Every grey column. They are calculated by the system when you upload, so '
                .'anything typed into them is discarded rather than trusted.'],
            ['', ''],
            ['Do not', 'Delete or reorder columns, or delete rows. Two hidden columns at the far left carry '
                .'the identity of each risk; without them the upload cannot tell your rows apart.'],
            ['If somebody else edits', 'A risk changed in the system after you took this file is NOT overwritten '
                .'silently. The upload lists it and asks you which answer to keep.'],
        ];

        $row = 1;

        foreach ($lines as [$label, $value]) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->setCellValue('B'.$row, $value);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $sheet->getStyle('B'.$row)->getAlignment()->setWrapText(true);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(80);
    }

    /* ------------------------------------------------------------------ */
    /*  Derived values */
    /* ------------------------------------------------------------------ */

    /**
     * One phrase for a line's whole plan set.
     *
     * WORST-FIRST, not a count. A risk with three plans of which one is overdue
     * is an overdue risk; reporting "3 plans" or "1 of 3 closed" lets the
     * overdue one hide behind the arithmetic on a Board pack.
     *
     * @param  Collection<int, RcsaActionPlan>  $plans
     */
    private function planStatus(Collection $plans): string
    {
        if ($plans->isEmpty()) {
            return 'None';
        }

        if ($plans->contains(fn (RcsaActionPlan $plan) => $plan->isOverdue())) {
            return 'Overdue';
        }

        foreach ([RcsaActionPlan::OPEN, RcsaActionPlan::IN_PROGRESS, RcsaActionPlan::COMPLETED] as $status) {
            if ($plans->contains('status', $status)) {
                return $this->humanise($status);
            }
        }

        return 'Closed';
    }

    /**
     * The worst overdue figure on the line, positive in days, blank when none.
     *
     * @param  Collection<int, RcsaActionPlan>  $plans
     */
    private function daysOverdue(Collection $plans): ?int
    {
        $worst = $plans
            ->filter(fn (RcsaActionPlan $plan) => $plan->isOverdue())
            ->map(fn (RcsaActionPlan $plan) => -1 * (int) $plan->daysUntilDue())
            ->max();

        return $worst === null ? null : (int) $worst;
    }

    /* ------------------------------------------------------------------ */
    /*  Plumbing */
    /* ------------------------------------------------------------------ */

    private function newSpreadsheet(string $title): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        $spreadsheet->getProperties()
            ->setCreator('Atheris ERM')
            ->setTitle($title)
            ->setDescription('Generated '.now()->toDateTimeString().' from the RCSA module.');

        return $spreadsheet;
    }

    /**
     * Who answered the line, falling back to whoever filed the assessment.
     *
     * Explicit null checks rather than a chain of `?->name ?? ...`: larastan
     * types a `belongsTo` as non-nullable and rejects the nullsafe as dead
     * code, while the relation genuinely is null when a caller has not loaded
     * it. This ends up in a document a regulator reads, so a blank beats a
     * fatal — the same shape RcsaSubmissionService uses for the same reason.
     */
    private function assessorName(RcsaAssessmentLine $line, ?RcsaAssessment $assessment): ?string
    {
        $assessor = $line->getRelationValue('assessor');

        if ($assessor !== null) {
            return (string) $assessor->name;
        }

        $submitter = $assessment?->getRelationValue('submitter');

        return $submitter === null ? null : (string) $submitter->name;
    }

    /**
     * `very_high` is a key, not a label. Printing it raw is how a regulator's
     * copy of the bank's risk profile comes to say "very_high".
     */
    private function humanise(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $words = str_replace('_', ' ', $value);

        return mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    private function widthFor(string $field): int
    {
        return match ($field) {
            'potential_risk', 'risk_driver', 'existing_control', 'control_to_implement' => 50,
            'appetite_status', 'secondary_categories' => 30,
            'business_unit_name', 'process_name', 'sub_process_name', 'action_owner', 'cycle' => 24,
            'inherent_likelihood', 'inherent_impact' => 14,
            default => 16,
        };
    }

    private function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function toString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();

        // PhpSpreadsheet holds the whole workbook in memory and a bulk export
        // is the largest one this module makes; releasing it here keeps a burst
        // of concurrent downloads from stacking.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $contents;
    }
}
