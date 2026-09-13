<?php

use App\Support\Graph\OrganisationGraphUnifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-03 TASK 4 — one organisational graph, and a review queue for what could
 * not be decided automatically.
 *
 * object_merge_candidates IS the mapping table the work package asks for. It is
 * not a temporary artefact: it stays in the schema because the legacy entity_id
 * and business_unit_id columns stay for a release, and the decision recorded
 * here is what says whether they may be dropped.
 *
 * See App\Support\Graph\OrganisationGraphUnifier for the merge rule. The short
 * version: identical normalised names, unique on both sides, merge; everything
 * else is imported as its own node and queued for a human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_merge_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Deliberately not a polymorphic relation: the rows being compared
            // live in tables that may be dropped once the merge is settled, and
            // a morph would tie this audit record to models that outlive it.
            $table->string('left_source_type', 32);
            $table->unsignedBigInteger('left_source_id')->nullable();
            $table->string('left_name', 500)->nullable();
            $table->string('right_source_type', 32);
            $table->unsignedBigInteger('right_source_id')->nullable();
            $table->string('right_name', 500)->nullable();

            // Reported for ranking the queue. Never acted on — see the class docs.
            $table->decimal('similarity', 5, 4)->default(0);
            $table->string('match_basis', 40);
            $table->enum('decision', ['auto_merged', 'auto_attached', 'pending', 'rejected', 'applied'])
                ->default('pending');
            $table->unsignedBigInteger('resolved_object_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'decision']);
            $table->index(['left_source_type', 'left_source_id']);
            $table->index(['right_source_type', 'right_source_id']);
        });

        (new OrganisationGraphUnifier)->run();
    }

    public function down(): void
    {
        // Unwind in dependency order: node_id points at objects, and the
        // domain tables must let go before the rows they reference can.
        foreach (['risks', 'controls', 'issues', 'loss_events', 'key_risk_indicators',
            'near_misses', 'treatment_plans', 'control_tests', 'quantification_scenarios',
            'campaign_assignments'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'node_id')) {
                DB::table($table)->update(['node_id' => null]);
            }
        }

        DB::table('objects')->update(['parent_id' => null, 'node_id' => null]);
        DB::table('objects')->whereIn('source_model_type', ['entity', 'business_unit', 'business_process'])->delete();

        Schema::dropIfExists('object_merge_candidates');
    }
};
