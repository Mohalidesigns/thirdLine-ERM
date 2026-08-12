<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * WP-10a — the assessment chain.
 *
 * A risk expert's review set the canonical journey an assessment must follow:
 *
 *   Risk → Root Cause → Likelihood → Impact → Inherent Risk →
 *   Existing Controls → Control Effectiveness → Residual Risk →
 *   Risk Treatment → Action Plan → Owner → Due Date → KRI
 *
 * The platform covered likelihood, impact and inherent risk. Root cause did
 * not exist at all (`loss_event_rca` only analyses causes AFTER an event, and
 * AnalysisController's bow-tie read two columns that were never on `risks`).
 * Controls existed but were never shown during an assessment, so residual risk
 * was typed in by hand — an assertion, not a derivation. Treatment, owner, due
 * date and KRI lived in modules an assessor had to leave the assessment to
 * reach.
 *
 * Three tables close the chain.
 *
 * `risk_cause_categories` is the configurable cause taxonomy.
 * organization_id is NULLABLE and means "system category, available to every
 * tenant" — the convention widget_definitions, object_types and
 * scoring_profiles already use, so a new organization can classify a cause
 * before anyone has configured anything. The six seeded rows are Basel's four
 * operational cause classes plus the two the Nigerian operating environment
 * makes unavoidable: third parties (agent networks, switches, aggregators)
 * and governance.
 *
 * `risk_causes` is the register. Causes belong to the RISK, not to a single
 * assessment — a cause outlives the quarter that found it, and "which causes
 * recur across the register" is the question that makes the taxonomy worth
 * having. Each assessment snapshots which causes it considered.
 *
 * `risk_assessment_controls` is the load-bearing one. It records how each
 * mapped control was rated DURING THIS ASSESSMENT, rather than reading the
 * control library live. Without it, re-testing a control next month silently
 * rewrites last quarter's residual score, and a board paper stops being
 * reproducible. Design and operating effectiveness are separate columns
 * because a well-designed control that is not being performed is a different
 * finding from a badly designed one that is.
 */
return new class extends Migration
{
    /**
     * Basel's four operational cause classes, plus third-party and governance.
     * Seeded with organization_id NULL, so every tenant inherits them.
     */
    private const SYSTEM_CATEGORIES = [
        ['code' => 'people', 'name' => 'People', 'description' => 'Human error, skills gaps, capacity, conduct, key-person dependency.', 'sort_order' => 10],
        ['code' => 'process', 'name' => 'Process', 'description' => 'Design flaws, missing or unclear procedure, manual handoffs, control gaps.', 'sort_order' => 20],
        ['code' => 'systems', 'name' => 'Systems & Technology', 'description' => 'Application failure, infrastructure, data quality, integration, cyber.', 'sort_order' => 30],
        ['code' => 'external', 'name' => 'External Events', 'description' => 'Regulatory change, market conditions, fraud by outsiders, power, security, weather.', 'sort_order' => 40],
        ['code' => 'third_party', 'name' => 'Third Party', 'description' => 'Vendors, agents, switches, aggregators, correspondent banks, outsourced providers.', 'sort_order' => 50],
        ['code' => 'governance', 'name' => 'Governance & Strategy', 'description' => 'Unclear accountability, inadequate oversight, strategic misalignment, policy gaps.', 'sort_order' => 60],
    ];

    public function up(): void
    {
        Schema::create('risk_cause_categories', function (Blueprint $table) {
            $table->id();
            // NULL = system category, inherited by every tenant. See the docblock.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('risk_causes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();

            // NULL is permitted: an assessor in a workshop should be able to
            // capture the cause first and classify it afterwards, rather than
            // being blocked by a taxonomy they may not have configured.
            $table->foreignId('cause_category_id')->nullable()
                ->constrained('risk_cause_categories')->nullOnDelete();

            $table->text('description');

            // Where the cause came from: workshop | loss_event | near_miss |
            // audit_finding | kri_breach | incident | regulatory | other.
            // A string, not an enum, so a new intake channel is a value not a
            // migration.
            $table->string('source', 30)->nullable();

            // The evidence behind the cause, when there is any — a loss event,
            // an issue, an audit finding. Kept as free text on purpose: the
            // polymorphic link belongs in WP-11's bow-tie, not here.
            $table->string('evidence_ref', 255)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['risk_id', 'sort_order']);
            $table->index(['organization_id', 'cause_category_id']);
        });

        Schema::create('risk_assessment_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, not cascade: an assessment's control ratings are
            // the evidence for its residual score. Deleting a control must not
            // quietly rewrite history. `controls` soft-deletes, so the normal
            // retirement path is unaffected.
            $table->foreignId('control_id')->constrained()->restrictOnDelete();

            // Snapshot, so a renamed or retired control still reads correctly
            // on an assessment from three years ago.
            $table->string('control_code', 20)->nullable();
            $table->string('control_name', 200)->nullable();

            // effective | mostly_effective | partially_effective | ineffective |
            // not_operating — the same vocabulary as controls.effectiveness_rating
            // and config/risk.php's control_effectiveness map.
            $table->string('design_effectiveness', 30)->nullable();
            $table->string('operating_effectiveness', 30)->nullable();

            // Resolved percentage for this control in THIS assessment. The
            // weaker of design and operating wins: a control that is well
            // designed but not performed provides the assurance of the latter.
            $table->decimal('effectiveness_pct', 5, 2)->nullable();

            // Carried from risk_control_mapping at assessment time so the
            // weighted aggregate is reproducible even if the mapping changes.
            $table->decimal('control_weight', 5, 2)->default(1.00);
            $table->boolean('is_key_control')->default(false);

            $table->text('notes')->nullable();
            $table->string('evidence_ref', 255)->nullable();
            $table->timestamps();

            $table->unique(['risk_assessment_id', 'control_id']);
            $table->index(['organization_id', 'control_id']);
        });

        Schema::table('risk_assessments', function (Blueprint $table) {
            // Step 7's output: the weighted aggregate of this assessment's
            // control ratings. The existing `control_effectiveness_data` JSON
            // column was declared in the original migration and written by
            // nothing; it now carries the per-control breakdown for display,
            // while this column carries the number residual risk derives from.
            $table->decimal('control_effectiveness_pct', 5, 2)->nullable()->after('control_effectiveness_data');

            // 'derived' — residual came from inherent × control effectiveness
            // through the organization's scoring profile.
            // 'override' — an assessor overruled it, and residual_justification
            // is then required. Distinguishing the two is what lets a reviewer
            // see which residual scores are judgement rather than arithmetic.
            $table->string('residual_source', 20)->nullable()->after('residual_rating');
            $table->text('residual_justification')->nullable()->after('residual_source');

            // Step 9, recorded at the assessment that decided it.
            // avoid | reduce | share | transfer | accept — four responses plus
            // sharing, which the master plan flags as missing platform-wide.
            $table->string('treatment_strategy', 20)->nullable()->after('residual_justification');

            // Which causes this assessment considered, frozen at submission:
            // [{id, description, category}]. Causes stay editable on the risk;
            // the assessment keeps the version it reasoned about.
            $table->json('cause_snapshot')->nullable()->after('treatment_strategy');
        });

        $now = now();

        DB::table('risk_cause_categories')->insert(array_map(fn (array $row) => $row + [
            'organization_id' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::SYSTEM_CATEGORIES));

        $this->backfillCausesFromLossEvents();
    }

    /**
     * Every risk that has already caused a loss starts with a real cause
     * rather than an empty register.
     *
     * The platform has been collecting post-event root causes all along — in
     * `loss_event_rca.root_cause_description` where a full RCA was performed,
     * and in `loss_events.initial_root_cause` where it was not. Where the loss
     * event is linked to a risk (`loss_events.risk_register_id`), that cause is
     * exactly the cause the risk should be carrying going forward. Seeded with
     * a NULL category and source 'loss_event', so it reads as inherited
     * evidence awaiting classification rather than as an assessor's assertion.
     */
    private function backfillCausesFromLossEvents(): void
    {
        if (! Schema::hasTable('loss_events') || ! Schema::hasColumn('loss_events', 'risk_register_id')) {
            return;
        }

        $rows = collect();

        // Preferred source: a completed root-cause analysis.
        $rcaColumn = Schema::hasTable('loss_event_rca')
            ? collect(['root_cause_description', 'root_cause_statement'])
                ->first(fn (string $column) => Schema::hasColumn('loss_event_rca', $column))
            : null;

        if ($rcaColumn !== null) {
            $rows = $rows->concat(
                DB::table('loss_event_rca')
                    ->join('loss_events', 'loss_events.id', '=', 'loss_event_rca.loss_event_id')
                    ->whereNotNull('loss_events.risk_register_id')
                    ->whereNotNull('loss_event_rca.'.$rcaColumn)
                    ->where('loss_event_rca.'.$rcaColumn, '!=', '')
                    ->select([
                        'loss_events.risk_register_id as risk_id',
                        'loss_events.organization_id',
                        'loss_event_rca.'.$rcaColumn.' as description',
                    ])
                    ->get()
            );
        }

        // Fallback: the cause captured when the loss was first reported.
        foreach (['root_cause_summary', 'initial_root_cause'] as $column) {
            if (! Schema::hasColumn('loss_events', $column)) {
                continue;
            }

            $rows = $rows->concat(
                DB::table('loss_events')
                    ->whereNotNull('risk_register_id')
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->select([
                        'risk_register_id as risk_id',
                        'organization_id',
                        $column.' as description',
                    ])
                    ->get()
            );
        }

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $seen = [];
        $insert = [];

        foreach ($rows as $row) {
            $description = trim((string) $row->description);
            $key = $row->risk_id.'|'.mb_strtolower($description);

            if ($description === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $insert[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $row->organization_id,
                'risk_id' => $row->risk_id,
                'cause_category_id' => null,
                'description' => mb_substr($description, 0, 5000),
                'source' => 'loss_event',
                'evidence_ref' => null,
                'is_primary' => false,
                'sort_order' => 0,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 200) as $chunk) {
            DB::table('risk_causes')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            $table->dropColumn([
                'control_effectiveness_pct',
                'residual_source',
                'residual_justification',
                'treatment_strategy',
                'cause_snapshot',
            ]);
        });

        Schema::dropIfExists('risk_assessment_controls');
        Schema::dropIfExists('risk_causes');
        Schema::dropIfExists('risk_cause_categories');
    }
};
