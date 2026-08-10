<?php

use App\Models\RiskAuditTrail;
use App\Support\Audit\AuditTrailImmutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * risk_audit_trail.action_type was varchar(20), which is narrower than the
 * action names the application actually writes.
 *
 * Two overflow it: `kri_breach_escalation` (21), written by
 * EscalateRiskOnKriBreach, and `threshold_rebaselined` (21), added by WP-04.
 * The first has never been hit because the nightly breach check threw on its
 * first query and so never dispatched the event — the moment WP-04 made
 * breaches actually fire, escalating one became a 1406 and rolled back the
 * escalation.
 *
 * WHY THIS WAS NOT CAUGHT BY THE SUITE. SQLite does not enforce VARCHAR
 * length; it stores the declared type and ignores it. Every one of these
 * inserts passes in tests and fails on MySQL. The companion test,
 * AuditActionTypeWidthTest, closes that gap by asserting against the DECLARED
 * width rather than relying on the driver to complain.
 *
 * 64 rather than 21: the column holds a short verb phrase, the cost of the
 * headroom is nil, and the next feature should not need a migration to name
 * its own action.
 *
 * Widening is additive and touches no stored value, so the append-only hash
 * chain over this table is unaffected — the digests are computed from row
 * contents, not from column metadata, and the BEFORE UPDATE / BEFORE DELETE
 * triggers do not fire for a DDL change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_audit_trail', function (Blueprint $table) {
            $table->string('action_type', RiskAuditTrail::ACTION_TYPE_MAX)->change();
        });

        // SQLite implements a column change by rebuilding the table, and the
        // append-only triggers belong to the table that gets dropped — so the
        // widening above silently removes the tamper-evidence on that driver
        // while leaving it intact on MySQL. Reinstalling unconditionally keeps
        // every driver in the same state, and costs nothing where the triggers
        // survived.
        AuditTrailImmutability::reinstall();
    }

    public function down(): void
    {
        // Narrowing again would truncate any action already recorded at more
        // than 20 characters, which on an append-only, hash-chained table means
        // silently breaking the chain. Deliberately not reversed.
    }
};
