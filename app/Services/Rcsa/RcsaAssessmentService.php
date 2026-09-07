<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaLineRevision;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\User;
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

    public function __construct(
        private readonly RcsaCalculationService $calculator,
        private readonly RcsaAuditRecorder $audit,
    ) {}

    /**
     * Apply an assessor's answers to one line.
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
        // OPTIMISTIC LOCKING. Two risk champions in the same unit editing the
        // same grid is the normal case, not the edge case: the second save
        // must be refused loudly rather than quietly overwriting the first.
        if ($expectedVersion !== null && (int) $line->version !== $expectedVersion) {
            return ['status' => self::CONFLICT, 'line' => $line->fresh() ?? $line];
        }

        if ($line->isLocked()) {
            return ['status' => self::LOCKED, 'line' => $line];
        }

        $methodology = $this->methodologyFor($line);

        return DB::transaction(function () use ($line, $input, $actor, $methodology, $request) {
            $before = $line->only(RcsaAssessmentLine::MATERIAL_FIELDS);

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

            $line->fill($assessed);

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

            if ($line->isScored()) {
                $line->assessor_id = $actor->id;
                $line->assessed_at = now();
            }

            $line->version = (int) $line->version + 1;
            $line->save();

            $this->recordRevisions($line, $before, $actor, $request);
            $this->recomputeProgress($line->assessment);

            return ['status' => 'saved', 'line' => $line->refresh()];
        });
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

        $lines = $assessment->lines()->with('actionPlans')->get();

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
     * Write a before/after row for each material field that moved.
     *
     * @param  array<string, mixed>  $before
     */
    private function recordRevisions(RcsaAssessmentLine $line, array $before, User $actor, ?Request $request): void
    {
        foreach (RcsaAssessmentLine::MATERIAL_FIELDS as $field) {
            $old = $before[$field] ?? null;
            $new = $line->{$field};

            if ($old === $new) {
                continue;
            }

            $reason = $field === 'treatment_override' ? $line->treatment_override_reason : null;

            RcsaLineRevision::create([
                'organization_id' => $line->organization_id,
                'line_id' => $line->id,
                'user_id' => $actor->id,
                'field' => $field,
                'old_value' => $old,
                'new_value' => $new,
                'reason' => $reason,
                'request_id' => $request?->header('X-Request-Id'),
                'ip_address' => $request?->ip(),
                'created_at' => now(),
            ]);

            // And into the estate-wide trail (§11). Two writes on purpose: the
            // revision answers "what happened to this line", the trail answers
            // "what did this person do", and the second is hash-chained.
            $this->audit->lineChange($line, $field, $old, $new, $actor, $reason);
        }
    }
}
