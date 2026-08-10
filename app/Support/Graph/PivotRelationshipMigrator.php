<?php

namespace App\Support\Graph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WP-03 TASK 5 — fold the pivot tables into typed edges.
 *
 * The pivots are NOT dropped. They keep being readable for one release, per the
 * additive-migrations rule: this release stops treating them as the only answer
 * and starts writing edges instead; the release after removes them. Anything
 * that reads a pivot today keeps working while it is moved over.
 *
 * regulatory_risk_mapping is the one that needs more than a copy. It stores a
 * regulation NAME and a requirement REFERENCE as free text, not a foreign key,
 * so there is nothing on the far end of the edge to point at. Distinct
 * (regulation, reference) pairs are therefore materialised as Requirement
 * objects — which is what they always were, written as strings because there
 * was no table to put them in.
 */
class PivotRelationshipMigrator
{
    /** @var array<string, int> "alias:id" => object id */
    private array $objectIds = [];

    /** @var array<string, int> relationship code => id */
    private array $typeIds = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @return array<string, int> edges written, per source table
     */
    public function run(): array
    {
        $this->typeIds = DB::table('object_relationship_types')
            ->whereNull('organization_id')
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->migrateRiskControlMapping();
        $this->migrateRiskRelatedRisks();
        $this->migrateLossEventControls();
        $this->migrateRiskKriMapping();
        $this->migrateNearMissConversions();
        $this->migrateTreatmentPlans();
        $this->migrateRegulatoryRiskMapping();

        Log::info('Pivot tables migrated to object_relationships', $this->counts);

        return $this->counts;
    }

    /* ------------------------------------------------------------------ */
    /*  Per-pivot passes */
    /* ------------------------------------------------------------------ */

    /**
     * A control mitigates a risk. Direction matters: the control is the actor,
     * so it is the FROM side, and 'mitigated_by' reads it the other way.
     */
    private function migrateRiskControlMapping(): void
    {
        foreach (DB::table('risk_control_mapping')->orderBy('id')->cursor() as $row) {
            $this->writeEdge('mitigates', 'control', $row->control_id, 'risk', $row->risk_id, [
                'weight' => (float) ($row->control_weight ?? 1),
                'attributes' => array_filter([
                    'is_key_control' => (bool) $row->is_key_control,
                    'mapping_rationale' => $row->mapping_rationale,
                ], fn ($value) => $value !== null && $value !== ''),
                'created_by' => $row->created_by ?? null,
                'created_at' => $row->created_at ?? null,
            ], 'risk_control_mapping');
        }
    }

    private function migrateRiskRelatedRisks(): void
    {
        foreach (DB::table('risk_related_risks')->orderBy('id')->cursor() as $row) {
            $this->writeEdge('derives_from', 'risk', $row->risk_id, 'risk', $row->related_risk_id, [
                'attributes' => array_filter([
                    'relationship_type' => $row->relationship_type,
                    'correlation_strength' => $row->correlation_strength,
                ], fn ($value) => $value !== null && $value !== ''),
                'created_at' => $row->created_at ?? null,
            ], 'risk_related_risks');
        }
    }

    private function migrateLossEventControls(): void
    {
        foreach (DB::table('loss_event_controls')->orderBy('id')->cursor() as $row) {
            $this->writeEdge('failed_control', 'loss_event', $row->loss_event_id, 'control', $row->control_id, [
                'attributes' => array_filter([
                    'failure_type' => $row->failure_type,
                    'failure_description' => $row->failure_description,
                ], fn ($value) => $value !== null && $value !== ''),
                'created_at' => $row->created_at ?? null,
            ], 'loss_event_controls');
        }
    }

    private function migrateRiskKriMapping(): void
    {
        foreach (DB::table('risk_kri_mapping')->orderBy('id')->cursor() as $row) {
            $this->writeEdge('monitored_by', 'risk', $row->risk_id, 'key_risk_indicator', $row->kri_id, [
                'attributes' => array_filter([
                    'correlation_type' => $row->correlation_type,
                ], fn ($value) => $value !== null && $value !== ''),
                'created_at' => $row->created_at ?? null,
            ], 'risk_kri_mapping');
        }
    }

    /**
     * near_misses.converted_loss_event_id is a column, not a pivot, but it is
     * the same fact: this near miss became that loss event.
     */
    private function migrateNearMissConversions(): void
    {
        $rows = DB::table('near_misses')
            ->whereNotNull('converted_loss_event_id')
            ->get(['id', 'converted_loss_event_id', 'created_at']);

        foreach ($rows as $row) {
            $this->writeEdge('converted_to', 'near_miss', $row->id, 'loss_event', $row->converted_loss_event_id, [
                'created_at' => $row->created_at ?? null,
            ], 'near_misses.converted_loss_event_id');
        }
    }

    /**
     * Beyond the six the work package names.
     *
     * treatment_plans.risk_id is the same shape of fact as the pivots — a
     * foreign key that is really an edge — and 'treats' was seeded with the
     * other relationship types. Migrating it here means the graph can answer
     * "what is being done about this risk" without a special case, which is the
     * whole point of having one edge table.
     */
    private function migrateTreatmentPlans(): void
    {
        $rows = DB::table('treatment_plans')
            ->whereNotNull('risk_id')
            ->whereNull('deleted_at')
            ->get(['id', 'risk_id', 'created_at']);

        foreach ($rows as $row) {
            $this->writeEdge('treats', 'treatment_plan', $row->id, 'risk', $row->risk_id, [
                'created_at' => $row->created_at ?? null,
            ], 'treatment_plans.risk_id');
        }
    }

    /**
     * The mapping whose far end has to be created before it can be pointed at.
     */
    private function migrateRegulatoryRiskMapping(): void
    {
        $requirementType = DB::table('object_types')
            ->whereNull('organization_id')
            ->where('code', 'Requirement')
            ->value('id');

        if ($requirementType === null) {
            Log::error('Requirement object type missing; regulatory mappings not migrated');

            return;
        }

        foreach (DB::table('regulatory_risk_mapping')->orderBy('id')->cursor() as $row) {
            $riskObjectId = $this->objectId('risk', $row->risk_id);

            if ($riskObjectId === null) {
                $this->miss('regulatory_risk_mapping', 'risk', $row->risk_id);

                continue;
            }

            $organizationId = (int) DB::table('objects')->where('id', $riskObjectId)->value('organization_id');
            $requirementId = $this->requirementObject($organizationId, (int) $requirementType, $row);

            $this->writeEdgeByObjectIds('maps_to', $riskObjectId, $requirementId, $organizationId, [
                'attributes' => array_filter([
                    'regulation_name' => $row->regulation_name,
                    'requirement_ref' => $row->requirement_ref,
                    'mapping_notes' => $row->mapping_notes,
                ], fn ($value) => $value !== null && $value !== ''),
                'created_by' => $row->mapped_by ?? null,
                'created_at' => $row->created_at ?? null,
            ], 'regulatory_risk_mapping');
        }
    }

    /**
     * Find or create the Requirement object for a (regulation, reference) pair.
     *
     * The code is derived from the pair itself, so the same requirement
     * referenced by twenty risks produces one object and twenty edges — which
     * is the point: "what else does CBN BSD/DIR/GEN/LAB/07/052 touch" becomes a
     * question the platform can answer.
     */
    private function requirementObject(int $organizationId, int $typeId, object $row): int
    {
        $reference = trim((string) ($row->requirement_ref ?? ''));
        $regulation = trim((string) $row->regulation_name);
        $code = Str::limit(Str::upper(Str::slug($regulation.' '.$reference, '-')), 64, '');

        if ($code === '') {
            $code = 'REQ-UNSPECIFIED';
        }

        $existing = DB::table('objects')
            ->where('organization_id', $organizationId)
            ->where('object_type_id', $typeId)
            ->where('code', $code)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $name = $reference === '' ? $regulation : $regulation.' — '.$reference;

        return (int) DB::table('objects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'object_type_id' => $typeId,
            'code' => $code,
            'name' => Str::limit($name, 495, ''),
            'description' => null,
            'lifecycle_state' => 'identified',
            'status' => 'identified',
            // Materialised from free text rather than mirrored from a table:
            // there is no source row to point back at.
            'source_model_type' => null,
            'source_model_id' => null,
            'hierarchy_depth' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Edge writing */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $options
     */
    private function writeEdge(
        string $relationshipCode,
        string $fromAlias,
        int|string|null $fromId,
        string $toAlias,
        int|string|null $toId,
        array $options,
        string $sourceTable,
    ): void {
        $from = $fromId === null ? null : $this->objectId($fromAlias, (int) $fromId);
        $to = $toId === null ? null : $this->objectId($toAlias, (int) $toId);

        if ($from === null) {
            $this->miss($sourceTable, $fromAlias, $fromId);

            return;
        }

        if ($to === null) {
            $this->miss($sourceTable, $toAlias, $toId);

            return;
        }

        $organizationId = (int) DB::table('objects')->where('id', $from)->value('organization_id');

        $this->writeEdgeByObjectIds($relationshipCode, $from, $to, $organizationId, $options, $sourceTable);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function writeEdgeByObjectIds(
        string $relationshipCode,
        int $fromObjectId,
        int $toObjectId,
        int $organizationId,
        array $options,
        string $sourceTable,
    ): void {
        $typeId = $this->typeIds[$relationshipCode] ?? null;

        if ($typeId === null) {
            Log::error('Relationship type missing during pivot migration', ['code' => $relationshipCode]);

            return;
        }

        // A self-edge is always a data error, and the graph traversal would
        // loop on it forever.
        if ($fromObjectId === $toObjectId) {
            $this->count($sourceTable.'.self_edge_skipped');

            return;
        }

        $exists = DB::table('object_relationships')
            ->where('relationship_type_id', $typeId)
            ->where('from_object_id', $fromObjectId)
            ->where('to_object_id', $toObjectId)
            ->exists();

        if ($exists) {
            return;
        }

        $timestamp = $options['created_at'] ?? now();
        $attributes = $options['attributes'] ?? [];

        DB::table('object_relationships')->insert([
            'organization_id' => $organizationId,
            'relationship_type_id' => $typeId,
            'from_object_id' => $fromObjectId,
            'to_object_id' => $toObjectId,
            'weight' => $options['weight'] ?? 1,
            'attributes' => $attributes === [] ? null : json_encode($attributes),
            'created_by' => $options['created_by'] ?? null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->count($sourceTable);
    }

    private function objectId(string $alias, int $sourceId): ?int
    {
        $key = $alias.':'.$sourceId;

        if (! array_key_exists($key, $this->objectIds)) {
            $id = DB::table('objects')
                ->where('source_model_type', $alias)
                ->where('source_model_id', $sourceId)
                ->value('id');

            $this->objectIds[$key] = $id === null ? null : (int) $id;
        }

        return $this->objectIds[$key];
    }

    /**
     * A pivot row whose endpoint has no graph node. Counted and logged rather
     * than skipped silently — the count is what tells you the backfill did not
     * cover something.
     */
    private function miss(string $sourceTable, string $alias, int|string|null $id): void
    {
        $this->count($sourceTable.'.unresolved');

        Log::warning('Pivot row skipped: endpoint has no graph node', [
            'source' => $sourceTable,
            'alias' => $alias,
            'source_id' => $id,
        ]);
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }
}
