<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Q10 — "retention period for completed assessments and export logs?"
 *
 * Nothing in this module has ever expired or been purged. §13's "retained for
 * at least one audit cycle" is honoured by never deleting anything, which
 * satisfies the floor and says nothing about the ceiling: generated workbooks
 * accumulate on disk for ever, and P6's export expiry is checked ON READ rather
 * than swept, so an "expired" export is a row that refuses to download beside a
 * file that is still sitting there.
 *
 * WHAT THIS DOES NOT DO IS AS IMPORTANT AS WHAT IT DOES.
 *
 * There is no purge here for assessments, lines, cycles or export LOG ROWS, and
 * that is deliberate rather than unfinished:
 *
 *   - `risk_audit_trail` is hash-chained and append-only by model AND by
 *     database trigger. Deleting a link does not shorten the chain, it BREAKS
 *     it, and `audit:verify` would then report tampering that never happened.
 *     A retention policy cannot apply to a structure whose whole value is that
 *     nothing can be removed from it. The service asserts it never touches it.
 *   - An export LOG ROW is a control, not telemetry — §10.2 requires the bank
 *     to know who took the operational risk profile out of the building.
 *     Deleting the log destroys the evidence, so retention purges the FILE and
 *     keeps the row, which is the distinction that makes this safe.
 *   - A closed cycle is the bank's own regulatory record. The command REPORTS
 *     cycles beyond the configured period so somebody can decide; it does not
 *     delete them. Shipping an automatic purge of a completed RCSA on nobody's
 *     instruction would be the single most destructive default in this module.
 *
 * As with Q4 and Q5, the policy is per tenant and its default is today's
 * behaviour: every period is null, which means nothing is ever purged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rcsa_cycles', function (Blueprint $table) {
            // A cycle under legal hold is excluded from every sweep, whatever
            // the policy says. Retention periods are a housekeeping rule;
            // a hold is a legal instruction, and the instruction wins.
            $table->timestamp('legal_hold_at')->nullable()->after('status');
            $table->foreignId('legal_hold_by')->nullable()->after('legal_hold_at')
                ->constrained('users')->nullOnDelete();
            $table->text('legal_hold_reason')->nullable()->after('legal_hold_by');
        });

        Schema::table('rcsa_export_jobs', function (Blueprint $table) {
            // The row survives its file. Set when retention removes the
            // artefact, so the log can say "this was taken, and the copy we
            // generated has since been cleaned up" rather than looking like a
            // row whose file failed to write.
            $table->timestamp('file_purged_at')->nullable()->after('file_path');
        });

        Schema::table('rcsa_import_batches', function (Blueprint $table) {
            $table->timestamp('file_purged_at')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('rcsa_import_batches', function (Blueprint $table) {
            $table->dropColumn('file_purged_at');
        });

        Schema::table('rcsa_export_jobs', function (Blueprint $table) {
            $table->dropColumn('file_purged_at');
        });

        Schema::table('rcsa_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_hold_by');
            $table->dropColumn(['legal_hold_at', 'legal_hold_reason']);
        });
    }
};
