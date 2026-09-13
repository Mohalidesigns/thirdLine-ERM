<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 10 — programme maturity (FR-RPT-06).
 *
 * TWO TABLES BECAUSE A MATURITY ASSESSMENT IS A PERIOD AND A SET OF SCORES,
 * and the requirement asks for a trend across periods. A JSON column on one
 * row would make "how did Contract Management move over four quarters" a
 * query nobody can write.
 *
 * SCORES ARE NULLABLE AND NULL IS NOT ZERO. Level 0 in this model means
 * "non-existent" — a real finding about a programme somebody assessed. A
 * category nobody has scored yet is null, and the screen and the trend both
 * distinguish them, because reporting an unassessed category as non-existent
 * makes a programme look worse than it is and reporting it as anything else
 * makes it look better.
 *
 * `framework_version` IS STAMPED ON THE ASSESSMENT. The VRMMM criteria this
 * product ships are ITS OWN — Shared Assessments' content is licensed and must
 * not be redistributed (TRD Appendix E item 7) — and NIST CSF subcategory
 * lettering has moved between framework releases. A score of 3 means nothing
 * without knowing which rubric produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_maturity_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('period_label', 40);
            $table->date('as_at');
            $table->string('status', 20)->default('draft');

            // Which rubric produced these scores.
            $table->string('framework_version', 40);

            $table->text('summary')->nullable();

            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assessed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'period_label'], 'tp_maturity_period_unique');
            $table->index(['organization_id', 'as_at']);
        });

        Schema::create('tp_maturity_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('tp_maturity_assessments')->cascadeOnDelete();

            // 'vrmmm' or 'nist_csf'. Two lenses over one programme, kept apart
            // because their scales mean different things: VRMMM levels are
            // programme maturity, CSF subcategories are outcomes.
            $table->string('framework', 20);
            $table->string('category_code', 40);
            $table->string('category_name', 255);

            // Null means unscored. Zero means assessed as non-existent.
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->unsignedTinyInteger('target_level')->nullable();

            $table->text('evidence')->nullable();
            $table->text('gap_actions')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Named explicitly: MySQL's identifier limit is 64 characters and
            // the generated name for this one runs past it.
            $table->unique(['assessment_id', 'framework', 'category_code'], 'tp_maturity_score_unique');
            $table->index(['organization_id', 'framework', 'category_code'], 'tp_maturity_trend_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_maturity_scores');
        Schema::dropIfExists('tp_maturity_assessments');
    }
};
