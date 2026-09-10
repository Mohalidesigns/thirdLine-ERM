<?php

namespace App\Support\Graph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WP-03 TASK 4 — fold the two organisational models into one graph.
 *
 * The platform has carried two answers to "what is the organisation" since the
 * scoping module landed:
 *
 *   organizations -> business_units (self-tree) -> business_processes
 *   entity_types  -> entities (self-tree)
 *
 * Risks, controls, issues, loss events and KRIs point at BOTH. In the live
 * data, all nineteen business units are roots with no parent, and their names
 * ("Retail", "Operations") overlap the entity names ("Retail Banking Division",
 * "Operations & Technology") without matching them. There is no rule that can
 * tell you whether those are the same node or two different views of one bank
 * — only somebody who works there knows.
 *
 * So this class does NOT guess. It merges exactly one case:
 *
 *   an entity and a business unit whose names are identical once case,
 *   punctuation and whitespace are normalised, AND which are each the only
 *   row on their side with that normalised name.
 *
 * Everything else becomes a node of its own and, where it plausibly overlaps
 * something on the other side, a row in object_merge_candidates for a human to
 * accept or reject. Every merge, every attachment and every unmatched row is
 * logged.
 *
 * The similarity score is reported, never acted on. It exists to sort the
 * review queue, not to make the decision.
 */
class OrganisationGraphUnifier
{
    /** Below this, an overlap is not worth a reviewer's attention. */
    private const REVIEW_THRESHOLD = 0.60;

    /** @var array<string, int> type code => object_types.id */
    private array $typeIds = [];

    /** @var array<int, array<int, int>> organization => [entity id => object id] */
    private array $entityObjects = [];

    /** @var array<int, array<int, int>> organization => [business_unit id => object id] */
    private array $businessUnitObjects = [];

    /** @var array<int, array<int, int>> organization => [business_process id => object id] */
    private array $businessProcessObjects = [];

    /** @var array<string, int> counters for the summary */
    private array $counts = [
        'entities' => 0,
        'business_units' => 0,
        'business_processes' => 0,
        'merged' => 0,
        'pending_review' => 0,
        'attached_to_root' => 0,
        'untyped_entities' => 0,
        'cycles_broken' => 0,
        'nodes_resolved' => 0,
        'nodes_unresolved' => 0,
    ];

    /**
     * @return array<string, int> the summary counts
     */
    public function run(): array
    {
        $this->typeIds = DB::table('object_types')
            ->whereNull('organization_id')
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($this->typeIds === []) {
            throw new \RuntimeException(
                'The system object type registry is empty. '
                .'2026_08_11_120005_seed_system_object_type_registry must run before the graph can be unified.'
            );
        }

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $organizationId) {
            $organizationId = (int) $organizationId;

            $this->importEntities($organizationId);
            $this->importBusinessUnits($organizationId);
            $this->importBusinessProcesses($organizationId);
            $this->wireParents($organizationId);
        }

        $this->rebuildPaths();
        $this->resolveNodeIds();

        Log::info('Organisation graph unified', $this->counts);

        return $this->counts;
    }

    /* ------------------------------------------------------------------ */
    /*  Import */
    /* ------------------------------------------------------------------ */

    private function importEntities(int $organizationId): void
    {
        $entityTypes = DB::table('entity_types')
            ->where('organization_id', $organizationId)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $legacyMap = ObjectTypeRegistry::legacyEntityTypeMap();
        $typeNames = $this->typeNamesByNormalisedName();

        $entities = DB::table('entities')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($entities as $entity) {
            $legacyType = $entityTypes->get($entity->entity_type_id);
            $typeCode = null;

            if ($legacyType !== null) {
                // 1. the declared mapping for the nine seeded entity types
                $typeCode = $legacyMap[$legacyType->code] ?? null;

                // 2. a tenant-added entity type whose NAME is one of ours
                $typeCode ??= $typeNames[$this->normalise($legacyType->name)] ?? null;
            }

            if ($typeCode === null) {
                // 3. no mapping: import as a generic business unit so nothing
                //    goes dark, and put it in front of a human.
                $typeCode = 'BusinessUnit';
                $this->counts['untyped_entities']++;

                $this->recordCandidate($organizationId, [
                    'left_source_type' => 'entity_type',
                    'left_source_id' => (int) $entity->entity_type_id,
                    'left_name' => $legacyType->name ?? 'unknown entity type',
                    'right_source_type' => 'object_type',
                    'right_source_id' => null,
                    'right_name' => 'BusinessUnit (fallback)',
                    'similarity' => 0,
                    'match_basis' => 'unmapped_entity_type',
                    'decision' => 'pending',
                    'notes' => "Entity #{$entity->id} ({$entity->name}) was imported as a generic BusinessUnit "
                        .'because its entity type could not be mapped to a system object type. Retype it.',
                ]);

                Log::warning('Unmapped entity type imported as BusinessUnit', [
                    'organization_id' => $organizationId,
                    'entity_id' => $entity->id,
                    'entity_type' => $legacyType->code ?? null,
                ]);
            }

            $objectId = $this->createObject($organizationId, $typeCode, [
                'code' => $entity->entity_code,
                'name' => $entity->name,
                'description' => $entity->description,
                'owner_id' => $entity->owner_id,
                'delegate_owner_id' => $entity->delegate_owner_id,
                'lifecycle_state' => $entity->status,
                'status' => $entity->status,
                'source_model_type' => 'entity',
                'source_model_id' => (int) $entity->id,
                'created_by' => $entity->created_by,
            ]);

            $this->entityObjects[$organizationId][(int) $entity->id] = $objectId;
            $this->counts['entities']++;
        }
    }

    private function importBusinessUnits(int $organizationId): void
    {
        $units = DB::table('business_units')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $entityIndex = $this->normalisedNameIndex(
            DB::table('entities')
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->get(['id', 'name'])
        );

        $unitIndex = $this->normalisedNameIndex($units);

        foreach ($units as $unit) {
            $key = $this->normalise($unit->name);

            // The one automatic merge: an exact normalised name that is unique
            // on both sides. Anything ambiguous falls through to review.
            $isUnambiguous = count($entityIndex[$key] ?? []) === 1 && count($unitIndex[$key] ?? []) === 1;

            // A unit that already has a node of its own is not a candidate for
            // merging: something has already given it an identity, and folding
            // it into the entity now would orphan every edge pointing at it.
            $alreadyIdentified = DB::table('objects')
                ->where('source_model_type', 'business_unit')
                ->where('source_model_id', $unit->id)
                ->exists();

            if ($isUnambiguous && ! $alreadyIdentified) {
                $entityId = $entityIndex[$key][0];
                $objectId = $this->entityObjects[$organizationId][$entityId] ?? null;

                if ($objectId !== null) {
                    $this->businessUnitObjects[$organizationId][(int) $unit->id] = $objectId;
                    $this->counts['merged']++;

                    $this->recordCandidate($organizationId, [
                        'left_source_type' => 'entity',
                        'left_source_id' => $entityId,
                        'left_name' => $this->entityName($entityId),
                        'right_source_type' => 'business_unit',
                        'right_source_id' => (int) $unit->id,
                        'right_name' => $unit->name,
                        'similarity' => 1,
                        'match_basis' => 'exact_normalised_name',
                        'decision' => 'auto_merged',
                        'resolved_object_id' => $objectId,
                        'notes' => 'Names are identical once case, punctuation and whitespace are normalised, '
                            .'and neither side had a second row with that name.',
                    ]);

                    Log::info('Merged business unit into entity node', [
                        'organization_id' => $organizationId,
                        'business_unit_id' => $unit->id,
                        'entity_id' => $entityId,
                        'object_id' => $objectId,
                    ]);

                    continue;
                }
            }

            $objectId = $this->createObject($organizationId, 'BusinessUnit', [
                'code' => $unit->code,
                'name' => $unit->name,
                'description' => $unit->description,
                'owner_id' => $unit->head_id,
                'lifecycle_state' => $unit->is_active ? 'active' : 'inactive',
                'status' => $unit->is_active ? 'active' : 'inactive',
                'source_model_type' => 'business_unit',
                'source_model_id' => (int) $unit->id,
            ]);

            $this->businessUnitObjects[$organizationId][(int) $unit->id] = $objectId;
            $this->counts['business_units']++;

            $this->proposeCandidates($organizationId, $unit, $objectId);
        }
    }

    /**
     * Everything on the entity side that this unit might be, ranked. Reported
     * only — nothing here changes a single row of graph data.
     */
    private function proposeCandidates(int $organizationId, object $unit, int $objectId): void
    {
        $entities = DB::table('entities')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        foreach ($entities as $entity) {
            $similarity = $this->similarity($unit->name, $entity->name);

            if ($similarity < self::REVIEW_THRESHOLD) {
                continue;
            }

            $this->recordCandidate($organizationId, [
                'left_source_type' => 'entity',
                'left_source_id' => (int) $entity->id,
                'left_name' => $entity->name,
                'right_source_type' => 'business_unit',
                'right_source_id' => (int) $unit->id,
                'right_name' => $unit->name,
                'similarity' => $similarity,
                'match_basis' => 'name_similarity',
                'decision' => 'pending',
                'resolved_object_id' => $objectId,
                'notes' => 'Imported as a separate node. Merge or reject this pairing before the legacy '
                    .'entity_id and business_unit_id columns are dropped.',
            ]);

            $this->counts['pending_review']++;
        }
    }

    private function importBusinessProcesses(int $organizationId): void
    {
        $processes = DB::table('business_processes')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($processes as $process) {
            $objectId = $this->createObject($organizationId, 'Process', [
                'code' => $process->code,
                'name' => $process->name,
                'description' => $process->description,
                'owner_id' => $process->owner_id,
                'lifecycle_state' => $process->is_active ? 'active' : 'inactive',
                'status' => $process->is_active ? 'active' : 'inactive',
                'source_model_type' => 'business_process',
                'source_model_id' => (int) $process->id,
            ]);

            $this->businessProcessObjects[$organizationId][(int) $process->id] = $objectId;
            $this->counts['business_processes']++;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Structure */
    /* ------------------------------------------------------------------ */

    private function wireParents(int $organizationId): void
    {
        // Entities keep their own tree.
        foreach (DB::table('entities')->where('organization_id', $organizationId)->whereNull('deleted_at')->get(['id', 'parent_id']) as $entity) {
            $this->setParent(
                $this->entityObjects[$organizationId][(int) $entity->id] ?? null,
                $entity->parent_id === null ? null : ($this->entityObjects[$organizationId][(int) $entity->parent_id] ?? null)
            );
        }

        // Business units keep theirs. A merged unit's object already has a
        // parent from the entity side; only overwrite it when the unit
        // genuinely declares one, so a merge cannot silently re-root a subtree.
        foreach (DB::table('business_units')->where('organization_id', $organizationId)->whereNull('deleted_at')->get(['id', 'parent_id']) as $unit) {
            if ($unit->parent_id === null) {
                continue;
            }

            $this->setParent(
                $this->businessUnitObjects[$organizationId][(int) $unit->id] ?? null,
                $this->businessUnitObjects[$organizationId][(int) $unit->parent_id] ?? null
            );
        }

        // A process belongs to its business unit.
        foreach (DB::table('business_processes')->where('organization_id', $organizationId)->whereNull('deleted_at')->get(['id', 'business_unit_id']) as $process) {
            $this->setParent(
                $this->businessProcessObjects[$organizationId][(int) $process->id] ?? null,
                $process->business_unit_id === null ? null : ($this->businessUnitObjects[$organizationId][(int) $process->business_unit_id] ?? null)
            );
        }

        $this->attachOrphansToRoot($organizationId);
    }

    /**
     * The live data has nineteen parentless business units and one entity root.
     * A forest of twenty roots is not a unified organisation, so parentless
     * units are attached to the single root when there IS exactly one — and
     * each attachment is written to the review table, because "Retail reports
     * to the holding company" is an assertion about the bank, not a fact the
     * database knew.
     *
     * With no root, or more than one, nothing is attached: there would be no
     * defensible choice, and a wrong parent silently changes who can see what.
     */
    private function attachOrphansToRoot(int $organizationId): void
    {
        $entityRoots = DB::table('objects')
            ->where('organization_id', $organizationId)
            ->where('source_model_type', 'entity')
            ->whereNull('parent_id')
            ->pluck('id');

        if ($entityRoots->count() !== 1) {
            Log::info('Parentless nodes left as roots: no single organisation root to attach them to', [
                'organization_id' => $organizationId,
                'entity_roots' => $entityRoots->count(),
            ]);

            return;
        }

        $rootId = (int) $entityRoots->first();

        $orphans = DB::table('objects')
            ->where('organization_id', $organizationId)
            ->whereIn('source_model_type', ['business_unit'])
            ->whereNull('parent_id')
            ->where('id', '!=', $rootId)
            ->get(['id', 'name', 'source_model_id']);

        foreach ($orphans as $orphan) {
            DB::table('objects')->where('id', $orphan->id)->update(['parent_id' => $rootId]);
            $this->counts['attached_to_root']++;

            $this->recordCandidate($organizationId, [
                'left_source_type' => 'object',
                'left_source_id' => $rootId,
                'left_name' => (string) DB::table('objects')->where('id', $rootId)->value('name'),
                'right_source_type' => 'business_unit',
                'right_source_id' => (int) $orphan->source_model_id,
                'right_name' => $orphan->name,
                'similarity' => 0,
                'match_basis' => 'attached_to_root',
                'decision' => 'auto_attached',
                'resolved_object_id' => (int) $orphan->id,
                'notes' => 'Had no parent of its own and was attached to the only organisation root so the '
                    .'graph is a single tree. Re-parent it if it belongs further down.',
            ]);
        }

        if ($orphans->isNotEmpty()) {
            Log::info('Attached parentless business units to organisation root', [
                'organization_id' => $organizationId,
                'root_object_id' => $rootId,
                'count' => $orphans->count(),
            ]);
        }
    }

    private function setParent(?int $objectId, ?int $parentId): void
    {
        if ($objectId === null || $parentId === null || $objectId === $parentId) {
            return;
        }

        DB::table('objects')->where('id', $objectId)->update(['parent_id' => $parentId]);
    }

    /* ------------------------------------------------------------------ */
    /*  Paths */
    /* ------------------------------------------------------------------ */

    /**
     * Materialise hierarchy_path and hierarchy_depth for every object.
     *
     * Breadth-first from the roots so a parent's path is always written before
     * its children need it. A node still unresolved after the tree is exhausted
     * is in a cycle — merging two trees makes that possible in a way neither
     * tree alone was — so its parent link is broken, it becomes a root, and the
     * break is logged. Leaving a cycle in place would hang every ancestor walk
     * in the platform.
     */
    public function rebuildPaths(): void
    {
        $rows = DB::table('objects')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'parent_id'])
            ->mapWithKeys(fn ($row) => [(int) $row->id => $row->parent_id === null ? null : (int) $row->parent_id])
            ->all();

        $paths = [];
        $depths = [];
        $pending = $rows;
        $guard = 0;

        while ($pending !== [] && $guard++ < 1000) {
            $progressed = false;

            foreach ($pending as $id => $parentId) {
                if ($parentId === null || ! array_key_exists($parentId, $rows)) {
                    $paths[$id] = "/{$id}/";
                    $depths[$id] = 0;
                } elseif (isset($paths[$parentId])) {
                    $paths[$id] = $paths[$parentId].$id.'/';
                    $depths[$id] = $depths[$parentId] + 1;
                } else {
                    continue;
                }

                unset($pending[$id]);
                $progressed = true;
            }

            if (! $progressed) {
                foreach ($pending as $id => $parentId) {
                    DB::table('objects')->where('id', $id)->update(['parent_id' => null]);
                    $paths[$id] = "/{$id}/";
                    $depths[$id] = 0;
                    $this->counts['cycles_broken']++;

                    Log::error('Cycle detected in the object graph; parent link broken', [
                        'object_id' => $id,
                        'parent_id' => $parentId,
                    ]);
                }

                break;
            }
        }

        foreach ($paths as $id => $path) {
            DB::table('objects')->where('id', $id)->update([
                'hierarchy_path' => $path,
                'hierarchy_depth' => $depths[$id],
            ]);
        }

        // An org node owns itself, so "everything owned by this subtree" stays
        // one whereIn against node_id instead of a union with the node list.
        DB::statement('UPDATE objects SET node_id = id WHERE node_id IS NULL AND object_type_id IN ('
            .'SELECT id FROM object_types WHERE is_node_type = 1)');
    }

    /* ------------------------------------------------------------------ */
    /*  node_id on the domain tables */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve every domain row to exactly one node.
     *
     * Order matters: entity_id wins over business_unit_id, because entities are
     * the richer model and the one the scoping UI writes. Rows that resolve
     * through a parent (a treatment plan through its risk) are done in a second
     * pass once the first has run.
     */
    private function resolveNodeIds(): void
    {
        $entityToObject = DB::table('objects')
            ->where('source_model_type', 'entity')
            ->pluck('id', 'source_model_id');

        $unitToObject = DB::table('objects')
            ->where('source_model_type', 'business_unit')
            ->pluck('id', 'source_model_id');

        // A merged unit has no object of its own; point it at the entity's.
        foreach ($this->businessUnitObjects as $perOrg) {
            foreach ($perOrg as $unitId => $objectId) {
                $unitToObject[$unitId] = $objectId;
            }
        }

        $direct = [
            'risks' => ['entity_id', 'business_unit_id'],
            'controls' => ['entity_id', 'business_unit_id'],
            'issues' => ['entity_id', 'business_unit_id'],
            'loss_events' => ['entity_id', 'business_unit_id'],
            'key_risk_indicators' => ['entity_id', null],
            'near_misses' => [null, 'business_unit_id'],
            'campaign_assignments' => [null, 'business_unit_id'],
        ];

        foreach ($direct as $table => [$entityColumn, $unitColumn]) {
            if (! $this->tableIsReady($table)) {
                continue;
            }

            if ($entityColumn !== null) {
                foreach ($entityToObject as $sourceId => $objectId) {
                    $this->counts['nodes_resolved'] += DB::table($table)
                        ->where($entityColumn, $sourceId)
                        ->whereNull('node_id')
                        ->update(['node_id' => $objectId]);
                }
            }

            if ($unitColumn !== null) {
                foreach ($unitToObject as $sourceId => $objectId) {
                    $this->counts['nodes_resolved'] += DB::table($table)
                        ->where($unitColumn, $sourceId)
                        ->whereNull('node_id')
                        ->update(['node_id' => $objectId]);
                }
            }
        }

        // Second pass: rows whose node comes from their parent record.
        $derived = [
            'treatment_plans' => ['risks', 'risk_id'],
            'control_tests' => ['controls', 'control_id'],
            'quantification_scenarios' => ['risks', 'risk_register_id'],
            // KRIs have no business_unit_id and, in practice, no entity_id
            // either. The indicator belongs where the risk it monitors belongs.
            'key_risk_indicators' => ['risks', 'risk_id'],
        ];

        foreach ($derived as $table => [$parentTable, $key]) {
            if (! $this->tableIsReady($table)) {
                continue;
            }

            $parents = DB::table($parentTable)->whereNotNull('node_id')->pluck('node_id', 'id');

            foreach ($parents as $parentId => $nodeId) {
                $this->counts['nodes_resolved'] += DB::table($table)
                    ->where($key, $parentId)
                    ->whereNull('node_id')
                    ->update(['node_id' => $nodeId]);
            }
        }

        foreach (array_merge(array_keys($direct), array_keys($derived)) as $table) {
            if (! $this->tableIsReady($table)) {
                continue;
            }

            $unresolved = DB::table($table)->whereNull('node_id')->count();
            $this->counts['nodes_unresolved'] += $unresolved;

            if ($unresolved > 0) {
                Log::warning('Rows left without an org-graph node', [
                    'table' => $table,
                    'count' => $unresolved,
                ]);
            }
        }
    }

    private function tableIsReady(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table)
            && \Illuminate\Support\Facades\Schema::hasColumn($table, 'node_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $values
     */
    private function createObject(int $organizationId, string $typeCode, array $values): int
    {
        $typeId = $this->typeIds[$typeCode] ?? $this->typeIds['BusinessUnit'];
        $now = now();

        $existing = DB::table('objects')
            ->where('source_model_type', $values['source_model_type'])
            ->where('source_model_id', $values['source_model_id'])
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('objects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'object_type_id' => $typeId,
            'code' => $this->allocateCode($organizationId, $typeId, $values),
            'name' => Str::limit((string) ($values['name'] ?? 'Unnamed'), 495, ''),
            'description' => $values['description'] ?? null,
            'owner_id' => $values['owner_id'] ?? null,
            'delegate_owner_id' => $values['delegate_owner_id'] ?? null,
            'lifecycle_state' => $values['lifecycle_state'] ?? null,
            'status' => $values['status'] ?? null,
            'source_model_type' => $values['source_model_type'],
            'source_model_id' => $values['source_model_id'],
            'created_by' => $values['created_by'] ?? null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * objects is unique on (organization_id, object_type_id, code). Two source
     * tables can legitimately hold the same code for the same type, so a
     * collision suffixes rather than failing the migration — and the suffix is
     * derived from the source row, so it is stable across re-runs.
     *
     * @param  array<string, mixed>  $values
     */
    private function allocateCode(int $organizationId, int $typeId, array $values): string
    {
        $code = trim((string) ($values['code'] ?? ''));

        if ($code === '') {
            $code = strtoupper(substr($values['source_model_type'], 0, 3)).'-'.$values['source_model_id'];
        }

        $taken = DB::table('objects')
            ->where('organization_id', $organizationId)
            ->where('object_type_id', $typeId)
            ->where('code', $code)
            ->exists();

        if (! $taken) {
            return Str::limit($code, 64, '');
        }

        $suffixed = Str::limit($code, 50, '').'-'.$values['source_model_id'];

        Log::warning('Object code collision resolved by suffixing', [
            'organization_id' => $organizationId,
            'code' => $code,
            'resolved' => $suffixed,
            'source' => $values['source_model_type'].'#'.$values['source_model_id'],
        ]);

        return $suffixed;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function recordCandidate(int $organizationId, array $row): void
    {
        DB::table('object_merge_candidates')->insert(array_merge([
            'organization_id' => $organizationId,
            'resolved_object_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $row));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, list<int>>
     */
    private function normalisedNameIndex($rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            $index[$this->normalise($row->name)][] = (int) $row->id;
        }

        return $index;
    }

    private function entityName(int $entityId): string
    {
        return (string) DB::table('entities')->where('id', $entityId)->value('name');
    }

    /**
     * Lowercase, strip everything that is not a letter or digit, collapse
     * spaces. Deliberately no stemming and no stop-word removal: dropping
     * "Division" would make "Retail" and "Retail Banking Division" equal, and
     * that equality is exactly the judgement this class refuses to make.
     */
    private function normalise(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', '', strtolower((string) $value))));
    }

    private function similarity(string $left, string $right): float
    {
        $a = $this->normalise($left);
        $b = $this->normalise($right);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }

    /**
     * @return array<string, string> normalised type name => type code
     */
    private function typeNamesByNormalisedName(): array
    {
        $names = [];

        foreach (ObjectTypeRegistry::types() as $type) {
            $names[$this->normalise($type['name'])] = $type['code'];
        }

        return $names;
    }
}
