<?php

use App\Models\Organization;
use App\Services\Workflow\WorkflowPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-06 TASK 4 — install the shipped processes for every existing organization.
 *
 * Per organization rather than as global system rows, because
 * workflow_definitions.organization_id is NOT NULL and, more to the point,
 * because a workflow is the first thing a customer edits. A shared system
 * definition would mean one bank's change to its loss-event escalation matrix
 * landing in another's.
 *
 * Idempotent: an organization that already has a definition with the code keeps
 * whatever it has. Re-running this migration on a customer who has redrawn
 * their approval chain must not put ours back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $publisher = app(WorkflowPublisher::class);

        Organization::query()->withoutGlobalScopes()->orderBy('id')->each(
            function (Organization $organization) use ($publisher) {
                $installed = $publisher->provision($organization->id);

                if ($installed !== []) {
                    logger()->info('Installed the shipped workflow definitions.', [
                        'organization_id' => $organization->id,
                        'codes' => $installed,
                    ]);
                }
            }
        );
    }

    public function down(): void
    {
        // Only the untouched system rows are removed. A definition somebody has
        // published a version of is theirs now, and a rollback that deleted it
        // would delete the process their open instances are pinned to.
        DB::table('workflow_definitions')
            ->where('is_system', true)
            ->where('version', 1)
            ->whereNotIn('id', DB::table('workflow_instances')->select('definition_id'))
            ->delete();
    }
};
