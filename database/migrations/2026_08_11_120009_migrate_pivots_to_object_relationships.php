<?php

use App\Support\Graph\PivotRelationshipMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-03 TASK 5 — the pivot tables become typed edges.
 *
 * Nothing is dropped. risk_control_mapping, risk_related_risks,
 * loss_event_controls, risk_kri_mapping and regulatory_risk_mapping all stay
 * exactly as they are and stay readable, per the additive-migrations rule —
 * this release stops treating them as the only answer, the next one removes
 * them once every reader is on object_relationships.
 *
 * See docs/schema/deprecations.md for the release that drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PivotRelationshipMigrator)->run();
    }

    public function down(): void
    {
        $codes = ['mitigates', 'derives_from', 'failed_control', 'monitored_by', 'converted_to', 'treats', 'maps_to'];

        $typeIds = DB::table('object_relationship_types')
            ->whereNull('organization_id')
            ->whereIn('code', $codes)
            ->pluck('id');

        DB::table('object_relationships')->whereIn('relationship_type_id', $typeIds)->delete();

        // The Requirement objects this migration materialised from the free
        // text in regulatory_risk_mapping have no source row, which is what
        // distinguishes them from every other object.
        DB::table('objects')
            ->whereNull('source_model_type')
            ->whereIn('object_type_id', DB::table('object_types')->whereNull('organization_id')->where('code', 'Requirement')->pluck('id'))
            ->delete();
    }
};
