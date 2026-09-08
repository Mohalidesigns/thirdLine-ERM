<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Q5 — "may an assessor override the calculated treatment, and who signs
 * that off?"
 *
 * Half of this shipped in P3: an override exists, and the submission gate
 * refuses one with no written justification. The half that did not is the
 * second signature. Today an assessor can change TREAT to ACCEPT on a risk
 * above appetite, write a sentence, and file it — the justification is
 * recorded and nobody is required to agree with it.
 *
 * AS EVER, THE DEFAULT IS TODAY'S BEHAVIOUR. Approval is per tenant, in
 * `organizations.settings['rcsa']['treatment_override_approval_required']`,
 * default off, beside `bu_approval_required` and for the same reason P5 gave:
 * a workflow policy cannot live on the methodology, because the methodology
 * LOCKS when a cycle opens and a bank would then have to version its scoring
 * engine to turn an approval step on.
 *
 * `none` IS NOT `approved`, AND EXISTING OVERRIDES STAY `none`. It means "no
 * approval was asked for" — which is the truth about every override written
 * before this migration and about every override written by a tenant that
 * leaves the setting off. Backfilling them to `approved` would assert a
 * sign-off that never happened, which is the same lie P8 refused when it made
 * migrated cycles arrive `closed` rather than `validated`. Backfilling them to
 * `pending` would be worse still: it would retroactively block assessments
 * that were validly filed under the rule in force at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rcsa_assessment_lines', function (Blueprint $table) {
            // none:     not subject to approval (setting off, or predates it).
            // pending:  requested, and NOT IN FORCE until decided.
            // approved: in force, with a name and a time against it.
            // rejected: refused; the calculated treatment stands.
            $table->enum('treatment_override_status', ['none', 'pending', 'approved', 'rejected'])
                ->default('none')
                ->after('treatment_override_reason');

            $table->foreignId('treatment_override_requested_by')
                ->nullable()->after('treatment_override_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('treatment_override_requested_at')->nullable()
                ->after('treatment_override_requested_by');

            $table->foreignId('treatment_override_decided_by')
                ->nullable()->after('treatment_override_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('treatment_override_decided_at')->nullable()
                ->after('treatment_override_decided_by');

            // Why it was approved or refused. Mandatory on a REJECTION — an
            // assessor told only "no" cannot act on it — and enforced in the
            // Form Request rather than here, so a draft can still be saved.
            $table->text('treatment_override_decision_note')->nullable()
                ->after('treatment_override_decided_at');

            // The review screen's "what is still undecided" query.
            $table->index(
                ['assessment_id', 'treatment_override_status'],
                'rcsa_lines_override_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('rcsa_assessment_lines', function (Blueprint $table) {
            $table->dropIndex('rcsa_lines_override_status_idx');

            $table->dropConstrainedForeignId('treatment_override_requested_by');
            $table->dropConstrainedForeignId('treatment_override_decided_by');

            $table->dropColumn([
                'treatment_override_status',
                'treatment_override_requested_at',
                'treatment_override_decided_at',
                'treatment_override_decision_note',
            ]);
        });
    }
};
