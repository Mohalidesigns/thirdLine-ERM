<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 1 — the cause checklist, frozen by ADR
 * 0027 Amendment 1 §A1.4.
 *
 * SPARSE: one row per ticked cause, not 23 pre-materialised rows. The checklist
 * is a const on `Sm\ScenarioCause` (§A1.8), so the absence of a row means "not
 * selected". Full materialisation was refused along with the sync-on-save
 * machinery it needs — unlike `kri_return_lines`, nothing here is derived from
 * a due-set that changes under the user. Unticking deletes the row and
 * `AuditTrailService` records it.
 *
 * NO SOFT DELETES: a child line table, per ADR 0026 §2.
 *
 * NO `label` COLUMN, deliberately. The const map keyed by `code` is the
 * authority; a stored copy means a Phase 5 rename updates some rows and not
 * others, so the Excel round-trip exports two spellings of one cause.
 *
 * `side` and `cause_group` are DERIVED FROM `code` by the service at insert and
 * are never accepted from a request — a client posting them is how a row arrives
 * `side = external` with `code = people_other`.
 *
 * Codes are group-prefixed so `code` is globally unique and the two-column
 * unique key below works: the workbook has an "Other" in each of People,
 * Processes and Systems, and three rows sharing `code = 'other'` would make one
 * unique key impossible.
 *
 * NOT `RiskCause`. That model belongs to a RISK ("a cause outlives the quarter
 * that found it … causes accumulate on the risk") and carries `risk_id`,
 * `cause_category_id`, `source`, `is_primary`. Reuse was considered and refused:
 * it would put a `risk_id` on a row with no risk, or make it nullable, and mix
 * two vocabularies in the table `RiskCauseCategory::options()` renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_scenario_causes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            // 'internal' | 'external'
            $table->string('side', 10);
            // 'people' | 'process' | 'system' | 'external'
            $table->string('cause_group', 20);
            // One of the 23 frozen codes in ScenarioCause::CHECKLIST. A code not
            // in that map is a 422 — the map IS the checklist.
            $table->string('code', 50);

            // Defaults true because rows are sparse, and exists so "considered
            // and rejected, see note" is expressible — which ISO 31010's
            // document-your-assumptions rule (D4) needs.
            $table->boolean('is_selected')->default(true);
            $table->text('note')->nullable();

            // Freezes report and export row order so a tenant extending the
            // checklist (Phase 5) cannot reshuffle a signed-off scenario's
            // qualitative report. On-screen grouping order comes from the const.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['sm_scenario_id', 'code'], 'sm_causes_scenario_code_unique');
            $table->index(['organization_id', 'sm_scenario_id'], 'sm_causes_org_scenario_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_scenario_causes');
    }
};
