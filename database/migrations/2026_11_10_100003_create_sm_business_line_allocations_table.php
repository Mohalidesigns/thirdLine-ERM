<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 1 — business-line allocation, frozen by
 * ADR 0027 Amendment 1 §A1.5.
 *
 * `business_units`, NOT `business_processes`. §A1.3's evidence applies, plus
 * three facts: the workbook's allocation rows are BUSINESS LINES (Property and
 * Casualty / Life and Savings / Health / Investment / Pensions — insurer lines,
 * so the FK must reach the tenant's own tree and never a fixed list);
 * `business_processes` is a PROCESS CATALOGUE consumed by RCSA and mirrored
 * again by BCMS's `bcms_processes`, so pointing allocations at it puts two org
 * trees on one screen; and `risks` already models the pair as
 * `business_unit_id` + `process_id` — the unit is the owning level, the process
 * the refinement. A nullable `business_process_id` is deferred, not refused, if
 * a tenant ever allocates below the unit.
 *
 * `cascadeOnDelete`, NOT `nullOnDelete`: a NOT NULL column cannot be nulled, and
 * "40% to nobody" is worse than a missing row — the missing row is caught by the
 * sum-to-100 invariant, the meaningless one is not.
 *
 * SUM-TO-100 IS A SERVICE INVARIANT, NOT A DB CHECK. A grep for `CHECK (` /
 * `->check(` across database/migrations returns nothing: there is no CHECK
 * precedent on either engine, and one here would fail a save with
 * SQLSTATE[23000] where the Form Request should have answered 422. The
 * allocation service compares Σ percent to 100.00 as DECIMALS at the column's
 * precision (string/bccomp, never float `==`) and answers 422 on key
 * `allocations`, enforced at the `in_design → designed` transition and not on
 * every draft save — a draft legitimately sums to 60% mid-entry, and refusing to
 * save it pushes users into one big form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_business_line_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();

            // decimal(5,2) matches risks.control_effectiveness_pct.
            $table->decimal('percent', 5, 2);

            $table->timestamps();

            $table->unique(['sm_scenario_id', 'business_unit_id'], 'sm_allocations_scenario_unit_unique');
            // "Which scenarios touch this unit".
            $table->index(['organization_id', 'business_unit_id'], 'sm_allocations_org_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_business_line_allocations');
    }
};
