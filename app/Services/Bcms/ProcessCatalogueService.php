<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Process;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The process catalogue and its Excel round trip.
 *
 * THE DRY RUN IS THE FEATURE. An import that half-succeeds and reports "142 of
 * 200 imported" leaves a customer with a catalogue they cannot trust and no way
 * to tell which 58 are missing. `dryRun()` validates every row and writes
 * nothing; `import()` refuses to run at all while any row is invalid. That is
 * strictly better than partial success, because the fix — correct the file and
 * re-upload — is the same either way, and only one of the two leaves the
 * database in a state somebody has to reconcile by hand.
 *
 * THE ROUND TRIP MUST BE LOSSLESS. `export()` writes the columns `import()`
 * reads, in the same order and with the same header text, because the realistic
 * workflow is export → edit in Excel → re-import. A round trip that drops a
 * column silently deletes whatever was in it.
 *
 * `is_critical_service` NEEDS A JUSTIFICATION AND THE IMPORTER ENFORCES IT. It
 * is the BOFIA/NDIC resolution-planning designation, not a convenience flag; a
 * bare TRUE in a spreadsheet cell is not something anybody can defend at an
 * examination.
 */
class ProcessCatalogueService
{
    /** The workbook columns, in order. Import reads these; export writes them. */
    public const COLUMNS = [
        'code', 'name', 'description', 'business_unit_code', 'parent_process_code',
        'owner_email', 'category', 'criticality_tier', 'is_critical_service',
        'critical_service_justification', 'regulatory_flags', 'linked_business_process_code',
    ];

    public const HEADERS = [
        'Code', 'Name', 'Description', 'Business unit code', 'Parent process code',
        'Owner email', 'Category', 'Criticality tier (1-4)', 'Critical service (yes/no)',
        'Critical service justification', 'Regulatory flags (comma separated)', 'Linked business process code',
    ];

    public function __construct(private SpreadsheetReader $reader) {}

    /**
     * Validate a workbook without writing anything.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<array{row:int, column:string, message:string}>, valid: int, invalid: int}
     */
    public function dryRun(string $path): array
    {
        $raw = $this->reader->dataRows($path);

        $units = BusinessUnit::query()->pluck('id', 'code');
        $users = User::query()->pluck('id', 'email');
        $sourceProcesses = BusinessProcess::query()->pluck('id', 'code');
        $existing = Process::query()->pluck('id', 'code');

        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($raw as $index => $cells) {
            // Spreadsheet row number as a human sees it: one header row, and
            // rows are one-based. An error report that says "row 4" when Excel
            // shows row 6 is an error report nobody can use.
            $rowNumber = $index + 2;

            $row = $this->mapRow($cells);

            if ($this->isBlank($row)) {
                continue;
            }

            $rowErrors = $this->validateRow($row, $rowNumber, $units, $users, $sourceProcesses, $seen);

            $seen[strtoupper((string) $row['code'])] = true;

            $rows[] = $row + [
                '_row' => $rowNumber,
                '_valid' => $rowErrors === [],
                '_action' => isset($existing[$row['code']]) ? 'update' : 'create',
            ];

            $errors = array_merge($errors, $rowErrors);
        }

        $valid = count(array_filter($rows, fn (array $r) => $r['_valid']));

        return [
            'rows' => $rows,
            'errors' => $errors,
            'valid' => $valid,
            'invalid' => count($rows) - $valid,
        ];
    }

    /**
     * Import a validated workbook.
     *
     * REFUSES WHILE ANY ROW IS INVALID. See the class docblock: partial success
     * is the worse of the two failure modes.
     *
     * @return array{created: int, updated: int}
     */
    public function import(string $path, ?int $userId = null): array
    {
        $preview = $this->dryRun($path);

        if ($preview['invalid'] > 0) {
            throw new \InvalidArgumentException(sprintf(
                '%d row(s) failed validation. Correct the file and re-upload; nothing has been imported.',
                $preview['invalid']
            ));
        }

        $units = BusinessUnit::query()->pluck('id', 'code');
        $users = User::query()->pluck('id', 'email');
        $sourceProcesses = BusinessProcess::query()->pluck('id', 'code');

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($preview, $units, $users, $sourceProcesses, $userId, &$created, &$updated) {
            // Two passes, because a parent may appear below its child in the
            // file and a single pass would fail on a forward reference — which
            // reads to a customer as "the importer cannot handle a hierarchy".
            foreach ($preview['rows'] as $row) {
                $process = Process::query()->updateOrCreate(
                    ['code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'business_unit_id' => $units[$row['business_unit_code']] ?? null,
                        'owner_id' => $users[$row['owner_email']] ?? null,
                        'business_process_id' => $sourceProcesses[$row['linked_business_process_code']] ?? null,
                        'category' => $row['category'],
                        'criticality_tier' => $row['criticality_tier'],
                        'is_critical_service' => $row['is_critical_service'],
                        'critical_service_justification' => $row['critical_service_justification'],
                        'regulatory_flags' => $row['regulatory_flags'],
                        'status' => 'active',
                        'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
                        'updated_by' => $userId ?? auth()->id(),
                    ]
                );

                $process->wasRecentlyCreated ? $created++ : $updated++;
            }

            foreach ($preview['rows'] as $row) {
                if (blank($row['parent_process_code'])) {
                    continue;
                }

                $parentId = Process::query()->where('code', $row['parent_process_code'])->value('id');
                Process::query()->where('code', $row['code'])->update(['parent_process_id' => $parentId]);
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * The catalogue as rows for a workbook — the same columns `import()` reads.
     *
     * @return list<list<string|null>>
     */
    public function exportRows(): array
    {
        $processes = Process::query()
            ->with(['businessUnit:id,code', 'owner:id,email', 'parent:id,code', 'businessProcess:id,code'])
            ->orderBy('code')
            ->get();

        return $processes->map(fn (Process $p) => [
            $p->code,
            $p->name,
            $p->description,
            $p->businessUnit?->code,
            $p->parent?->code,
            $p->owner?->email,
            $p->category,
            $p->criticality_tier === null ? null : (string) $p->criticality_tier,
            $p->is_critical_service ? 'yes' : 'no',
            $p->critical_service_justification,
            is_array($p->regulatory_flags) ? implode(', ', $p->regulatory_flags) : null,
            $p->businessProcess?->code,
        ])->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Validation */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<string|null>  $cells
     * @return array<string, mixed>
     */
    private function mapRow(array $cells): array
    {
        $row = [];

        foreach (self::COLUMNS as $index => $column) {
            $row[$column] = isset($cells[$index]) ? trim((string) $cells[$index]) : null;
        }

        $row['code'] = $row['code'] === '' ? null : strtoupper($row['code']);
        $row['criticality_tier'] = $row['criticality_tier'] === '' || $row['criticality_tier'] === null
            ? null
            : (int) $row['criticality_tier'];
        $row['is_critical_service'] = in_array(strtolower((string) $row['is_critical_service']), ['yes', 'y', 'true', '1'], true);
        $row['regulatory_flags'] = blank($row['regulatory_flags'])
            ? null
            : array_values(array_filter(array_map('trim', explode(',', (string) $row['regulatory_flags']))));

        foreach (['name', 'description', 'business_unit_code', 'parent_process_code', 'owner_email', 'category', 'critical_service_justification', 'linked_business_process_code'] as $key) {
            if ($row[$key] === '') {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function isBlank(array $row): bool
    {
        return blank($row['code']) && blank($row['name']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, bool>  $seen
     * @return list<array{row:int, column:string, message:string}>
     */
    private function validateRow(array $row, int $rowNumber, Collection $units, Collection $users, Collection $sourceProcesses, array $seen): array
    {
        $errors = [];

        $add = function (string $column, string $message) use (&$errors, $rowNumber): void {
            $errors[] = ['row' => $rowNumber, 'column' => $column, 'message' => $message];
        };

        if (blank($row['code'])) {
            $add('Code', 'A process needs a code.');
        } elseif (isset($seen[strtoupper((string) $row['code'])])) {
            $add('Code', "'{$row['code']}' appears more than once in this file.");
        }

        if (blank($row['name'])) {
            $add('Name', 'A process needs a name.');
        }

        if (filled($row['business_unit_code']) && ! $units->has($row['business_unit_code'])) {
            $add('Business unit code', "No business unit with code '{$row['business_unit_code']}'.");
        }

        if (filled($row['owner_email']) && ! $users->has($row['owner_email'])) {
            $add('Owner email', "No active user with the email '{$row['owner_email']}'.");
        }

        if (filled($row['linked_business_process_code']) && ! $sourceProcesses->has($row['linked_business_process_code'])) {
            $add('Linked business process code', "No entry in the organisation's process catalogue with code '{$row['linked_business_process_code']}'.");
        }

        if ($row['criticality_tier'] !== null && ($row['criticality_tier'] < 1 || $row['criticality_tier'] > 4)) {
            $add('Criticality tier (1-4)', 'The criticality tier is 1 to 4, where 1 is the most critical.');
        }

        // The BOFIA designation. A bare TRUE is not defensible at an
        // examination, so the importer will not accept one.
        if ($row['is_critical_service'] && blank($row['critical_service_justification'])) {
            $add(
                'Critical service justification',
                'A process flagged as a critical service needs a justification — this is the BOFIA/NDIC '
                .'resolution-planning register, not a convenience flag.'
            );
        }

        if (filled($row['parent_process_code']) && strtoupper((string) $row['parent_process_code']) === strtoupper((string) $row['code'])) {
            $add('Parent process code', 'A process cannot be its own parent.');
        }

        return $errors;
    }
}
