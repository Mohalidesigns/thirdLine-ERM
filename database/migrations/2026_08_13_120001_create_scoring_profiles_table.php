<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-05 TASK 3 — retire the hardcoded 5×5.
 *
 * The matrix was spelled out in three places that could disagree, and did:
 * RiskScoringService::calculateRating(), AnalysisController::heatmap() and the
 * $cellColors literal in heatmap.blade.php. Meanwhile
 * organizations.settings->risk_settings — which the admin settings screen has
 * been writing since WP-00 — was read by nothing at all. A user could set
 * "impact_scale: 4", save, see a success flash, and every screen in the
 * product would carry on rendering five columns.
 *
 * A scoring profile is the single definition of what a score MEANS: how many
 * points on each axis, what each point is called, what a financial impact of
 * "4" is in naira, how the dimensions collapse, which bands the product of the
 * two axes falls into, and how residual is derived from inherent. Everything
 * that renders or computes a score resolves a profile first.
 *
 * WHY PROFILES ARE PLURAL PER TENANT. A Nigerian bank does not score credit
 * risk on the same axis as conduct risk, and a group does not score a
 * subsidiary on the same naira bands as the holding company — ₦50m is a
 * rounding error at group level and an existential event at a microfinance
 * subsidiary. applies_to binds a profile to nodes, object types or risk types;
 * the most specific match wins. A tenant that wants one matrix for everything
 * simply never creates a second profile.
 *
 * organization_id is NULLABLE and means "system profile, available to every
 * tenant", the same convention object_types uses. The seeded 5×5 lives there,
 * so a newly created organization scores correctly before anyone has
 * configured anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoring_profiles', function (Blueprint $table) {
            $table->id();
            // NULL = system profile. See the class docblock.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->text('description')->nullable();

            // {"node_ids":[..], "object_type_ids":[..], "risk_types":["credit"]}
            // An absent or empty key means "does not narrow on this axis".
            // NULL overall means the profile is general to the organization.
            $table->json('applies_to')->nullable();

            // [{value,label,definition,probability_min,probability_max}]
            // probability_* are fractions of 1 (0.05 = 5%), nullable for
            // organisations that score likelihood qualitatively.
            $table->json('likelihood_scale');

            // [{value,label,definition,financial_min,financial_max,currency}]
            // financial_* are MINOR UNITS (kobo) and always carry `currency`,
            // per the platform money rule. A band of ₦50m is stored as
            // 5000000000 with currency NGN, never as 50000000 with the unit
            // left to the reader.
            $table->json('impact_scale');

            // The dimensions this profile scores, in display order. Defaults to
            // the five the platform shipped with, but a profile may drop
            // 'strategic' or add 'environmental' without a migration.
            $table->json('impact_dimensions');

            $table->enum('impact_aggregation', ['max', 'weighted', 'average', 'worst_two'])->default('max');
            $table->json('dimension_weights')->nullable();

            // [{code,label,color,min,max}] — min/max inclusive, in score points.
            $table->json('rating_bands');

            // Expression evaluated by FormulaEvaluator with `inherent` and
            // `effectiveness` in scope. NULL keeps the platform default,
            // inherent × (1 − effectiveness ÷ 100).
            $table->text('residual_formula')->nullable();

            // The heat map's shape. Rows are likelihood, columns are impact.
            // They are stored rather than derived from count(likelihood_scale)
            // so a malformed scale cannot silently resize every dashboard.
            $table->unsignedTinyInteger('matrix_rows')->default(5);
            $table->unsignedTinyInteger('matrix_cols')->default(5);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);

            // A scoring change is a governance event: it re-rates the whole
            // register. effective_from lets a tenant stage next year's matrix
            // without disturbing this year's ratings.
            $table->date('effective_from')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_default']);
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_profiles');
    }
};
