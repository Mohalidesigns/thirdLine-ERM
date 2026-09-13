<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCSA v2, P8 — where a migrated row came from.
 *
 * §13 asks for three things that all need the same fact: a reconciliation that
 * compares legacy against migrated, an exceptions report naming what could not
 * be mapped, and a rollback valid for thirty days. None of them can be written
 * without knowing which v2 row came from which legacy row.
 *
 * IT IS ALSO WHAT MAKES THE MIGRATION RE-RUNNABLE. A one-off command that
 * cannot be run twice is a command nobody dares run once: the first attempt
 * fails halfway on a tenant with unusual data, and the operator is left
 * deciding whether a partial migration is safe to repeat. With provenance the
 * answer is trivial — a row that already carries its legacy id is skipped, so
 * the second run completes what the first started and changes nothing else.
 *
 * NULLABLE, AND NULL IS MEANINGFUL. A risk typed into the Universe screen or
 * uploaded from the template has no legacy origin and never will. `null` means
 * "born in v2", which is exactly the set the rollback must NOT touch.
 *
 * NO FOREIGN KEY TO `risks`. Deliberate: §13 keeps the legacy tables for at
 * least one audit cycle and then, eventually, somebody archives them. A
 * constraint would either block that or cascade into the v2 register, and the
 * v2 register is the record of what the bank assessed — it must outlive its own
 * provenance. The id is kept as a plain integer for the same reason the
 * assessment line keeps snapshotted text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rcsa_register_risks', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_risk_id')->nullable()->after('source_batch_id');
            $table->index(['organization_id', 'legacy_risk_id']);
        });

        Schema::table('rcsa_register_controls', function (Blueprint $table) {
            // Distinct from `control_library_id`, which P0 added as a live link
            // to the control library and which stays meaningful after cutover.
            // This one records that the row was CREATED by the migration, which
            // `control_library_id` cannot say — a control mapped by hand in the
            // Universe screen also carries one.
            $table->unsignedBigInteger('legacy_control_id')->nullable()->after('control_library_id');
            $table->index('legacy_control_id');
        });

        Schema::table('rcsa_cycles', function (Blueprint $table) {
            // The campaign this cycle was reconstructed from. A `Legacy` cycle
            // per campaign period, per §13.
            $table->unsignedBigInteger('legacy_campaign_id')->nullable()->after('methodology_id');
            $table->index(['organization_id', 'legacy_campaign_id']);
        });

        Schema::table('rcsa_assessment_lines', function (Blueprint $table) {
            // The campaign_responses row that produced this line.
            $table->unsignedBigInteger('legacy_response_id')->nullable()->after('row_hash');
            $table->index('legacy_response_id');
        });
    }

    public function down(): void
    {
        Schema::table('rcsa_assessment_lines', function (Blueprint $table) {
            $table->dropIndex(['legacy_response_id']);
            $table->dropColumn('legacy_response_id');
        });

        Schema::table('rcsa_cycles', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'legacy_campaign_id']);
            $table->dropColumn('legacy_campaign_id');
        });

        Schema::table('rcsa_register_controls', function (Blueprint $table) {
            $table->dropIndex(['legacy_control_id']);
            $table->dropColumn('legacy_control_id');
        });

        Schema::table('rcsa_register_risks', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'legacy_risk_id']);
            $table->dropColumn('legacy_risk_id');
        });
    }
};
