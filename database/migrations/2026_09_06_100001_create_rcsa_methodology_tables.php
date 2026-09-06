<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCSA v2, P0 — the methodology aggregate.
 *
 * The rewritten RCSA module is built to the client's `SB_RCSA Template 2026`
 * workbook, and these four tables are that workbook's scoring engine made into
 * data. App\Support\Rcsa\RcsaMethodologyTemplate holds the canonical values and
 * explains, at length, why this is not a `scoring_profiles` row.
 *
 * WHY IT IS VERSIONED. A methodology is not configuration, it is the thing an
 * assessment was scored against. When the bank re-cuts its bands in 2027, the
 * closed 2026 assessments must still render — and still reconcile — under the
 * scales they were assessed with. Every cycle therefore pins a methodology id,
 * and an active methodology becomes `retired` rather than being edited once any
 * cycle references it. `is_locked` is the hard version of that: set when the
 * first assessment line is written, and checked before any scale, band or
 * criterion under it is touched.
 *
 * ORGANIZATION_ID IS NULLABLE and means "system methodology, available to every
 * tenant" — the convention `scoring_profiles`, `object_types`,
 * `widget_definitions` and `risk_cause_categories` already use. A tenant that
 * has configured nothing scores against the seeded SB methodology, which is the
 * right default here in a way it would not be for most tables: the workbook IS
 * the client's approved methodology, so the out-of-the-box behaviour is the
 * signed-off behaviour.
 *
 * Nothing here is soft-deleted except the methodology itself. A scale item or a
 * band is meaningless apart from its methodology, and a soft-deleted band would
 * be a hole in a score range that the calculation service would have to reason
 * about; retiring the whole methodology is the supported way to withdraw one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rcsa_methodologies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // NULL = system methodology, inherited by every tenant.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 64);
            $table->string('name', 160);
            $table->string('version', 20)->default('1.0');
            $table->text('description')->nullable();

            $table->enum('status', ['draft', 'active', 'retired'])->default('draft');

            // A methodology change re-rates everything scored under it, so it
            // is dated the way scoring_profiles.effective_from is dated: next
            // year's matrix can be staged without disturbing this year's.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // calculated: residual is always derived from inherent × CE.
            // assessed:   the assessor supplies a residual likelihood/impact.
            // hybrid:     derived, overridable with a justification.
            // Which of these the bank wants is §14 Q1 of the plan and is still
            // open; the column exists so that answering it is configuration
            // rather than a migration.
            $table->enum('residual_mode', ['calculated', 'assessed', 'hybrid'])->default('calculated');

            // Defect D2. The workbook drives residual to 0.00 on Fully
            // Achieved, so a 25-score risk bands VERY LOW on one control
            // rating. Seeded at 0 for template parity; raise it only on a
            // methodology decision. Decimal, not integer, because the residual
            // score it clamps is decimal(5,2).
            $table->decimal('residual_floor', 5, 2)->default(0);

            // The highest residual band still inside appetite. Every band above
            // it is "above risk appetite" and requires an action plan. Stored
            // rather than hardcoded because §14 Q4 asks whether appetite is a
            // single ceiling or a statement per risk category; a per-category
            // table would hang off this column, not replace it.
            $table->string('appetite_ceiling_level', 32)->default('low');

            $table->boolean('is_system')->default(false);

            // Set when the first assessment line is scored under this
            // methodology. A locked methodology's scales and bands are immutable.
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code', 'version']);
            $table->index(['organization_id', 'status']);
            $table->index('effective_from');
        });

        Schema::create('rcsa_scale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('methodology_id')->constrained('rcsa_methodologies')->cascadeOnDelete();

            $table->enum('type', ['likelihood', 'impact', 'control_effectiveness']);

            $table->string('label', 80);

            // 1-5 for likelihood and impact; 1-4 for control effectiveness,
            // where the ORDER is inverted (1 = Fully Achieved, the best) and
            // the number that matters is `modifier`, not `value`. Never
            // multiply by a control-effectiveness `value`.
            $table->unsignedTinyInteger('value');

            // The percentage of inherent risk this control rating is assessed
            // to remove: 100 / 75 / 50 / 25. NULL on likelihood and impact.
            $table->unsignedTinyInteger('modifier')->nullable();

            // Display guidance: "67% to <90%", "NGN 15m - NGN 30m", "76% - 100%".
            // A string, because the workbook's own likelihood bands are not
            // contiguous and a pair of numbers would not reproduce them.
            $table->string('percent_band', 60)->nullable();

            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['methodology_id', 'type', 'value']);
            $table->index(['methodology_id', 'type']);
        });

        Schema::create('rcsa_impact_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('methodology_id')->constrained('rcsa_methodologies')->cascadeOnDelete();

            // The impact level this descriptor qualifies, 1-5.
            $table->unsignedTinyInteger('impact_value');

            // financial | brand_reputation | operational | legal_regulatory |
            // customer_satisfaction | health_safety. A string rather than an
            // enum because a tenant may add a dimension (environmental, say)
            // without a migration, exactly as scoring_profiles.impact_dimensions
            // allows.
            $table->string('dimension', 64);

            $table->text('descriptor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['methodology_id', 'impact_value', 'dimension']);
            $table->index(['methodology_id', 'impact_value']);
        });

        Schema::create('rcsa_risk_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('methodology_id')->constrained('rcsa_methodologies')->cascadeOnDelete();

            $table->string('level', 32);
            $table->string('label', 60);

            // INCLUSIVE bounds on the 0-25 score. Decimal because a residual
            // score is fractional — 12.5 is a real value — and defect D3 is
            // explicit that banding happens on the raw value, never on a
            // rounded one. The bands are contiguous to two decimal places:
            // VERY LOW ends at 2.00 and LOW starts at 2.01.
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);

            $table->string('colour', 7)->nullable();

            // Column S. accept | mitigate | treat.
            $table->string('treatment', 32);

            // Column T, verbatim. A sentence, not a flag: "Above risk appetite:
            // Treat". The action-plan requirement is derived from its prefix.
            $table->string('appetite_status', 160);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['methodology_id', 'level']);
            $table->index(['methodology_id', 'min_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcsa_risk_bands');
        Schema::dropIfExists('rcsa_impact_criteria');
        Schema::dropIfExists('rcsa_scale_items');
        Schema::dropIfExists('rcsa_methodologies');
    }
};
