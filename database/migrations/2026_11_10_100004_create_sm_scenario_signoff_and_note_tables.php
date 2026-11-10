<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 1 — the two append-only governance tables,
 * frozen by ADR 0027 Amendment 1 §A1.6 and §A1.7 plus addendum-1.
 *
 * Grouped in one file because they are one concern (D4's bias-mitigation trail)
 * and neither is referenced by anything else, as BCMS groups by concern.
 *
 * ── sm_scenario_signoffs ────────────────────────────────────────────────────
 *
 * NO UNIQUE KEY ON (sm_scenario_id, source), DELIBERATELY. D4 says sign-off
 * freezes and post-sign-off edits require revalidation — so source 1 signs, the
 * scenario is returned, and source 1 signs AGAIN; a unique key would force an
 * UPDATE over the record this table exists to protect. The current sign-off for
 * a source is the LATEST ROW BY `signed_at`, and the lifecycle reads only those.
 * An `is_current` flag was refused: a second source of truth that drifts on the
 * third revalidation.
 *
 * `signatory_name` and `role` are SNAPSHOTS, as ADR 0026 §5 froze `email`+`name`
 * on `kri_return_notice_recipients`: `user_id` is `nullOnDelete`, and a sign-off
 * whose signatory has left must still say who signed it. "Signed by (deleted
 * user) 4" is not evidence. `role` is a FREE-TEXT CAPACITY, not a platform role
 * name — "Head of Operational Risk", "Second Line — Independent Challenge" —
 * because Spatie names are an implementation detail and renaming one would
 * silently falsify every historical sign-off storing it. `string(100)` per ADR
 * 0014: size the column for the values it will hold.
 *
 * TWO decisions, not three. `approved_with_conditions` was refused — no
 * transition branches on it, and a condition is a note row of kind `assumption`.
 * A third value nothing reads is how a workflow acquires a state nobody can
 * leave.
 *
 * `signed_at` is nullable per standard §13, and ONLY NON-NULL ROWS COUNT toward
 * the lifecycle. `requested_at`/`requested_by`, which would make the null
 * meaningful, are Phase 3 (§A1.13).
 *
 * No soft deletes and no `updated_by`: a sign-off is appended to, never edited.
 *
 * `warnings_acknowledged` (addendum-1) is a SNAPSHOT, never recomputed at
 * render, and that is the load-bearing part: spec 01 §5.5 lets a note be added
 * to a `signed_off` scenario, so a live-derived `no_challenge_recorded` would
 * vanish the moment somebody appended a challenge note a month after sign-off —
 * falsifying what was outstanding when the signer signed. The sign action ALWAYS
 * writes an array, `[]` when nothing was outstanding; Phase 1 never writes NULL,
 * and NULL reads as `[]`. `json`, not a child table (a 1:N with no behaviour of
 * its own is the shape §A1.1 refused for `sm_scenario_exposures`) and not a
 * bitmask or delimited text. Standard §15 / ADR 0025: NO digest over this column
 * in Phase 1 — and if a later phase takes one, the payload must stay a list of
 * ASCII strings, with no floats.
 *
 * ── sm_scenario_notes ───────────────────────────────────────────────────────
 *
 * APPEND-ONLY: Phase 1 registers no update and no destroy route. A corrected
 * note is a new note. This is the bias-mitigation trail D4 rests on, and a
 * dissent its author can quietly edit is not a dissent trail. Enforced by the
 * absence of routes plus a store-only Form Request, not by a DB trigger — the
 * product has none. `author_name` is a snapshot for §A1.6's reason: an anonymous
 * dissent cannot be followed up, which is worse than none.
 *
 * `kind` is `string(20)`, not a DB `enum` — ADR 0026 §2's rule: a fifth kind is
 * then a code change, not an ALTER TABLE. The four are frozen all the same.
 * `is_ai_generated` was refused; plan §6.5 puts AI-drafted narratives in Phase 3
 * and the column arrives with it in `Bcms\Scenario::$ai_generated`'s shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_scenario_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            // 'source_1' | 'source_2' | 'source_3' — the workbook's sign-off
            // block, VOCABULARY-EXTRACTION.md.
            $table->string('source', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signatory_name', 200);
            $table->string('role', 100);

            // 'approved' | 'returned_for_rework'
            $table->string('decision', 20);

            // A flag on the row, not a fourth sign-off source: independent
            // challenge is a property of how a signer signed, and Phase 1
            // refuses `validated → signed_off` with fewer than one of them.
            $table->boolean('is_independent_challenge')->default(false);
            $table->text('comment')->nullable();

            // Addendum-1. Codes only — see Signoff::WARNING_CODES. The rendered
            // wording lives in config/sm.php and is never stored, because
            // storing it would freeze text a tenant may reconfigure and
            // duplicate §A1.8's label maps.
            $table->json('warnings_acknowledged')->nullable();

            // An instant, so `_at` and `dateTime()` — standard §13.
            $table->dateTime('signed_at')->nullable();

            $table->timestamps();

            $table->index(['sm_scenario_id', 'source', 'signed_at'], 'sm_signoffs_scenario_source_idx');
            $table->index(['organization_id', 'sm_scenario_id'], 'sm_signoffs_org_scenario_idx');
        });

        Schema::create('sm_scenario_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            // 'assumption' | 'dissent' | 'challenge' | 'workshop_minute'
            $table->string('kind', 20);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 200);
            $table->text('body');

            $table->timestamps();

            $table->index(
                ['organization_id', 'sm_scenario_id', 'kind'],
                'sm_notes_org_scenario_kind_idx'
            );
        });
    }

    public function down(): void
    {
        // Reverse order within the file.
        Schema::dropIfExists('sm_scenario_notes');
        Schema::dropIfExists('sm_scenario_signoffs');
    }
};
