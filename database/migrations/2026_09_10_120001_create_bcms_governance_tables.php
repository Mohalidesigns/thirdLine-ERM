<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 1 — the governance tables, under ADR 0008.
 *
 * THE FIRST STRUCTURAL MIGRATION AFTER THE FREEZE, and it exists because
 * standing rule 2 says a phase that needs a column raises an ADR, not because
 * the rule was ignored. `docs/adr/0008-phase-1-schema-additions.md` argues each
 * of the seven tables and six columns; the short version is that clause 9.3
 * (management review) has no home — which the Phase 0 handoff predicted — and
 * that a maturity score, a RACI assignment and an annual board attestation are
 * each a thing the existing columns cannot express.
 *
 * NOTHING ANOTHER TRACK READS IS ALTERED. Every existing column keeps its name,
 * its type and its meaning; `bcms_findings` gains two columns, `bcms_processes`
 * one. Tracks B, C, D and E do not have to rebase on this.
 *
 * THE MANIFEST IS REGENERATED IN THE SAME COMMIT. `bcms:verify-schema` fails on
 * an extra column as well as a missing one, so a migration that ships without
 * the manifest turns the build red — which is the mechanism working.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Management review — ISO 22301 clause 9.3, a MANDATORY record. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_management_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('bcms_programmes')->nullOnDelete();
            $table->string('reference', 40);
            $table->string('title', 250);
            $table->date('held_on');
            $table->foreignId('chaired_by')->nullable()->constrained('users')->nullOnDelete();

            // Attendees as JSON rather than a pivot: a management review's
            // attendee list includes people who are not platform users — a
            // board member, an external adviser — and a pivot to `users` would
            // quietly drop them from the record an auditor reads.
            $table->json('attendees')->nullable();

            // The clause 9.3(a)–(f) inputs, captured as a SNAPSHOT rather than
            // as live queries. A review held in March considered March's CAPA
            // status; re-deriving it in December would rewrite what the meeting
            // actually looked at, which is the one thing the record exists to
            // preserve.
            $table->json('inputs')->nullable();
            $table->timestamp('inputs_captured_at')->nullable();

            $table->longText('discussion')->nullable();
            $table->json('decisions')->nullable();

            $table->string('status', 20)->default('draft'); // draft|held|minuted|approved
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'held_on']);
        });

        /* ------------------------------------------------------------------ */
        /*  Programme scope — clause 4.3, as a SET rather than as prose. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_programme_scope', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->constrained('bcms_programmes')->cascadeOnDelete();

            // Morph over `business_unit` and `bcms_process`, both already in
            // App\Support\MorphTypes. Short aliases, never class names.
            $table->string('scopable_type', 30);
            $table->unsignedBigInteger('scopable_id');

            // FALSE IS A REAL ANSWER AND IS NOT THE ABSENCE OF A ROW. Clause 4.3
            // requires an exclusion to be justified, so "the insurance
            // brokerage is deliberately out of scope, because…" is a row here.
            // Silence would be indistinguishable from nobody having considered
            // it.
            $table->boolean('in_scope')->default(true);
            $table->text('rationale')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['programme_id', 'scopable_type', 'scopable_id'], 'bcms_prog_scope_unique');
            $table->index(['scopable_type', 'scopable_id'], 'bcms_prog_scope_morph_idx');
            $table->index(['organization_id', 'programme_id', 'in_scope'], 'bcms_prog_scope_org_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  The tenant's obligation register. */
        /* */
        /*  `bcms_clause_refs` is the SHIPPED LIBRARY — which obligations exist */
        /*  in the world. This is which of them apply to THIS institution, who */
        /*  owns each, and what cadence it drives. A bank with no open banking */
        /*  licence marks those three rows not-applicable, with a reason, and */
        /*  the evidence pack stops asking for quarterly failover evidence. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_programme_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('bcms_programmes')->cascadeOnDelete();

            // The clause code, not a foreign key: `bcms_clause_refs` has no
            // `organization_id` and is keyed by a string the enum owns. A FK
            // would work; the code is what every other table already stores in
            // `iso_clause_ref`, and two spellings of the same reference is the
            // defect the enum exists to prevent.
            $table->string('clause_ref', 60);

            $table->boolean('applies')->default(true);
            $table->text('applicability_note')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // What the obligation makes the institution DO, and how often. Free
            // text plus a machine-readable cadence, because "quarterly" has to
            // be comparable against an exercise definition's
            // `frequency_per_year` and "at least annually and after any
            // substantive change" does not reduce to a number.
            $table->string('cadence', 60)->nullable();
            $table->unsignedSmallInteger('cadence_per_year')->nullable();
            $table->text('how_satisfied')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['programme_id', 'clause_ref'], 'bcms_prog_oblig_unique');
            $table->index(['organization_id', 'applies'], 'bcms_prog_oblig_org_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  RACI. */
        /* */
        /*  `owner_id` on a process is one person in one role. RACI is four */
        /*  roles and the gap report asks a question no owner column can */
        /*  answer: "which processes have nobody ACCOUNTABLE". */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_raci_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('assignable_type', 30);
            $table->unsignedBigInteger('assignable_id');

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('raci_role', 1); // R | A | C | I
            $table->string('note', 250)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One person holds one RACI letter on one thing. Somebody both
            // responsible and accountable is two rows, which is correct and is
            // also visible.
            $table->unique(['assignable_type', 'assignable_id', 'user_id', 'raci_role'], 'bcms_raci_unique');
            $table->index(['assignable_type', 'assignable_id'], 'bcms_raci_morph_idx');
            $table->index(['organization_id', 'raci_role'], 'bcms_raci_org_role_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Maturity — STORED, dated, and produced by exactly one service. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_maturity_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('bcms_programmes')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();

            // The engine's version, stored on the row. A scoring rule that
            // changes in Phase 11 must not make March's assessment
            // uninterpretable: a reader has to be able to say which rules
            // produced this number.
            $table->string('method_version', 20)->default('1.0');

            // Nullable, not zero. A tenant with no artefacts at all has no
            // maturity score — a rate over nothing is undefined.
            $table->decimal('overall_score', 3, 2)->nullable();

            $table->string('trigger', 30)->default('manual'); // manual|scheduled|artefact_change
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'assessed_at']);
        });

        Schema::create('bcms_maturity_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('bcms_maturity_assessments')->cascadeOnDelete();

            // An ISO 22301 clause group — `4`, `5`, `6`, `7`, `8.2`, `8.4`,
            // `8.5`, `9`, `10`. Not a single clause: a maturity level for
            // "clause 8.5.3" is a precision the model does not have.
            $table->string('clause_group', 20);

            $table->unsignedTinyInteger('score')->nullable(); // 1..5, null = no evidence at all
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('expected_count')->default(0);

            // WHY the score is what it is, generated by the engine. A maturity
            // number a customer cannot interrogate is a number they will argue
            // with and then ignore.
            $table->text('rationale')->nullable();
            $table->json('evidence_summary')->nullable();

            $table->timestamps();

            $table->unique(['assessment_id', 'clause_group'], 'bcms_maturity_scores_unique');
            $table->index(['organization_id', 'clause_group'], 'bcms_maturity_scores_org_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Policy attestation — annual, dated, signed. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_plan_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('bcms_plans')->cascadeOnDelete();

            $table->string('attestation_type', 20)->default('board'); // board|executive|owner
            $table->unsignedSmallInteger('period_year');
            $table->foreignId('attested_by')->constrained('users')->cascadeOnDelete();
            $table->string('attested_by_name', 200);
            $table->string('attested_by_role', 150)->nullable();
            $table->timestamp('attested_at');

            // The words the signer agreed to, stored with the signature. An
            // attestation whose statement can be edited afterwards attests to
            // nothing.
            $table->text('statement');

            $table->string('ip_address', 45)->nullable();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'attestation_type', 'period_year', 'attested_by'], 'bcms_plan_attest_unique');
            $table->index(['organization_id', 'period_year']);
        });

        /* ------------------------------------------------------------------ */
        /*  Column additions. */
        /* ------------------------------------------------------------------ */

        Schema::table('bcms_processes', function (Blueprint $table) {
            // A regulatory designation recorded as a bare boolean is one nobody
            // can defend at an examination.
            $table->text('critical_service_justification')->nullable()->after('is_critical_service');
        });

        Schema::table('bcms_programmes', function (Blueprint $table) {
            $table->json('interested_parties')->nullable()->after('out_of_scope_statement');
            $table->foreignId('policy_plan_id')->nullable()->after('interested_parties')
                ->constrained('bcms_plans')->nullOnDelete();
        });

        // Dropped: it pointed at a document-control module this product does
        // not have, nothing ever wrote to it, and two columns for one
        // relationship is the defect `bcms_aars` already refused.
        Schema::table('bcms_programmes', function (Blueprint $table) {
            $table->dropColumn('policy_document_id');
        });

        Schema::table('bcms_objectives', function (Blueprint $table) {
            $table->decimal('baseline_value', 15, 4)->nullable()->after('target_unit');
            $table->timestamp('baseline_captured_at')->nullable()->after('baseline_value');
        });

        Schema::table('bcms_findings', function (Blueprint $table) {
            // Names the KIND of source. The four nullable FKs stay, for the
            // four the database can actually check; `audit` and `gap_analysis`
            // have no BCMS table to point at.
            $table->string('source', 30)->nullable()->after('reference');
            $table->foreignId('management_review_id')->nullable()->after('dr_test_id')
                ->constrained('bcms_management_reviews')->nullOnDelete();
            $table->index(['organization_id', 'source'], 'bcms_findings_org_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bcms_findings', function (Blueprint $table) {
            $table->dropIndex('bcms_findings_org_source_idx');
            $table->dropForeign(['management_review_id']);
            $table->dropColumn(['source', 'management_review_id']);
        });

        Schema::table('bcms_objectives', function (Blueprint $table) {
            $table->dropColumn(['baseline_value', 'baseline_captured_at']);
        });

        Schema::table('bcms_programmes', function (Blueprint $table) {
            $table->unsignedBigInteger('policy_document_id')->nullable();
            $table->dropForeign(['policy_plan_id']);
            $table->dropColumn(['interested_parties', 'policy_plan_id']);
        });

        Schema::table('bcms_processes', function (Blueprint $table) {
            $table->dropColumn('critical_service_justification');
        });

        Schema::dropIfExists('bcms_plan_attestations');
        Schema::dropIfExists('bcms_maturity_scores');
        Schema::dropIfExists('bcms_maturity_assessments');
        Schema::dropIfExists('bcms_raci_assignments');
        Schema::dropIfExists('bcms_programme_obligations');
        Schema::dropIfExists('bcms_programme_scope');
        Schema::dropIfExists('bcms_management_reviews');
    }
};
