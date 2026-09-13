<?php

namespace App\Services\Configuration;

use App\Models\ConfigBundle;
use App\Models\ConfigBundleApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-05 TASK 4 — apply a bundle, or say what applying it would do.
 *
 * DRY RUN IS THE DEFAULT AND IS NOT A SEPARATE CODE PATH. `plan()` produces
 * the diff; `apply()` produces the same diff and then acts on it. There is no
 * version of this class where the preview and the write can disagree about
 * what is about to happen, because they are computed by the same call.
 *
 * THE ROLLBACK POINT IS A SNAPSHOT, NOT AN UNDO LOG. Before anything is
 * written, the target's current configuration is exported as a bundle. Rolling
 * back applies that snapshot. This is deliberately not "reverse each
 * operation": inverse operations are where rollback implementations go wrong,
 * because an inverse has to be right about everything the forward operation
 * touched, including the things it touched by accident.
 *
 * CONFLICTS BLOCK BY DEFAULT. A row both sides have changed since the last
 * apply is not merged and not overwritten; the operator is told and decides.
 * --force takes the bundle's version, which is a choice somebody made rather
 * than a default that quietly discarded a colleague's work.
 */
class ConfigurationImporter
{
    public function __construct(
        private ConfigurationExporter $exporter,
        private ConfigurationDiffer $differ,
    ) {}

    /**
     * What applying this bundle would do. Writes nothing except the log entry.
     *
     * @param  array<string, mixed>  $payload
     * @return array{diff: array<string, mixed>, checksum_ok: bool}
     */
    public function plan(array $payload, ?int $organizationId = null, bool $log = true): array
    {
        $organizationId ??= TenantContext::organizationId();

        $current = $this->exporter->payload($organizationId);
        $ancestor = $this->lastAppliedSnapshot($organizationId);

        $diff = $this->differ->diff($payload, $current, $ancestor);

        if ($log) {
            $this->record($organizationId, null, null, 'dry_run', $diff['is_empty'] ? 'no_changes' : 'ok', $diff);
        }

        return ['diff' => $diff, 'checksum_ok' => true];
    }

    /**
     * Apply a bundle inside one transaction, with a snapshot taken first.
     *
     * @return array{application: ConfigBundleApplication, diff: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function apply(
        ConfigBundle $bundle,
        ?int $organizationId = null,
        bool $force = false,
        bool $prune = false,
    ): array {
        $organizationId ??= TenantContext::organizationId();

        // A bundle edited between export and import is the most common cause
        // of a half-applied configuration, and the cheapest to detect.
        if ($bundle->checksum !== ConfigurationExporter::checksum($bundle->payload)) {
            throw ValidationException::withMessages([
                'bundle' => 'This bundle\'s checksum does not match its contents — it has been modified since '
                    .'it was exported. Re-export it rather than applying it.',
            ]);
        }

        $current = $this->exporter->payload($organizationId);
        $ancestor = $this->lastAppliedSnapshot($organizationId);
        $diff = $this->differ->diff($bundle->payload, $current, $ancestor);

        if ($diff['totals']['conflicting'] > 0 && ! $force) {
            throw ValidationException::withMessages([
                'bundle' => $diff['totals']['conflicting'].' item(s) have been changed both here and in the '
                    .'bundle since the last apply. Applying would discard the changes made here. Review the '
                    .'conflicts and re-run with --force to take the bundle\'s version.',
            ]);
        }

        if ($diff['is_empty']) {
            return [
                'application' => $this->record($organizationId, $bundle->id, null, 'apply', 'no_changes', $diff),
                'diff' => $diff,
            ];
        }

        // The rollback point, taken before anything is written.
        $snapshot = $this->exporter->export(
            code: 'snapshot-'.$bundle->code,
            name: "Pre-apply snapshot of {$bundle->name}",
            description: 'Captured automatically before applying bundle #'.$bundle->id.'.',
            organizationId: $organizationId,
            isSnapshot: true,
        );

        try {
            DB::transaction(function () use ($bundle, $organizationId, $diff, $prune) {
                $this->write($bundle->payload, $organizationId, $diff, $prune);
            });
        } catch (\Throwable $error) {
            $application = $this->record(
                $organizationId, $bundle->id, $snapshot->id, 'apply', 'failed', $diff, $error->getMessage()
            );

            throw ValidationException::withMessages([
                'bundle' => 'The apply failed and was rolled back; nothing was changed. '
                    .$error->getMessage().' (log entry #'.$application->id.')',
            ]);
        }

        \App\Models\ScoringProfile::flushResolutionCache();

        return [
            'application' => $this->record($organizationId, $bundle->id, $snapshot->id, 'apply', 'ok', $diff),
            'diff' => $diff,
        ];
    }

    /**
     * Restore the configuration to what it was before an application.
     */
    public function rollback(ConfigBundleApplication $application): ConfigBundleApplication
    {
        if ($application->snapshot_bundle_id === null) {
            throw ValidationException::withMessages([
                'application' => 'That application has no snapshot, so there is nothing to roll back to. '
                    .'Dry runs and no-op applies do not take one.',
            ]);
        }

        $snapshot = ConfigBundle::withoutGlobalScopes()->findOrFail($application->snapshot_bundle_id);
        $organizationId = $application->organization_id;

        $current = $this->exporter->payload($organizationId);
        $diff = $this->differ->diff($snapshot->payload, $current, null);

        DB::transaction(function () use ($snapshot, $organizationId, $diff) {
            // Pruning is right here and wrong on a normal apply: the snapshot
            // is a complete statement of the prior configuration, so anything
            // absent from it genuinely did not exist before.
            $this->write($snapshot->payload, $organizationId, $diff, prune: true);
        });

        \App\Models\ScoringProfile::flushResolutionCache();

        $rollback = $this->record($organizationId, $snapshot->id, null, 'rollback', 'ok', $diff);

        $application->forceFill(['rolled_back_by_application_id' => $rollback->id])->save();

        return $rollback;
    }

    /* ------------------------------------------------------------------ */
    /*  Writing */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $diff
     */
    private function write(array $payload, int $organizationId, array $diff, bool $prune): void
    {
        // Sections in dependency order, so a type exists before the attributes
        // that hang off it.
        foreach (ConfigurationSchema::applyOrder() as $section) {
            $this->writeSection($section, $payload['sections'][$section] ?? [], $organizationId, first: true);
        }

        // Second pass: references that could not be resolved on the way past
        // because their target had not been written yet — a type's
        // default_lifecycle_id points at a lifecycle that belongs to the type.
        foreach (ConfigurationSchema::applyOrder() as $section) {
            $this->writeSection($section, $payload['sections'][$section] ?? [], $organizationId, first: false);
        }

        if ($prune) {
            $this->prune($diff, $organizationId);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeSection(string $section, array $rows, int $organizationId, bool $first): void
    {
        $definition = ConfigurationSchema::sections()[$section] ?? null;

        if ($definition === null || $rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $attributes = [];

            foreach ($definition['columns'] as $column) {
                if (! array_key_exists($column, $row)) {
                    continue;
                }

                $value = $row[$column];

                $attributes[$column] = in_array($column, $definition['json'], true) && is_array($value)
                    ? json_encode($value)
                    : $value;
            }

            // Natural keys back into local ids.
            $unresolved = [];

            foreach ($definition['references'] as $column => $reference) {
                $key = $row['_refs'][$column] ?? null;

                if ($key === null) {
                    $attributes[$column] = null;

                    continue;
                }

                $id = $this->resolve($reference['section'], $key, $organizationId);

                if ($id === null) {
                    if (! $reference['nullable']) {
                        throw new \RuntimeException(
                            "Cannot place {$section} row [".ConfigurationSchema::naturalKey($section, $row)
                            ."]: it references {$reference['section']} [{$key}], which is not in the bundle "
                            .'and does not exist here.'
                        );
                    }

                    // Resolvable only on the second pass. Left alone rather
                    // than nulled, so the first pass does not erase a value
                    // the target already had.
                    $unresolved[] = $column;

                    continue;
                }

                $attributes[$column] = $id;
            }

            foreach ($unresolved as $column) {
                unset($attributes[$column]);
            }

            // The two JSON arrays of object type keys that no per-column
            // reference map can express. Without this the arrays would be
            // written as the exporting environment's codes and every
            // constraint check against them would fail silently.
            if ($section === 'object_relationship_types') {
                foreach (['from_type_ids', 'to_type_ids'] as $column) {
                    $keys = $row[$column] ?? null;

                    if ($keys === null) {
                        $attributes[$column] = null;

                        continue;
                    }

                    $ids = [];

                    foreach ((array) $keys as $key) {
                        $id = $this->resolve('object_types', (string) $key, $organizationId);

                        if ($id === null) {
                            throw new \RuntimeException(
                                "Relationship type [{$row['code']}] is constrained to object type [{$key}], "
                                .'which is not in the bundle and does not exist here.'
                            );
                        }

                        $ids[] = $id;
                    }

                    $attributes[$column] = json_encode($ids);
                }
            }

            if ($first === false && $unresolved === []) {
                // Nothing was deferred, so the first pass already wrote it.
                continue;
            }

            $this->upsert($section, $definition, $attributes, $row, $organizationId);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $row
     */
    private function upsert(string $section, array $definition, array $attributes, array $row, int $organizationId): void
    {
        $table = DB::table($definition['table']);
        $lookup = [];

        foreach ($definition['key'] as $column) {
            $lookup[$column] = $attributes[$column] ?? null;

            if ($lookup[$column] === null && isset($definition['references'][$column])) {
                $lookup[$column] = $this->resolve(
                    $definition['references'][$column]['section'],
                    (string) ($row['_refs'][$column] ?? ''),
                    $organizationId
                );
            }
        }

        if ($definition['tenantScoped']) {
            $attributes['organization_id'] = $organizationId;
            $lookup['organization_id'] = $organizationId;
        }

        if ($definition['systemFlag'] !== null) {
            // An imported row is never a system row: system rows are the
            // platform's seed, present in every environment already.
            $attributes[$definition['systemFlag']] = false;
        }

        $existing = $table->where($lookup)->first();

        if ($existing !== null) {
            $attributes['updated_at'] = now();
            DB::table($definition['table'])->where('id', $existing->id)->update($attributes);
            $this->cacheId($section, $definition, $row, (int) $existing->id, $organizationId);

            return;
        }

        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();

        $id = DB::table($definition['table'])->insertGetId($attributes);
        $this->cacheId($section, $definition, $row, (int) $id, $organizationId);
    }

    /**
     * Removals, applied only when explicitly asked for.
     *
     * @param  array<string, mixed>  $diff
     */
    private function prune(array $diff, int $organizationId): void
    {
        // Reverse dependency order: children before the parents they hang off.
        foreach (array_reverse(ConfigurationSchema::applyOrder()) as $section) {
            $definition = ConfigurationSchema::sections()[$section] ?? null;
            $removed = $diff['sections'][$section]['removed'] ?? [];

            if ($definition === null || $removed === []) {
                continue;
            }

            foreach ($removed as $entry) {
                $id = $this->resolve($section, $entry['key'], $organizationId);

                if ($id === null) {
                    continue;
                }

                $query = DB::table($definition['table'])->where('id', $id);

                if ($definition['tenantScoped']) {
                    // Never prune a system row, whatever the bundle omits.
                    $query->where('organization_id', $organizationId);
                }

                \Illuminate\Support\Facades\Schema::hasColumn($definition['table'], 'deleted_at')
                    ? $query->update(['deleted_at' => now()])
                    : $query->delete();
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Natural key resolution */
    /* ------------------------------------------------------------------ */

    /** @var array<string, array<string, int|null>> */
    private array $idCache = [];

    /**
     * The local id of the row a natural key names, or null if it has none yet.
     */
    private function resolve(string $section, string $key, int $organizationId): ?int
    {
        if (array_key_exists($key, $this->idCache[$section] ?? [])) {
            return $this->idCache[$section][$key];
        }

        $definition = ConfigurationSchema::sections()[$section] ?? null;

        if ($definition === null || $key === '') {
            return null;
        }

        $parts = explode('::', $key);
        $query = DB::table($definition['table']);

        foreach ($definition['key'] as $index => $column) {
            $part = $parts[$index] ?? null;
            $reference = $definition['references'][$column] ?? null;

            if ($reference !== null) {
                // A composite key whose part is itself a natural key. The
                // remaining parts belong to the parent, so they are rejoined.
                $parentDepth = count(ConfigurationSchema::sections()[$reference['section']]['key']);
                $parentKey = implode('::', array_slice($parts, $index, $parentDepth));
                $parentId = $this->resolve($reference['section'], $parentKey, $organizationId);

                if ($parentId === null) {
                    return $this->idCache[$section][$key] = null;
                }

                $query->where($column, $parentId);

                continue;
            }

            $query->where($column, $part);
        }

        if ($definition['tenantScoped']) {
            // A tenant's own row wins over the system row of the same code,
            // matching ObjectType::resolve().
            $query->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'))
                ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END');
        }

        $record = $query->first(['id']);

        return $this->idCache[$section][$key] = $record === null ? null : (int) $record->id;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $row
     */
    private function cacheId(string $section, array $definition, array $row, int $id, int $organizationId): void
    {
        $parts = [];

        foreach ($definition['key'] as $column) {
            $parts[] = isset($definition['references'][$column])
                ? (string) ($row['_refs'][$column] ?? '')
                : (string) ($row[$column] ?? '');
        }

        $this->idCache[$section][implode('::', $parts)] = $id;
    }

    /* ------------------------------------------------------------------ */
    /*  The log */
    /* ------------------------------------------------------------------ */

    /**
     * The common ancestor for conflict detection: the payload of the last
     * bundle successfully applied here.
     *
     * NOT the pre-apply snapshot. The snapshot is what the target looked like
     * BEFORE the last apply, which is a state the two sides never shared — a
     * three-way merge based on it reports every field the apply itself set as
     * a conflict, and reports genuine conflicts on a previously-empty target
     * as ordinary changes. The applied bundle is the last point at which the
     * source and the target agreed, which is what a merge base means.
     *
     * The snapshot keeps its own job: it is the rollback point.
     *
     * @return array<string, mixed>|null
     */
    private function lastAppliedSnapshot(int $organizationId): ?array
    {
        $application = ConfigBundleApplication::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('mode', 'apply')
            ->where('outcome', 'ok')
            ->whereNotNull('config_bundle_id')
            ->latest('applied_at')
            ->first();

        if ($application === null) {
            return null;
        }

        return ConfigBundle::withoutGlobalScopes()
            ->find($application->config_bundle_id)?->payload;
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function record(
        int $organizationId,
        ?int $bundleId,
        ?int $snapshotId,
        string $mode,
        string $outcome,
        array $diff,
        ?string $error = null,
    ): ConfigBundleApplication {
        return ConfigBundleApplication::create([
            'organization_id' => $organizationId,
            // A dry run against a payload that was never persisted as a bundle
            // still deserves a log entry; the FK is nullable for exactly that.
            'config_bundle_id' => $bundleId,
            'snapshot_bundle_id' => $snapshotId,
            'mode' => $mode,
            'outcome' => $outcome,
            'diff' => $diff,
            'added_count' => $diff['totals']['added'] ?? 0,
            'changed_count' => $diff['totals']['changed'] ?? 0,
            'removed_count' => $diff['totals']['removed'] ?? 0,
            'conflict_count' => $diff['totals']['conflicting'] ?? 0,
            'error' => $error,
            'applied_by' => auth()->id(),
            'applied_at' => now(),
        ]);
    }
}
