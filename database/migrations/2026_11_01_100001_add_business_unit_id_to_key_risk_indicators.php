<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0026 section 1 - a KRI belongs to a business unit.
 *
 * NOTHING RESOLVES A KRI TO A DEPARTMENT TODAY. `entity_id` feeds GraphScope and
 * never touches business_units; `node_id` was filled once by the graph unifier
 * and may name an entity or a merged node. So the link is a column.
 *
 * THE BACKFILL RUNS IN PHP, in chunks of 500 by id, and each UPDATE targets one
 * KRI by id. No UPDATE reads key_risk_indicators in a subquery (MySQL 8 error
 * 1093). The first rule that yields exactly one live unit of the KRI's own
 * organisation wins; disagreeing links are never guessed between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('key_risk_indicators', function (Blueprint $table) {
            $table->foreignId('business_unit_id')->nullable()->after('node_id')
                ->constrained('business_units')->nullOnDelete();
            $table->index(['organization_id', 'business_unit_id'], 'kri_org_business_unit_idx');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('key_risk_indicators', function (Blueprint $table) {
            $table->dropForeign(['business_unit_id']);
            $table->dropIndex('kri_org_business_unit_idx');
            $table->dropColumn('business_unit_id');
        });
    }

    private function backfill(): void
    {
        $counts = ['node' => 0, 'risk' => 0, 'mapping' => 0, 'unassigned' => 0];

        DB::table('key_risk_indicators')
            ->orderBy('id')
            ->chunkById(500, function ($kris) use (&$counts) {
                $units = $this->liveUnits($kris->pluck('organization_id')->unique()->filter()->all());

                $nodeUnit = $this->nodeUnits($kris->pluck('node_id')->filter()->unique()->all());
                $riskUnit = $this->riskUnits($kris->pluck('risk_id')->filter()->unique()->all());
                $mappedUnits = $this->mappedUnits($kris->pluck('id')->all());

                foreach ($kris as $kri) {
                    $orgId = (int) $kri->organization_id;
                    $valid = fn ($unitId) => $unitId !== null && ($units[(int) $unitId] ?? null) === $orgId;

                    $unit = null;
                    $rule = 'unassigned';

                    $candidate = $kri->node_id !== null ? ($nodeUnit[(int) $kri->node_id] ?? null) : null;

                    if ($valid($candidate)) {
                        $unit = (int) $candidate;
                        $rule = 'node';
                    } else {
                        $candidate = $kri->risk_id !== null ? ($riskUnit[(int) $kri->risk_id] ?? null) : null;

                        if ($valid($candidate)) {
                            $unit = (int) $candidate;
                            $rule = 'risk';
                        } else {
                            $set = $mappedUnits[(int) $kri->id] ?? [];

                            if (count($set) === 1 && $valid($set[0])) {
                                $unit = (int) $set[0];
                                $rule = 'mapping';
                            }
                        }
                    }

                    $counts[$rule]++;

                    if ($unit !== null) {
                        DB::table('key_risk_indicators')->where('id', $kri->id)->update(['business_unit_id' => $unit]);
                    }
                }
            });

        logger()->info('KRI business_unit_id backfill', $counts);
    }

    /**
     * @param  list<int>  $organizationIds
     * @return array<int, int> unit id => organisation id, live units only
     */
    private function liveUnits(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return DB::table('business_units')
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->pluck('organization_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Rule 1: the KRI's node is a business_unit object. A merged unit's object
     * is an entity object, so it does not match and falls through.
     *
     * @param  list<int>  $nodeIds
     * @return array<int, int> object id => business unit id
     */
    private function nodeUnits(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        return DB::table('objects')
            ->whereIn('id', $nodeIds)
            ->where('source_model_type', 'business_unit')
            ->whereNotNull('source_model_id')
            ->pluck('source_model_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $riskIds
     * @return array<int, int|null> risk id => business unit id
     */
    private function riskUnits(array $riskIds): array
    {
        if ($riskIds === []) {
            return [];
        }

        return DB::table('risks')->whereIn('id', $riskIds)->pluck('business_unit_id', 'id')->all();
    }

    /**
     * Rule 3: every mapped risk points at the same non-null unit. Any mapped
     * risk with no unit, or a second unit, voids the rule for that KRI.
     *
     * @param  list<int>  $kriIds
     * @return array<int, list<int>> kri id => the distinct units, only where unanimous
     */
    private function mappedUnits(array $kriIds): array
    {
        if ($kriIds === [] || ! Schema::hasTable('risk_kri_mapping')) {
            return [];
        }

        $rows = DB::table('risk_kri_mapping')
            ->join('risks', 'risks.id', '=', 'risk_kri_mapping.risk_id')
            ->whereIn('risk_kri_mapping.kri_id', $kriIds)
            ->select('risk_kri_mapping.kri_id as kri_id', 'risks.business_unit_id as business_unit_id')
            ->get();

        $byKri = [];

        foreach ($rows as $row) {
            $byKri[(int) $row->kri_id][] = $row->business_unit_id === null ? null : (int) $row->business_unit_id;
        }

        $result = [];

        foreach ($byKri as $kriId => $units) {
            $distinct = array_values(array_unique($units, SORT_REGULAR));

            if (count($distinct) === 1 && $distinct[0] !== null) {
                $result[$kriId] = [$distinct[0]];
            }
        }

        return $result;
    }
};
