<?php

namespace App\Services\Tprm;

use App\Enums\Tprm\ThirdPartyStatus;
use App\Models\Tprm\Category;
use App\Models\Tprm\ImportBatch;
use App\Models\Tprm\ThirdParty;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bulk import of the third-party register — FR-TPR-09.
 *
 * Upload → column mapping → dry run with per-row errors → commit → rollback.
 *
 * THE DRY RUN IS THE FEATURE. TRD §18 expects a bank to arrive with a vendor
 * list of several hundred rows in whatever shape its procurement system emits,
 * and the thing that decides whether the migration happens is whether the
 * first attempt tells them precisely what is wrong with row 214 or just fails.
 * So every row is validated, every failure carries its row number and its
 * reason, and nothing is written until the user has seen the list.
 *
 * TWO RULES THE ROW VALIDATION DOES NOT ENFORCE, deliberately:
 *
 *   A DUPLICATE IS A WARNING, NOT AN ERROR. FR-TPR-02 says duplicates surface
 *   as merge candidates rather than blocking, and that holds here: a file of
 *   400 vendors that refuses to import because 3 look like existing records is
 *   a file the user gives up on. They import, flagged, and the register's
 *   dedupe view is where they get merged.
 *
 *   THE "MINIMUM VIABLE RECORD" RULE OF TRD §18. Only the legal name is
 *   required. A bank migrating a legacy list has RC numbers for some vendors
 *   and not others, and demanding the full field set at import is what turns a
 *   ramp into a cliff — the data-quality dashboard is how the rest gets
 *   collected, not the import gate.
 */
class ThirdPartyImporter
{
    /**
     * The columns an import may map to, and whether one is required.
     *
     * @var array<string, array{label: string, required: bool, hint: string|null}>
     */
    public const COLUMNS = [
        'legal_name' => ['label' => 'Legal name', 'required' => true, 'hint' => 'The registered company name.'],
        'trading_name' => ['label' => 'Trading name', 'required' => false, 'hint' => null],
        'registration_number' => ['label' => 'RC number', 'required' => false, 'hint' => 'CAC registration number.'],
        'tax_id' => ['label' => 'TIN', 'required' => false, 'hint' => null],
        'lei' => ['label' => 'LEI', 'required' => false, 'hint' => 'Exactly 20 characters where present.'],
        'entity_type' => ['label' => 'Entity type', 'required' => false, 'hint' => 'company, partnership, sole_proprietor, government, ngo, intra_group.'],
        'country_of_incorporation' => ['label' => 'Country of incorporation', 'required' => false, 'hint' => 'Two-letter ISO code.'],
        'country_of_hq' => ['label' => 'Country of HQ', 'required' => false, 'hint' => 'Two-letter ISO code.'],
        'website' => ['label' => 'Website', 'required' => false, 'hint' => null],
        'category' => ['label' => 'Category', 'required' => false, 'hint' => 'Matched by name or code against the category taxonomy.'],
        'status' => ['label' => 'Status', 'required' => false, 'hint' => 'Defaults to prospect.'],
        'notes' => ['label' => 'Notes', 'required' => false, 'hint' => null],
    ];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ThirdPartyDeduplicator $deduplicator,
    ) {}

    /**
     * The file's headers plus a suggested mapping.
     *
     * The suggestion is a convenience and is confirmed by the user, never
     * applied silently: a column headed "Name" could be the legal name or the
     * contact's, and guessing wrong writes a person into the register as a
     * company.
     *
     * @return array{headers: list<string>, suggestion: array<string, string|null>, sample: list<list<string|null>>}
     */
    public function inspect(ImportBatch $batch): array
    {
        $path = Storage::disk('local')->path($batch->file_path);

        $headers = $this->reader->headers($path);
        $rows = array_slice($this->reader->dataRows($path), 0, 5);

        return [
            'headers' => $headers,
            'suggestion' => $this->suggestMapping($headers),
            'sample' => $rows,
        ];
    }

    /**
     * Validate every row against the confirmed mapping, without writing.
     *
     * @param  array<string, string|null>  $mapping  our column => the file's header
     */
    public function dryRun(ImportBatch $batch, array $mapping): ImportBatch
    {
        $path = Storage::disk('local')->path($batch->file_path);
        $headers = $this->reader->headers($path);
        $rows = $this->reader->dataRows($path);

        $index = [];
        foreach ($mapping as $column => $header) {
            $position = $header === null ? false : array_search($header, $headers, true);

            if ($position !== false) {
                $index[$column] = $position;
            }
        }

        $problems = [];
        $valid = 0;
        $seenNames = [];

        foreach ($rows as $offset => $row) {
            // +2: spreadsheets are 1-indexed and the header is row 1, so the
            // number in the error is the number in the user's own file.
            $lineNumber = $offset + 2;
            $attributes = $this->extract($row, $index);
            $rowProblems = $this->validateRow($attributes, $lineNumber, $seenNames);

            $blocking = array_filter($rowProblems, fn (array $p) => $p['severity'] === 'error');

            if ($blocking === []) {
                $valid++;
            }

            $problems = array_merge($problems, $rowProblems);
        }

        $batch->forceFill([
            'column_mapping' => $mapping,
            'status' => ImportBatch::STATUS_VALIDATED,
            'rows_total' => count($rows),
            'rows_valid' => $valid,
            'rows_failed' => count($rows) - $valid,
            'errors' => array_slice($problems, 0, 500),
        ])->save();

        return $batch;
    }

    /**
     * Write the valid rows, recording every id created so the batch can be
     * undone.
     */
    public function commit(ImportBatch $batch, ?int $userId = null): ImportBatch
    {
        $path = Storage::disk('local')->path($batch->file_path);
        $headers = $this->reader->headers($path);
        $rows = $this->reader->dataRows($path);
        $mapping = (array) $batch->column_mapping;

        $index = [];
        foreach ($mapping as $column => $header) {
            $position = $header === null ? false : array_search($header, $headers, true);

            if ($position !== false) {
                $index[$column] = $position;
            }
        }

        $created = [];
        $seenNames = [];

        DB::transaction(function () use ($rows, $index, $batch, $userId, &$created, &$seenNames) {
            foreach ($rows as $offset => $row) {
                $attributes = $this->extract($row, $index);
                $problems = $this->validateRow($attributes, $offset + 2, $seenNames);

                if (array_filter($problems, fn (array $p) => $p['severity'] === 'error') !== []) {
                    continue;
                }

                $thirdParty = ThirdParty::create($this->toModelAttributes($attributes, $batch) + [
                    'created_by' => $userId,
                ]);

                $created[] = $thirdParty->getKey();
            }
        });

        $batch->forceFill([
            'status' => ImportBatch::STATUS_COMMITTED,
            'created_ids' => $created,
            'committed_at' => now(),
        ])->save();

        return $batch;
    }

    /**
     * Undo a committed batch.
     *
     * Rows the import created AND NOTHING ELSE. A third party that has since
     * had an engagement raised against it is kept and reported, because
     * deleting it would take the engagement's history with it — the rollback
     * exists to undo a bad import, not to erase work done since.
     *
     * @return array{deleted: int, kept: list<string>}
     */
    public function rollBack(ImportBatch $batch): array
    {
        $kept = [];
        $deleted = 0;

        DB::transaction(function () use ($batch, &$kept, &$deleted) {
            foreach ((array) $batch->created_ids as $id) {
                $thirdParty = ThirdParty::find($id);

                if ($thirdParty === null) {
                    continue;
                }

                if ($thirdParty->engagements()->exists()) {
                    $kept[] = $thirdParty->legal_name;

                    continue;
                }

                $thirdParty->delete();
                $deleted++;
            }

            $batch->forceFill([
                'status' => ImportBatch::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
            ])->save();
        });

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $seenNames
     * @return list<array{row: int, column: string, severity: string, message: string}>
     */
    private function validateRow(array $attributes, int $lineNumber, array &$seenNames): array
    {
        $problems = [];
        $name = trim((string) ($attributes['legal_name'] ?? ''));

        // The one required field — TRD §18's minimum viable record.
        if ($name === '') {
            $problems[] = $this->problem($lineNumber, 'legal_name', 'error', 'A legal name is required.');

            return $problems;
        }

        if (mb_strlen($name) > 255) {
            $problems[] = $this->problem($lineNumber, 'legal_name', 'error', 'The legal name is longer than 255 characters.');
        }

        // A file that repeats a name is almost always a file with one row per
        // CONTRACT rather than one per vendor, which is worth saying plainly
        // rather than importing the vendor five times.
        $key = Str::lower($name);
        if (isset($seenNames[$key])) {
            $problems[] = $this->problem(
                $lineNumber, 'legal_name', 'error',
                "The same legal name appears on row {$seenNames[$key]}. Each third party belongs in the file once; "
                .'if this is a second service from the same vendor, it is a second engagement rather than a second row.'
            );
        } else {
            $seenNames[$key] = $lineNumber;
        }

        $lei = trim((string) ($attributes['lei'] ?? ''));
        if ($lei !== '' && mb_strlen($lei) !== 20) {
            $problems[] = $this->problem($lineNumber, 'lei', 'error', 'An LEI is exactly 20 characters.');
        }

        foreach (['country_of_incorporation', 'country_of_hq'] as $column) {
            $country = trim((string) ($attributes[$column] ?? ''));

            if ($country !== '' && mb_strlen($country) !== 2) {
                $problems[] = $this->problem($lineNumber, $column, 'error', 'Use a two-letter ISO country code.');
            }
        }

        $entityType = trim((string) ($attributes['entity_type'] ?? ''));
        if ($entityType !== '' && ! in_array($entityType, ThirdParty::ENTITY_TYPES, true)) {
            $problems[] = $this->problem(
                $lineNumber, 'entity_type', 'warning',
                "\"{$entityType}\" is not a recognised entity type; the row imports without one."
            );
        }

        $status = trim((string) ($attributes['status'] ?? ''));
        if ($status !== '' && ! in_array($status, ThirdPartyStatus::values(), true)) {
            $problems[] = $this->problem(
                $lineNumber, 'status', 'warning',
                "\"{$status}\" is not a recognised status; the row imports as a prospect."
            );
        }

        $category = trim((string) ($attributes['category'] ?? ''));
        if ($category !== '' && $this->resolveCategory($category) === null) {
            $problems[] = $this->problem(
                $lineNumber, 'category', 'warning',
                "No category matches \"{$category}\"; the row imports uncategorised."
            );
        }

        // A duplicate is a warning. FR-TPR-02 refuses to block on one, and a
        // 400-row file that fails because three vendors already exist is a
        // file the user abandons.
        $candidates = $this->deduplicator->candidatesFor([
            'legal_name' => $name,
            'registration_number' => $attributes['registration_number'] ?? null,
            'tax_id' => $attributes['tax_id'] ?? null,
            'lei' => $lei !== '' ? $lei : null,
        ]);

        if ($candidates->isNotEmpty()) {
            $match = $candidates->first();
            $problems[] = $this->problem(
                $lineNumber, 'legal_name', 'duplicate',
                "Looks like the existing record \"{$match['third_party']->legal_name}\" "
                ."({$match['kind']} match on {$match['on']}). It imports anyway — merge them from the register."
            );
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toModelAttributes(array $attributes, ImportBatch $batch): array
    {
        $entityType = trim((string) ($attributes['entity_type'] ?? ''));
        $status = trim((string) ($attributes['status'] ?? ''));
        $category = trim((string) ($attributes['category'] ?? ''));
        $name = trim((string) $attributes['legal_name']);

        return [
            'organization_id' => $batch->organization_id,
            'legal_name' => $name,
            'slug' => $this->uniqueSlug($name),
            'trading_name' => $this->nullIfBlank($attributes['trading_name'] ?? null),
            'registration_number' => $this->nullIfBlank($attributes['registration_number'] ?? null),
            'tax_id' => $this->nullIfBlank($attributes['tax_id'] ?? null),
            'lei' => $this->nullIfBlank($attributes['lei'] ?? null),
            'entity_type' => in_array($entityType, ThirdParty::ENTITY_TYPES, true) ? $entityType : null,
            'country_of_incorporation' => $this->upperOrNull($attributes['country_of_incorporation'] ?? null),
            'country_of_hq' => $this->upperOrNull($attributes['country_of_hq'] ?? null),
            'website' => $this->nullIfBlank($attributes['website'] ?? null),
            'category_id' => $category === '' ? null : $this->resolveCategory($category),
            'status' => in_array($status, ThirdPartyStatus::values(), true)
                ? $status
                : ThirdPartyStatus::Prospect->value,
            'notes' => $this->nullIfBlank($attributes['notes'] ?? null),
        ];
    }

    /**
     * Suggest a mapping by normalising both sides and comparing.
     *
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    private function suggestMapping(array $headers): array
    {
        $normalise = fn (string $value) => Str::lower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');

        $byNormalised = [];
        foreach ($headers as $header) {
            $byNormalised[$normalise($header)] = $header;
        }

        // The names a procurement export actually uses, beyond our own.
        $aliases = [
            'legal_name' => ['legalname', 'name', 'vendorname', 'suppliername', 'companyname', 'thirdparty'],
            'trading_name' => ['tradingname', 'tradename', 'dba'],
            'registration_number' => ['rcnumber', 'rc', 'cacnumber', 'registrationnumber', 'companynumber'],
            'tax_id' => ['tin', 'taxid', 'taxidentificationnumber'],
            'lei' => ['lei', 'legalentityidentifier'],
            'entity_type' => ['entitytype', 'type', 'companytype'],
            'country_of_incorporation' => ['countryofincorporation', 'country', 'incorporationcountry'],
            'country_of_hq' => ['countryofhq', 'hqcountry', 'headquarters'],
            'website' => ['website', 'url', 'web'],
            'category' => ['category', 'vendorcategory', 'servicecategory', 'segment'],
            'status' => ['status', 'vendorstatus'],
            'notes' => ['notes', 'comments', 'remarks'],
        ];

        $suggestion = [];

        foreach (array_keys(self::COLUMNS) as $column) {
            $candidates = array_merge([$normalise($column)], $aliases[$column] ?? []);
            $suggestion[$column] = null;

            foreach ($candidates as $candidate) {
                if (isset($byNormalised[$candidate])) {
                    $suggestion[$column] = $byNormalised[$candidate];

                    break;
                }
            }
        }

        return $suggestion;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $index
     * @return array<string, mixed>
     */
    private function extract(array $row, array $index): array
    {
        $attributes = [];

        foreach ($index as $column => $position) {
            $attributes[$column] = $row[$position] ?? null;
        }

        return $attributes;
    }

    /** @return array{row: int, column: string, severity: string, message: string} */
    private function problem(int $row, string $column, string $severity, string $message): array
    {
        return ['row' => $row, 'column' => $column, 'severity' => $severity, 'message' => $message];
    }

    private function resolveCategory(string $value): ?int
    {
        return Category::query()
            ->where(fn ($query) => $query->where('name', $value)->orWhere('code', $value))
            ->value('id');
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function upperOrNull(mixed $value): ?string
    {
        $trimmed = strtoupper(trim((string) $value));

        return $trimmed === '' ? null : $trimmed;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'third-party';
        $slug = $base;
        $suffix = 1;

        while (ThirdParty::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
