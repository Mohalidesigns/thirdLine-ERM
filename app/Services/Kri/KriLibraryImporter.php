<?php

namespace App\Services\Kri;

use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\KriMeasureBridge;
use App\Support\Kri\KriBandRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Loads a departmental KRI library (database/data/kri-library/*.json) into the
 * current tenant: the departments as business units, each KRI on its unit with
 * its two band boundaries, so the KRI Library and the monthly return cycle pick
 * them up with no further setup.
 *
 * ADOPT, NEVER OVERWRITE. A KRI is keyed on its library `code` (kri_code). One
 * that already exists is left exactly as it is - a bank that has since tuned a
 * threshold, renamed an indicator or moved it to another unit keeps that edit
 * across a re-run, the rule `bcms:kri:adopt` follows. The one thing a re-run
 * fills in is an owner that was missing and can now be resolved.
 *
 * DEPARTMENTS are matched to a live unit by an explicit map entry (library code
 * => the bank's own unit code, for a department the bank names differently),
 * then by code, then by name (case and spacing ignored), and created only when
 * none matches. A map entry naming a unit that does not exist is an error, not
 * a fallback: the administrator said where those KRIs go. A soft-deleted unit
 * holding the code is reported, not resurrected: deleting a department was
 * someone's decision.
 *
 * OWNERS. The library names a role ("Chief Dealer"), not a person. It becomes
 * kri_owner_id only when exactly one active user in the tenant carries that job
 * title; otherwise the KRI is created without an owner and the title is
 * reported, because guessing an owner puts a breach in the wrong inbox.
 */
class KriLibraryImporter
{
    /** @var array<string, int|null>|null normalised job title => user id, or null when not exactly one; loaded once per run */
    private ?array $owners = null;

    public function __construct(private readonly KriMeasureBridge $bridge) {}

    /**
     * @param  array<string, mixed>  $library  the decoded library file
     * @param  array<string, string>  $unitMap  library unit code => existing business unit code
     * @return array{units_created: list<string>, units_matched: list<string>, units_refused: list<string>, kris_created: int, kris_existing: int, kris_skipped: list<string>, kris_deleted: list<string>, owners_assigned: int, owners_unresolved: array<string, int>}
     */
    public function import(array $library, ?int $actorId = null, bool $dryRun = false, array $unitMap = []): array
    {
        // Every read below relies on the tenant scope; with no tenant set it
        // would read every bank's units and users. This throws instead.
        TenantContext::organizationId();

        $this->validate($library, $unitMap);
        $this->owners = null;

        $result = [
            'units_created' => [], 'units_matched' => [], 'units_refused' => [],
            'kris_created' => 0, 'kris_existing' => 0, 'kris_skipped' => [], 'kris_deleted' => [],
            'owners_assigned' => 0, 'owners_unresolved' => [],
        ];

        $run = function () use ($library, $actorId, $unitMap, &$result) {
            $units = $this->importUnits($library['units'], $unitMap, $result);

            // withTrashed: a deleted library KRI still holds its kri_code under
            // the unique key, and deleting it was someone's decision - it is
            // reported, like a deleted unit, never recreated or restored.
            $existing = KeyRiskIndicator::withTrashed()
                ->whereIn('kri_code', array_column($library['kris'], 'code'))
                ->get()
                ->keyBy('kri_code');

            foreach ($library['kris'] as $row) {
                $ownerId = $this->ownerFor($row['owner_title'] ?? null);

                /** @var KeyRiskIndicator|null $kri */
                $kri = $existing->get($row['code']);

                if ($kri?->trashed()) {
                    $result['kris_deleted'][] = $row['code'];

                    continue;
                }

                if ($kri !== null) {
                    $result['kris_existing']++;

                    if ($ownerId !== null && $kri->kri_owner_id === null) {
                        $original = $kri->getAttributes();
                        $kri->update(['kri_owner_id' => $ownerId, 'owner_id' => $ownerId, 'updated_by' => $actorId]);
                        AuditTrailService::recordChanges($kri, $original);
                        $result['owners_assigned']++;
                    }

                    // Unowned only if it still has no owner: one set by hand
                    // since the last run is not reported as missing.
                    if ($kri->kri_owner_id === null) {
                        $this->unresolved($result, $row);
                    }

                    continue;
                }

                $unitId = $units[$row['unit']] ?? null;

                if ($unitId === null) {
                    $result['kris_skipped'][] = $row['code'];

                    continue;
                }

                $this->createKri($row, $unitId, $ownerId, $actorId);
                $result['kris_created']++;

                if ($ownerId !== null) {
                    $result['owners_assigned']++;
                } else {
                    $this->unresolved($result, $row);
                }
            }
        };

        // One transaction: a library is adopted whole or not at all, and a dry
        // run is the same work rolled back, so it reports exactly what a real
        // run would do.
        DB::beginTransaction();

        try {
            $run();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * @param  list<array{code: string, name: string, parent: ?string}>  $rows
     * @param  array<string, string>  $unitMap
     * @param  array<string, mixed>  $result
     * @return array<string, int> library unit code => business_units.id
     */
    private function importUnits(array $rows, array $unitMap, array &$result): array
    {
        $live = BusinessUnit::query()->get(['id', 'code', 'name', 'parent_id']);
        $byCode = $live->keyBy(fn (BusinessUnit $u) => strtolower((string) $u->code));
        $byName = $live->keyBy(fn (BusinessUnit $u) => $this->key((string) $u->name));

        $trashedCodes = BusinessUnit::onlyTrashed()
            ->pluck('code')
            ->map(fn ($code) => strtolower((string) $code))
            ->all();

        $ids = [];

        // Parents first, so a child can point at one created in this run.
        usort($rows, fn (array $a, array $b) => ($a['parent'] === null ? 0 : 1) <=> ($b['parent'] === null ? 0 : 1));

        foreach ($rows as $row) {
            if (isset($unitMap[$row['code']])) {
                $found = $byCode->get(strtolower($unitMap[$row['code']]))
                    ?? throw new InvalidArgumentException("--map sends {$row['code']} to unit {$unitMap[$row['code']]}, which does not exist.");
            } else {
                $found = $byCode->get(strtolower($row['code'])) ?? $byName->get($this->key($row['name']));
            }

            if ($found !== null) {
                $ids[$row['code']] = (int) $found->id;
                $result['units_matched'][] = "{$row['code']} => {$found->name}";

                continue;
            }

            if (in_array(strtolower($row['code']), $trashedCodes, true)) {
                $result['units_refused'][] = "{$row['code']} ({$row['name']}): a deleted unit holds this code";

                continue;
            }

            $unit = BusinessUnit::create([
                'organization_id' => TenantContext::organizationId(),
                'parent_id' => $row['parent'] === null ? null : ($ids[$row['parent']] ?? null),
                'code' => $row['code'],
                'name' => $row['name'],
                'is_active' => true,
            ]);

            AuditTrailService::record($unit, 'create');

            $ids[$row['code']] = (int) $unit->id;
            $result['units_created'][] = "{$row['code']} ({$row['name']})";
        }

        return $ids;
    }

    /**
     * Written the way KriService::create() writes a KRI - both column families,
     * the six band columns from KriBandRule's one fan-out, the measure engine's
     * definition synced so the KRI has bands before its first reading, and an
     * audit row - with the library code as kri_code so a re-run can find it.
     *
     * @param  array<string, mixed>  $row
     */
    private function createKri(array $row, int $unitId, ?int $ownerId, ?int $actorId): KeyRiskIndicator
    {
        $direction = $row['direction'];

        $kri = KeyRiskIndicator::create(array_merge([
            'organization_id' => TenantContext::organizationId(),
            'kri_code' => $row['code'],
            'business_unit_id' => $unitId,
            'kri_name' => $row['name'],
            'name' => $row['name'],
            'description' => $row['description'] ?? '',
            'metric_formula' => '',
            'data_source' => '',
            'measurement_frequency' => $row['frequency'],
            'measurement_unit' => $row['measurement_unit'],
            'unit_of_measure' => $row['measurement_unit'],
            'direction' => $direction,
            'threshold_direction' => $direction === 'higher_is_worse' ? 'higher_worse' : 'lower_worse',
            'kri_owner_id' => $ownerId,
            'owner_id' => $ownerId,
            'current_status' => 'green',
            'is_active' => true,
            'created_by' => $actorId,
        ], KriBandRule::bandColumns($direction, $row['green_threshold'] ?? null, $row['red_threshold'] ?? null)));

        $this->bridge->syncDefinition($kri);

        AuditTrailService::record($kri, 'create');

        return $kri;
    }

    private function ownerFor(?string $title): ?int
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        // One read of the tenant's titled users per run, grouped by title.
        $this->owners ??= User::query()
            ->where('is_active', true)
            ->whereNotNull('job_title')
            ->get(['id', 'job_title'])
            ->groupBy(fn (User $user) => $this->key((string) $user->job_title))
            ->map(fn ($users) => $users->count() === 1 ? (int) $users->first()->id : null)
            ->all();

        return $this->owners[$this->key($title)] ?? null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $row
     */
    private function unresolved(array &$result, array $row): void
    {
        $title = $row['owner_title'] ?? null;

        if ($title !== null && trim($title) !== '') {
            $result['owners_unresolved'][$title] = ($result['owners_unresolved'][$title] ?? 0) + 1;
        }
    }

    /** Case, spacing and the stray space before a comma ("Head , Legal") ignored. */
    private function key(string $value): string
    {
        return strtolower(trim((string) preg_replace(['/\s+/', '/\s+,/'], [' ', ','], $value)));
    }

    /**
     * Reject a malformed file before anything is written.
     *
     * @param  array<string, mixed>  $library
     * @param  array<string, string>  $unitMap
     */
    private function validate(array $library, array $unitMap): void
    {
        if (! isset($library['units'], $library['kris']) || ! is_array($library['units']) || ! is_array($library['kris'])) {
            throw new InvalidArgumentException('A KRI library needs "units" and "kris" lists.');
        }

        $unitCodes = array_column($library['units'], 'code');

        foreach (array_keys($unitMap) as $code) {
            if (! in_array($code, $unitCodes, true)) {
                throw new InvalidArgumentException("--map names {$code}, which is not a unit in the library.");
            }
        }

        foreach ($library['units'] as $unit) {
            if (($unit['parent'] ?? null) !== null && ! in_array($unit['parent'], $unitCodes, true)) {
                throw new InvalidArgumentException("Unit {$unit['code']} names a parent the library does not define: {$unit['parent']}.");
            }
        }

        $codes = [];

        foreach ($library['kris'] as $row) {
            foreach (['code', 'unit', 'name', 'frequency', 'measurement_unit', 'direction'] as $field) {
                if (! isset($row[$field]) || $row[$field] === '') {
                    throw new InvalidArgumentException('A KRI row is missing '.$field.': '.json_encode($row['code'] ?? $row['name'] ?? $row));
                }
            }

            if (isset($codes[$row['code']])) {
                throw new InvalidArgumentException("KRI code {$row['code']} appears twice.");
            }

            $codes[$row['code']] = true;

            if (strlen($row['code']) > 20) {
                throw new InvalidArgumentException("KRI code {$row['code']} is longer than kri_code's 20 characters.");
            }

            if (! in_array($row['unit'], $unitCodes, true)) {
                throw new InvalidArgumentException("KRI {$row['code']} names a unit the library does not define: {$row['unit']}.");
            }

            if (! in_array($row['frequency'], KeyRiskIndicator::FREQUENCIES, true)) {
                throw new InvalidArgumentException("KRI {$row['code']} has a frequency the KRI form does not accept: {$row['frequency']}.");
            }

            if (! in_array($row['direction'], KeyRiskIndicator::DIRECTIONS, true)) {
                throw new InvalidArgumentException("KRI {$row['code']} has an unknown direction: {$row['direction']}.");
            }

            $green = $row['green_threshold'] ?? null;
            $red = $row['red_threshold'] ?? null;

            if ($green !== null && $red !== null) {
                $ordered = $row['direction'] === 'higher_is_worse' ? $green <= $red : $green >= $red;

                if (! $ordered) {
                    throw new InvalidArgumentException("KRI {$row['code']} has its green and red boundaries the wrong way round for {$row['direction']}.");
                }
            }
        }
    }
}
