<?php

use App\Support\Graph\ObjectBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-03 TASK 3 — give the records that predate the trait their graph identity.
 *
 * HasObjectIdentity mirrors a model on save. Every row already in the database
 * was saved before the trait existed, so without this pass the acceptance
 * criterion "every model listed in TASK 3 has a corresponding objects row"
 * would only hold for records created from today.
 *
 * It also has to run before the pivot migration: an edge needs objects at both
 * ends, and risk_control_mapping is full of risks and controls that have none
 * until this has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ObjectBackfiller)->run(source: 'migration');
    }

    public function down(): void
    {
        // Only the mirrors of governance records. The org-node objects belong
        // to 120007 and are that migration's to remove.
        $governanceAliases = [
            'risk_category', 'risk', 'control', 'key_risk_indicator', 'issue',
            'loss_event', 'near_miss', 'risk_appetite', 'assessment_campaign',
            'treatment_plan', 'control_test', 'quantification_scenario',
        ];

        $objectIds = DB::table('objects')->whereIn('source_model_type', $governanceAliases)->pluck('id');

        DB::table('object_relationships')
            ->whereIn('from_object_id', $objectIds)
            ->orWhereIn('to_object_id', $objectIds)
            ->delete();

        DB::table('object_versions')->whereIn('object_id', $objectIds)->delete();
        DB::table('objects')->whereIn('id', $objectIds)->update(['parent_id' => null]);
        DB::table('objects')->whereIn('id', $objectIds)->delete();
    }
};
