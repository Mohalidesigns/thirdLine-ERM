<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * risk_control_mapping.organization_id and loss_event_rca.organization_id were
 * added nullable by 200038_align_schema_with_controllers and nothing ever
 * populated them. Now that BelongsToOrganization filters reads by tenant, any
 * row still holding NULL would become invisible to every organization.
 *
 * Backfill both from their parent record. Additive and forward-only — no
 * column is dropped and no existing value is overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('risk_control_mapping', 'organization_id')) {
            DB::table('risk_control_mapping')
                ->whereNull('organization_id')
                ->update([
                    'organization_id' => DB::raw(
                        '(select organization_id from risks where risks.id = risk_control_mapping.risk_id)'
                    ),
                ]);
        }

        if (Schema::hasTable('loss_event_rca') && Schema::hasColumn('loss_event_rca', 'organization_id')) {
            DB::table('loss_event_rca')
                ->whereNull('organization_id')
                ->update([
                    'organization_id' => DB::raw(
                        '(select organization_id from loss_events where loss_events.id = loss_event_rca.loss_event_id)'
                    ),
                ]);
        }
    }

    public function down(): void
    {
        // Forward-only: the backfilled values are the correct values. Reverting
        // them to NULL would re-create the orphaned rows this migration fixed.
    }
};
