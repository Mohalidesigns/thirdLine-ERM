<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-04 TASK 2 — the measure model.
 *
 * One fact table, `measure_values`, keyed on
 *
 *     (measure x object x period x scenario) -> value
 *
 * replaces four different ways of storing a number that the platform grew
 * independently: kri_measurements, the denormalised score columns on `risks`,
 * the assessment snapshot on `risk_assessments`, and whatever the reporting
 * services recomputed on the fly. Every one of those answers "what is it now";
 * none of them answers "what was it then", and none of them can hold a target
 * or a stress case alongside the actual.
 *
 * `object_id` points at `objects` (WP-03), not at a polymorphic pair. A measure
 * is attached to a node in the graph — a risk, a control, a business unit, the
 * organisation itself — and the graph already knows how those nest, so a
 * group-level roll-up is a subtree query rather than a union.
 *
 * -----------------------------------------------------------------------
 * currency_key
 * -----------------------------------------------------------------------
 * The natural key for a value includes its currency: the same measure, object
 * and period legitimately holds one row per currency for a monetary measure.
 * currency_code is NULLABLE because a count or a percentage has no currency —
 * but in MySQL a NULL never equals another NULL, so a UNIQUE index containing
 * a nullable column does not constrain the rows that matter most: the
 * non-monetary ones, which are the majority.
 *
 * currency_key is the same fact with the hole closed. It is derived from
 * currency_code by the model on every write, and holds 'XXX' — ISO 4217's own
 * code for "no currency involved" — where currency_code is NULL. The unique
 * index uses currency_key; every reader uses currency_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('symbol', 16)->nullable();
            $table->enum('category', ['currency', 'count', 'pct', 'time', 'mass', 'energy', 'ratio', 'custom']);

            // Self-referential: 'kobo' has base_unit_id -> 'NGN' with a
            // conversion_factor of 0.01. NULL base_unit_id means this IS the
            // base unit of its family.
            $table->foreignId('base_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('conversion_factor', 28, 12)->default(1);
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('measures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 300);
            $table->text('description')->nullable();

            // Which kind of object this measure may be recorded against.
            // NULL means "any" — used by organisation-level financials that
            // hang off whatever node the reporting entity is.
            $table->foreignId('object_type_id')->nullable()->constrained('object_types')->nullOnDelete();

            $table->enum('measure_kind', [
                'kri', 'kpi', 'risk_score', 'control_eff', 'esg', 'financial', 'capital', 'quality',
            ]);
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->enum('aggregation', ['sum', 'avg', 'last', 'max', 'min', 'weighted_avg', 'count'])->default('last');
            $table->enum('polarity', ['higher_better', 'lower_better', 'target_band'])->default('lower_better');
            $table->unsignedTinyInteger('decimal_places')->default(2);

            // The definition of the number in the organisation's own words for
            // a manual measure; a FormulaEvaluator expression when is_derived.
            $table->text('formula')->nullable();
            $table->boolean('is_derived')->default(false);
            $table->enum('source', ['manual', 'import', 'api', 'calculated'])->default('manual');
            $table->string('frequency', 20)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'measure_kind', 'is_active']);
        });

        Schema::create('measure_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('measure_id')->constrained('measures')->cascadeOnDelete();
            $table->foreignId('object_id')->constrained('objects')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();

            $table->enum('scenario', ['actual', 'target', 'budget', 'forecast', 'stress', 'plan', 'baseline'])
                ->default('actual');

            // 28,6 rather than the 18,4 used elsewhere: a naira balance-sheet
            // figure in kobo already needs 15 digits before a decimal place is
            // spent, and an FX-converted value needs the fractional room.
            $table->decimal('value', 28, 6);
            $table->char('currency_code', 3)->nullable();
            $table->char('currency_key', 3)->default('XXX');
            $table->decimal('fx_rate_used', 18, 8)->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'locked'])->default('approved');
            $table->string('rag_band', 32)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->string('source', 32)->default('manual');
            $table->string('evidence_ref', 500)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['measure_id', 'object_id', 'period_id', 'scenario', 'currency_key'],
                'measure_values_natural_key_unique'
            );
            $table->index(['organization_id', 'period_id', 'measure_id']);
            $table->index(['object_id', 'measure_id', 'period_id']);
        });

        Schema::create('measure_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('measure_id')->constrained('measures')->cascadeOnDelete();

            // NULL = the default band set for this measure. A row with an
            // object_id overrides it for that one object, which is how a group
            // limit gets tightened for a single subsidiary.
            $table->foreignId('object_id')->nullable()->constrained('objects')->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // [{code,label,color,min,max,min_formula,max_formula}, ...]
            $table->json('bands');

            $table->enum('direction', ['higher_worse', 'lower_worse', 'band'])->default('higher_worse');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // The row this one replaces. Bands are never updated in place: a
            // re-baselined limit writes a NEW row and closes the old one's
            // effective_to, so a breach recorded last year can still be shown
            // against the limit that was actually in force at the time.
            $table->foreignId('supersedes_id')->nullable()->constrained('measure_thresholds')->nullOnDelete();
            $table->timestamps();

            $table->index(['measure_id', 'object_id', 'effective_from']);
            $table->index(['organization_id', 'effective_from']);
        });

        Schema::create('measure_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('measure_id')->constrained('measures')->cascadeOnDelete();
            $table->foreignId('object_id')->constrained('objects')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();

            // dateTime, NOT timestamp. MySQL/MariaDB give the first
            // non-nullable TIMESTAMP column in a table an implicit
            // `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` whenever
            // explicit_defaults_for_timestamp is OFF — so every later UPDATE to
            // a breach row would silently reset the moment the breach began,
            // and mean-time-to-resolve would quietly measure nothing. DATETIME
            // carries no automatic initialisation or update behaviour at all.
            // SQLite has no such rule, so no test can catch this.
            $table->dateTime('breached_at');
            $table->string('band_from', 32)->nullable();
            $table->string('band_to', 32);
            $table->decimal('value', 28, 6);
            $table->decimal('threshold_value', 28, 6)->nullable();
            $table->string('severity', 20)->default('medium');
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'false_positive'])->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('linked_risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->foreignId('linked_issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->text('root_cause')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // One open breach per measure/object/period/band. The nightly check
            // re-runs against the same reading and must not stack duplicates.
            $table->unique(
                ['measure_id', 'object_id', 'period_id', 'band_to'],
                'measure_breaches_natural_key_unique'
            );
            $table->index(['organization_id', 'status', 'breached_at']);
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();

            // NULL = a platform-wide rate (what the CBN published that day).
            // A tenant may record its own internal or contracted rate.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();

            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->date('rate_date');
            $table->enum('rate_type', ['cbn_official', 'nafem', 'parallel', 'internal', 'custom'])
                ->default('cbn_official');
            $table->decimal('rate', 18, 8);
            $table->string('source', 200)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            // Same NULL-in-a-unique-index problem as measure_values: a platform
            // rate has organization_id NULL, so the column is shadowed by a
            // non-null key. 0 is not a valid organizations.id.
            $table->unsignedBigInteger('organization_key')->default(0);

            $table->unique(
                ['organization_key', 'from_currency', 'to_currency', 'rate_date', 'rate_type'],
                'fx_rates_natural_key_unique'
            );
            $table->index(['from_currency', 'to_currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('measure_breaches');

        Schema::table('measure_thresholds', function (Blueprint $table) {
            $table->dropForeign(['supersedes_id']);
        });
        Schema::dropIfExists('measure_thresholds');

        Schema::dropIfExists('measure_values');
        Schema::dropIfExists('measures');

        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['base_unit_id']);
        });
        Schema::dropIfExists('units');
    }
};
