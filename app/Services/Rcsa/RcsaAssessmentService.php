<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\Rcsa\RcsaLineRevision;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\User;
use App\Support\Audit\AuditText;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Saving an assessment line, and everything that has to happen with it.
 *
 * ONE SAVE DOES FIVE THINGS, and leaving any of them to the caller is how they
 * come to be done inconsistently:
 *
 *   1. Refuses the write if somebody else has changed the line since it was
 *      read (the `version` check).
 *   2. Records a before/after revision for every MATERIAL field that moved.
 *   3. Recomputes the calculated columns through RcsaCalculationService —
 *      never trusting a number the client sent.
 *   4. Stamps the assessor and the time.
 *   5. Recomputes the assessment's stored completion percentage.
 *
 * THE FIRST SAVED RATING ALSO STARTS THE ASSESSMENT: a `draft` ("not started")
 * assessment moves to `in_progress` through RcsaWorkflowService, in the same
 * transaction. A "rating" is a change to one of RATING_FIELDS — a rationale or
 * an override on its own is not the work of assessing, and neither is a save
 * that only bumped the version. See startIfNotStarted().
 *
 * THE INVARIANT: THE COMPUTED COLUMNS ARE ALWAYS DERIVED FROM THE MERGED,
 * LOCKED ROW. The version check, the lock check, the merge of the answers and
 * the calculation all happen INSIDE one transaction, on a row read with
 * `lockForUpdate()` — never on the instance route-model binding loaded before
 * the transaction began. The autosaving grid sends one field per request, and
 * three of them in quick succession (L, I, CE) each used to compute from a
 * snapshot holding only their own field, then each wrote its own column: the
 * three answers were all stored, the scores were not, and `isScored()` (which
 * looks only at the answers) let an above-appetite risk with no plan through
 * the submission gate. Now the second writer waits for the first, reads what it
 * wrote, and computes from all of it.
 *
 * THE CLIENT'S CALCULATED VALUES ARE IGNORED, ALWAYS. The React grid computes
 * the same figures locally so a badge repaints in the same frame, but what it
 * sends is discarded: `apply()` takes only the assessed inputs. A server that
 * accepted a residual score from the browser would let anyone put any number
 * into a regulatory return by editing a request.
 */
class RcsaAssessmentService
{
    /**
     * Thrown as a 409 by the controller. Not an exception class of its own:
     * the controller needs the CURRENT line to send back so the user can see
     * what the other person did, and an exception carrying a model is a
     * clumsier way to say the same thing than a return value.
     */
    public const CONFLICT = 'conflict';

    /**
     * The line is frozen — submitted, or returned without having been flagged.
     *
     * Checked HERE as well as in the controller, and that redundancy is the
     * point: P5's rule is that a return reopens only the flagged lines, and a
     * rule enforced in exactly one controller method is one an import path, a
     * queued job or a future API route walks straight past. The controller
     * turns this into a 423 with the line attached.
     */
    public const LOCKED = 'locked';

    /**
     * The fields whose first saved change starts an assessment. The five
     * ratings an assessor chooses — NOT the rationale or the treatment
     * override, which can be written without having rated anything, and not a
     * calculated column, which the assessor never sends.
     */
    public const RATING_FIELDS = [
        'inherent_likelihood',
        'inherent_impact',
        'control_effectiveness',
        'residual_likelihood',
        'residual_impact',
    ];

    /**
     * The reason written on the revisions a repair makes, when the caller does
     * not give a more specific one. The repair is the SYSTEM's, never a
     * person's: see recomputeUnscored().
     */
    public const RECOMPUTE_REASON = 'Recomputed — scores were missing';

    public function __construct(
        private readonly RcsaCalculationService $calculator,
        private readonly RcsaAuditRecorder $audit,
        private readonly RcsaWorkflowService $workflow,
    ) {}

    /**
     * Apply an assessor's answers to one line.
     *
     * The `$line` passed in is only an ADDRESS. Its attributes may be stale — it
     * was loaded by route-model binding before this transaction opened, and
     * another request may have written since — so everything below reads the row
     * again under a write lock. The passed instance is brought into line with
     * what was saved, so a caller holding it sees the result.
     *
     * @param  array<string, mixed>  $input  Only the assessed fields are read.
     * @param  int|null  $expectedVersion  The version the client last read. Null skips the check.
     * @return array{status: string, line: RcsaAssessmentLine}
     */
    public function apply(
        RcsaAssessmentLine $line,
        array $input,
        User $actor,
        ?int $expectedVersion = null,
        ?Request $request = null,
    ): array {
        return DB::transaction(function () use ($line, $input, $actor, $expectedVersion, $request) {
            $current = $this->lockedRow($line);

            // OPTIMISTIC LOCKING, now a true compare-and-swap: the comparison is
            // made against the row as it is while we hold its lock, so two
            // requests that read version n cannot both pass it.
            //
            // Two risk champions in the same unit editing the same grid is the
            // normal case, not the edge case: the second save must be refused
            // loudly rather than quietly overwriting the first.
            if ($expectedVersion !== null && (int) $current->version !== $expectedVersion) {
                $this->syncInstance($line, $current);

                return ['status' => self::CONFLICT, 'line' => $current];
            }

            if ($current->isLocked()) {
                $this->syncInstance($line, $current);

                return ['status' => self::LOCKED, 'line' => $current];
            }

            $before = $current->only(RcsaAssessmentLine::MATERIAL_FIELDS);

            // ONLY the assessor's own answers. Anything else the client sent —
            // including every calculated column — is not read.
            $assessed = Arr::only($input, [
                'inherent_likelihood',
                'inherent_impact',
                'control_effectiveness',
                'residual_likelihood',
                'residual_impact',
                'treatment_override',
                'treatment_override_reason',
                'assessment_rationale',
            ]);

            $current->fill($assessed);

            $this->calculateInto($current);

            if ($current->isScored()) {
                $current->assessor_id = $actor->id;
                $current->assessed_at = now();
            }

            $current->version = (int) $current->version + 1;
            $current->save();

            $this->recordRevisions($current, $before, $actor, $request);
            $this->recomputeProgress($current->assessment);

            // The first RATING that actually lands starts the assessment. Not
            // "any save": `version` was bumped above, so a bare wasChanged([])
            // is always true and a PATCH of nothing but the version would have
            // started it. A same-answer re-send, a rationale-only save and an
            // override-only save are not somebody doing the assessing.
            if ($current->wasChanged(self::RATING_FIELDS)) {
                $this->startIfNotStarted($current, $actor, $request);
            }

            $current->refresh();
            $this->syncInstance($line, $current);

            return ['status' => 'saved', 'line' => $current];
        });
    }

    /**
     * `draft → in_progress`, once, the first time somebody saves a rating.
     *
     * "NOT STARTED" IS `draft` (RCSA-05): an assessment is created when its
     * cycle opens and stays `draft` until a person rates something, so the
     * dashboard and the deadline reminders can tell an idle unit from one being
     * worked. This is the only place that moves it, and it goes THROUGH the
     * state machine — a forceFill here would be the one status movement in the
     * module with no transition row behind it.
     *
     * The status is re-read under a write lock on the assessment row, inside
     * the caller's transaction: two first saves in the same unit (the grid
     * autosaves one field per request, and two champions share a unit) both
     * arrive believing the assessment is `draft`, and only the one that gets
     * the lock first may write the transition. The second sees `in_progress`
     * and writes nothing.
     *
     * recomputeUnscored() never calls this: repairing a score is not
     * somebody starting the work, and it has no actor at all.
     */
    private function startIfNotStarted(RcsaAssessmentLine $line, User $actor, ?Request $request): void
    {
        $assessment = RcsaAssessment::query()
            ->where('organization_id', $line->organization_id)
            ->whereKey($line->assessment_id)
            ->lockForUpdate()
            ->first();

        if ($assessment === null || $assessment->status !== RcsaAssessment::DRAFT) {
            return;
        }

        $this->workflow->transition(
            assessment: $assessment,
            to: RcsaAssessment::IN_PROGRESS,
            actor: $actor,
            event: RcsaAssessmentTransition::START,
            request: $request,
        );

        // The relation the caller may go on to read must not still say `draft`.
        $line->unsetRelation('assessment');
    }

    /**
     * Recalculate every line of an assessment that has its three answers but no
     * residual band — the state the lost-update race (and an import with an
     * unrecognised control rating) used to leave behind.
     *
     * Goes through the same engine as apply(), so each repaired line gets its
     * revisions (`residual_score`, `risk_treatment`, …) and a version bump.
     * The answers themselves are not touched and neither is the assessor.
     *
     * THE REPAIR IS THE SYSTEM'S, NOT A PERSON'S. There is deliberately no
     * actor parameter: the revisions and the audit-trail rows carry NO user and
     * a reason saying what happened. Submit calls this, and attributing the
     * recomputed scores to the person pressing Submit would make the trail say
     * that they changed a residual score — which they did not. `$request`, when
     * given, only correlates the rows (request id, IP) with the request that
     * triggered the repair.
     *
     * A line whose control rating matches nothing in the methodology stays
     * unscored — there is no residual to compute — and is not counted.
     *
     * @param  string  $reason  Written on every revision the repair makes.
     * @return int Lines whose stored scores changed.
     */
    public function recomputeUnscored(
        RcsaAssessment $assessment,
        ?Request $request = null,
        bool $includeLocked = false,
        string $reason = self::RECOMPUTE_REASON,
    ): int {
        $ids = $this->unscoredLines($assessment, $includeLocked)->pluck('id');

        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($assessment, $id, $request, $includeLocked, $reason) {
                $current = RcsaAssessmentLine::query()
                    ->where('organization_id', $assessment->organization_id)
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                // Re-checked under the lock: a concurrent save may have
                // repaired it, and a submit may have locked it.
                if ($current === null
                    || $current->residual_level !== null
                    || ! $current->isScored()
                    || ($current->isLocked() && ! $includeLocked)) {
                    return 0;
                }

                $before = $current->only(RcsaAssessmentLine::MATERIAL_FIELDS);

                $this->calculateInto($current);

                if (! $current->isDirty()) {
                    return 0;
                }

                $current->version = (int) $current->version + 1;
                $current->save();

                $this->recordRevisions($current, $before, null, $request, system: true, systemReason: $reason);

                return 1;
            });
        }

        return $changed;
    }

    /**
     * Lines that carry all three answers but no residual band.
     *
     * @return \Illuminate\Database\Eloquent\Builder<RcsaAssessmentLine>
     */
    public function unscoredLines(RcsaAssessment $assessment, bool $includeLocked = true)
    {
        return RcsaAssessmentLine::query()
            ->where('organization_id', $assessment->organization_id)
            ->where('assessment_id', $assessment->id)
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->whereNotNull('control_effectiveness')
            ->whereNull('residual_level')
            ->when(! $includeLocked, fn ($q) => $q->whereNull('locked_at'))
            ->orderBy('id');
    }

    /**
     * Apply one field to many lines at once (§8.3's bulk apply).
     *
     * Deliberately narrow: the plan names control effectiveness and owner, and
     * a general "set these columns on these rows" action is how a bulk edit
     * comes to rewrite risk statements. Every line still goes through apply(),
     * so every one is recomputed, revisioned and version-bumped exactly as a
     * single save would be — a bulk action that skipped the revision trail
     * would be the easiest way to change sixty ratings without a record.
     *
     * @param  \Illuminate\Support\Collection<int, RcsaAssessmentLine>  $lines
     * @param  array<string, mixed>  $input
     * @return int Lines changed.
     */
    public function applyToMany($lines, array $input, User $actor, ?Request $request = null): int
    {
        $changed = 0;

        foreach ($lines as $line) {
            // No version check: the user selected these rows in this session
            // and is deliberately overwriting them. The revision trail is what
            // makes that recoverable.
            $result = $this->apply($line, $input, $actor, expectedVersion: null, request: $request);

            if (! in_array($result['status'], [self::CONFLICT, self::LOCKED], true)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Recompute and store the assessment's completion percentage.
     *
     * Scored lines over total lines. A line is scored when all three assessed
     * columns are answered — see RcsaAssessmentLine::isScored() for why that
     * is not "has a residual score".
     */
    public function recomputeProgress(RcsaAssessment $assessment): int
    {
        $total = $assessment->lines()->count();

        $scored = $total === 0 ? 0 : $assessment->lines()
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->whereNotNull('control_effectiveness')
            ->count();

        $pct = $total === 0 ? 0 : (int) floor($scored / $total * 100);

        $assessment->forceFill(['completion_pct' => $pct])->save();

        return $pct;
    }

    /**
     * What still stands between this assessment and submission (§8.3's
     * progress and validation panel).
     *
     * Returned as structured issues rather than a rendered list, because the
     * screen turns each one into a click-to-jump link and P4's submission gate
     * consults the same call. "Failures are listed with jump links, never a
     * single generic toast."
     *
     * @return array{scored: int, total: int, issues: list<array{type: string, line_id: int, risk_no: string, message: string}>}
     */
    public function outstanding(RcsaAssessment $assessment): array
    {
        $issues = [];
        $scored = 0;

        // `priorLine` is eager-loaded because `movedMaterially()` below reads it
        // for every line. Without it this method issued one query per line —
        // 499 of them on a 500-line assessment — and it did so INVISIBLY UNTIL
        // THE SECOND CYCLE, because `prior_cycle_line_id` is null until a
        // cycle has a predecessor. A bank would have met it in their second
        // quarter, on the largest assessment they had.
        //
        // Only the columns movedMaterially() compares: this is a second copy of
        // the lines, loaded alongside the controller's own, and there is no
        // reason for it to carry the risk statements twice.
        $lines = $assessment->lines()
            ->with([
                'actionPlans',
                'priorLine:id,inherent_score,residual_level',
            ])
            ->get();

        foreach ($lines as $line) {
            if ($line->isScored()) {
                $scored++;
            } else {
                $missing = [];

                foreach (RcsaAssessmentLine::ASSESSED_FIELDS as $field) {
                    if (blank($line->{$field})) {
                        $missing[] = match ($field) {
                            'inherent_likelihood' => 'likelihood',
                            'inherent_impact' => 'impact',
                            default => 'control effectiveness',
                        };
                    }
                }

                $issues[] = [
                    'type' => 'unscored',
                    'line_id' => $line->id,
                    'risk_no' => (string) $line->risk_no,
                    'message' => 'Not assessed: no '.implode(', no ', $missing).'.',
                ];

                // An unscored line cannot also be above appetite or have moved,
                // so nothing below applies to it.
                continue;
            }

            /* --- Answered but never scored ------------------------------ */

            // `isScored()` looks only at the three ANSWERS. A line whose answers
            // were stored but whose scores were not (the lost-update race, or a
            // control rating that matches nothing in the methodology) has a null
            // residual band, and a null band is never "above appetite" — so
            // without this the appetite check below would be skipped and the
            // line would walk through the submission gate. It is listed, and
            // nothing further is asked of it.
            //
            // ONLY FOR A LINE THAT ACCEPTS EDITS. A locked line was filed as it
            // stands: its assessor cannot re-save it (423), submit never
            // rewrites it, and a blocker the user has no way to clear would
            // dead-end a returned assessment's resubmission behind an
            // instruction nobody can follow. It is repaired by the data fix
            // (`rcsa:recompute-scores --include-locked`), and nothing further
            // is asked of it here — a null band is never above appetite.
            if ($line->residual_level === null || $line->inherent_score === null) {
                if ($line->isLocked()) {
                    continue;
                }

                $issues[] = [
                    'type' => 'unscored',
                    'line_id' => $line->id,
                    'risk_no' => (string) $line->risk_no,
                    'message' => 'Assessed but not scored — re-save one of its ratings, and check the control rating is one of the listed options.',
                ];

                continue;
            }

            /* --- Above appetite without a complete plan (§8.4) --------- */

            $aboveAppetite = $this->isAboveAppetite($line);

            if ($aboveAppetite) {
                $complete = $line->actionPlans->filter(fn ($plan) => $plan->isComplete());

                if ($complete->isEmpty()) {
                    $issues[] = [
                        'type' => 'action_plan',
                        'line_id' => $line->id,
                        'risk_no' => (string) $line->risk_no,
                        'message' => 'Above risk appetite with no action plan: needs a control, an owner and a date.',
                    ];
                }
            }

            /* --- An override with no justification --------------------- */

            if (filled($line->treatment_override)
                && $line->treatment_override !== $line->risk_treatment
                && blank($line->treatment_override_reason)) {
                $issues[] = [
                    'type' => 'override',
                    'line_id' => $line->id,
                    'risk_no' => (string) $line->risk_no,
                    'message' => 'Treatment overridden to "'.$line->treatment_override.'" without a justification.',
                ];
            }

            /* --- Material movement with no rationale (§8.3) ------------ */

            if ($this->movedMaterially($line) && blank($line->assessment_rationale)) {
                $issues[] = [
                    'type' => 'movement',
                    'line_id' => $line->id,
                    'risk_no' => (string) $line->risk_no,
                    'message' => 'Moved materially since the last cycle without a rationale.',
                ];
            }
        }

        return ['scored' => $scored, 'total' => $lines->count(), 'issues' => $issues];
    }

    /**
     * Whether a line's residual sits above the methodology's appetite ceiling.
     */
    public function isAboveAppetite(RcsaAssessmentLine $line): bool
    {
        return $this->methodologyFor($line)->isAboveAppetite($line->residual_level);
    }

    /**
     * Whether this line moved materially since the cycle before it.
     *
     * §8.3: a change of two or more score points, or any band change. Two
     * points because a single-step change on one axis is ordinary
     * re-assessment; a band change because it is what alters the obligation.
     */
    public function movedMaterially(RcsaAssessmentLine $line): bool
    {
        $prior = $line->getRelationValue('priorLine');

        if ($prior === null || $prior->inherent_score === null || $line->inherent_score === null) {
            return false;
        }

        if ($prior->residual_level !== null && $line->residual_level !== null
            && $prior->residual_level !== $line->residual_level) {
            return true;
        }

        return abs((int) $line->inherent_score - (int) $prior->inherent_score) >= 2;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * The methodology this line is scored against — the one its CYCLE pinned,
     * not whatever is active today.
     *
     * That distinction is the whole reason methodologies are versioned: a
     * closed 2026 assessment re-rendered in 2027 must use 2026's bands.
     */
    private function methodologyFor(RcsaAssessmentLine $line): RcsaMethodology
    {
        static $cache = [];

        $id = (int) $line->methodology_id;

        if (! isset($cache[$id])) {
            $cache[$id] = RcsaMethodology::withoutGlobalScopes()
                ->with(['scaleItems', 'bands'])
                ->findOrFail($id);
        }

        return $cache[$id];
    }

    /**
     * The line as it is NOW, under a write lock.
     *
     * Scoped to the passed line's own organization as well as its key: the id
     * came from a tenant-scoped binding, and this keeps the guarantee when the
     * service is called from somewhere with no tenant context.
     */
    private function lockedRow(RcsaAssessmentLine $line): RcsaAssessmentLine
    {
        return RcsaAssessmentLine::query()
            ->where('organization_id', $line->organization_id)
            ->whereKey($line->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Bring the caller's instance into line with the row that was just read or
     * written, so a caller holding it does not go on looking at stale values.
     */
    private function syncInstance(RcsaAssessmentLine $line, RcsaAssessmentLine $current): void
    {
        if ($line === $current) {
            return;
        }

        $line->setRawAttributes($current->getAttributes(), true);
    }

    /**
     * Derive the computed columns from the line's own (merged) answers.
     */
    private function calculateInto(RcsaAssessmentLine $line): void
    {
        $methodology = $this->methodologyFor($line);

        $result = $this->calculator->calculate(
            likelihood: $line->inherent_likelihood,
            impact: $line->inherent_impact,
            controlEffectiveness: $line->control_effectiveness,
            methodology: $methodology,
            residualLikelihood: $line->residual_likelihood,
            residualImpact: $line->residual_impact,
        );

        // The canonical spelling of the control rating, so "fully achieved"
        // typed into an import and "Fully Achieved" chosen from the select
        // are one value on the line.
        if ($result->controlEffectiveness !== null) {
            $line->control_effectiveness = $result->controlEffectiveness;
        }

        $line->fill($result->toLineColumns());
    }

    /**
     * Write a before/after row for each material field that moved.
     *
     * @param  array<string, mixed>  $before
     * @param  bool  $system  A repair made by the system rather than by a person: the audit-trail row
     *                        then carries no user at all, instead of falling back to whoever is signed in.
     * @param  string|null  $systemReason  The reason recorded on every revision of a system repair.
     */
    private function recordRevisions(
        RcsaAssessmentLine $line,
        array $before,
        ?User $actor,
        ?Request $request,
        bool $system = false,
        ?string $systemReason = null,
    ): void {
        foreach (RcsaAssessmentLine::MATERIAL_FIELDS as $field) {
            $old = $before[$field] ?? null;
            $new = $line->{$field};

            if ($old === $new) {
                continue;
            }

            $reason = $field === 'treatment_override' ? $line->treatment_override_reason : $systemReason;

            RcsaLineRevision::create([
                'organization_id' => $line->organization_id,
                'line_id' => $line->id,
                'user_id' => $actor?->id,
                'field' => $field,
                'old_value' => $old,
                'new_value' => $new,
                'reason' => $reason,
                // Client-supplied and the column is string(64): clip it.
                'request_id' => AuditText::clip($request?->header('X-Request-Id'), 64),
                'ip_address' => $request?->ip(),
                'created_at' => now(),
            ]);

            // And into the estate-wide trail (§11). Two writes on purpose: the
            // revision answers "what happened to this line", the trail answers
            // "what did this person do", and the second is hash-chained.
            $this->audit->lineChange($line, $field, $old, $new, $actor, $reason, system: $system);
        }
    }
}
