<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-04 — an approval task can be raised by the platform rather than a person.
 *
 * Threshold re-baselining is detected by a scheduled job at period close. There
 * is no authenticated user in that context, and approval_requests.requested_by
 * was NOT NULL, so the job could not write the task it exists to write.
 *
 * requested_by becomes nullable rather than being given a synthetic "system"
 * user, for the same reason RiskAuditTrail.changed_by is nullable: a compliance
 * record naming the wrong person is worse than one that admits the change came
 * from a process. Screens read "raised by the platform" from a NULL.
 *
 * Additive: nothing stops writing requested_by, and every existing row keeps
 * its requester.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows raised by a job have no requester to restore, so tightening the
        // column again would fail on live data. The rollback is deliberately a
        // no-op: a nullable column is strictly more permissive than the one it
        // replaced, and nothing depends on the constraint.
    }
};
