<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\CorrectiveActionStatus;
use App\Enums\Bcms\MaturityClauseGroup;
use App\Enums\Bcms\PlanType;
use App\Enums\Bcms\RaciRole;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\Finding;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\MaturityAssessment;
use App\Models\Bcms\MaturityScore;
use App\Models\Bcms\Objective;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\Bcms\ProgrammeScopeItem;
use App\Models\Bcms\RaciAssignment;
use App\Models\Bcms\TrainingRecord;
use Illuminate\Support\Facades\DB;

/**
 * THE maturity scoring engine. There is exactly one, and Orchestration §5 says
 * so: Phase 11 builds the heatmap and the board pack **over this** and adds no
 * second scorer. `Phase1GovernanceTest` greps `app/` to keep that true.
 *
 * IT SCORES ARTEFACT PRESENCE AND CURRENCY, NOT SELF-DECLARATION. A maturity
 * model a customer fills in by hand measures their optimism. This one counts
 * what is actually in the database and how old it is, which is the same
 * evidence an auditor would ask for — and it is why the score moves the moment
 * an artefact is added, which is acceptance criterion 6.
 *
 * THE FIVE LEVELS, and what separates each from the one below:
 *
 *   1  Nothing. No artefact of this kind exists.
 *   2  Something exists, but it does not cover the estate.
 *   3  It covers the estate.
 *   4  It covers the estate AND is current — reviews inside their date,
 *      exercises actually delivered against the plan.
 *   5  All of that, AND it is improving: findings are being closed, and
 *      corrective actions carried forward are being verified.
 *
 * THE JUMP FROM 3 TO 4 IS THE PRODUCT THESIS. Blueprint §2.4: certification
 * proves you wrote plans, only testing proves readiness. A bank with a complete
 * plan library nobody has exercised scores 3, and a screen that let it score 5
 * would be selling the failure this module exists to prevent.
 *
 * A RATIO WITH NO DENOMINATOR SCORES NULL; AN ABSENT ARTEFACT SCORES 1. The two
 * look alike and are not. An organisation that has not defined a single process
 * has no BIA coverage ratio — a rate over nothing is undefined (development
 * standard §5) and 1/5 would be a judgement the data cannot support. An
 * organisation with no BC policy, on the other hand, is squarely at level 1:
 * the artefact either exists or it does not. Context and Leadership are
 * existence-based; everything else is a ratio.
 */
class MaturityService
{
    /** Bumped when a rule changes, and stored on every assessment. */
    public const METHOD_VERSION = '1.0';

    /**
     * Score the estate and store the result.
     *
     * STORED, NEVER RETURNED-AND-FORGOTTEN. A board pack printed in March must
     * reprint in December unchanged, so the assessment and its per-group scores
     * are rows with a date on them (ADR 0008).
     */
    public function assess(?Programme $programme = null, string $trigger = 'manual', ?int $userId = null): MaturityAssessment
    {
        $programme ??= Programme::query()->where('year', now()->year)->orderByDesc('id')->first();

        return DB::transaction(function () use ($programme, $trigger, $userId) {
            $assessment = MaturityAssessment::query()->create([
                'programme_id' => $programme?->getKey(),
                'assessed_at' => now(),
                'assessed_by' => $userId ?? auth()->id(),
                'method_version' => self::METHOD_VERSION,
                'trigger' => $trigger,
                'created_by' => $userId ?? auth()->id(),
            ]);

            $scored = [];

            foreach (MaturityClauseGroup::ordered() as $group) {
                $result = $this->scoreGroup($group, $programme);

                MaturityScore::query()->create([
                    'assessment_id' => $assessment->getKey(),
                    'clause_group' => $group->value,
                    'score' => $result['score'],
                    'evidence_count' => $result['count'],
                    'expected_count' => $result['expected'],
                    'rationale' => $result['rationale'],
                    'evidence_summary' => $result['summary'],
                ]);

                if ($result['score'] !== null) {
                    $scored[] = ['score' => $result['score'], 'weight' => $group->weight()];
                }
            }

            $assessment->update(['overall_score' => $this->weightedMean($scored)]);

            return $assessment->refresh()->load('scores');
        });
    }

    /** The most recent assessment, or null if the engine has never run. */
    public function latest(): ?MaturityAssessment
    {
        return MaturityAssessment::query()->with('scores')->orderByDesc('assessed_at')->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Scoring */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{score: int|null, count: int, expected: int, rationale: string, summary: array<string, mixed>}
     */
    private function scoreGroup(MaturityClauseGroup $group, ?Programme $programme): array
    {
        return match ($group) {
            MaturityClauseGroup::Context => $this->scoreContext($programme),
            MaturityClauseGroup::Leadership => $this->scoreLeadership($programme),
            MaturityClauseGroup::Planning => $this->scorePlanning($programme),
            MaturityClauseGroup::Support => $this->scoreSupport(),
            MaturityClauseGroup::Analysis => $this->scoreAnalysis(),
            MaturityClauseGroup::Strategy => $this->scoreStrategy(),
            MaturityClauseGroup::Plans => $this->scorePlans(),
            MaturityClauseGroup::Exercises => $this->scoreExercises(),
            MaturityClauseGroup::Evaluation => $this->scoreEvaluation(),
            MaturityClauseGroup::Improvement => $this->scoreImprovement(),
        };
    }

    /** Clause 4 — a programme with a scope statement and a scope SET. */
    private function scoreContext(?Programme $programme): array
    {
        if ($programme === null) {
            // ONE, NOT NULL. A programme is an artefact that either exists or
            // does not, so its absence is level 1 — "nothing" — which is a real
            // score. Null is reserved for a RATIO with no denominator: BIA
            // coverage of an empty process catalogue is undefined, and saying
            // "1 out of 5" about it would be a judgement the data cannot
            // support. The two cases look alike and are not.
            return [
                'score' => 1,
                'count' => 0,
                'expected' => 4,
                'rationale' => 'No business continuity programme has been created.',
                'summary' => [],
            ];
        }

        $hasScopeText = filled($programme->scope_statement);
        $scopeRows = ProgrammeScopeItem::query()->where('programme_id', $programme->getKey())->count();
        $exclusions = ProgrammeScopeItem::query()->where('programme_id', $programme->getKey())->where('in_scope', false)->count();
        $obligations = ProgrammeObligation::query()->where('programme_id', $programme->getKey())->count();
        $parties = is_array($programme->interested_parties) ? count($programme->interested_parties) : 0;

        $have = (int) $hasScopeText + (int) ($scopeRows > 0) + (int) ($obligations > 0) + (int) ($parties > 0);

        $score = match (true) {
            $have === 0 => 1,
            $have === 1 => 2,
            $have < 4 => 3,
            // 4 needs the exclusions to be justified as well as the inclusions —
            // clause 4.3 asks for the boundary, and a scope with no stated
            // exclusion is a scope somebody has not finished drawing.
            $exclusions === 0 => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $have,
            'expected' => 4,
            'rationale' => sprintf(
                'Scope statement %s; %d scope row(s) of which %d are stated exclusions; %d interested part%s; %d obligation(s) in the register.',
                $hasScopeText ? 'present' : 'missing', $scopeRows, $exclusions, $parties, $parties === 1 ? 'y' : 'ies', $obligations
            ),
            'summary' => compact('hasScopeText', 'scopeRows', 'exclusions', 'obligations', 'parties'),
        ];
    }

    /** Clause 5 — an approved, board-attested policy and a named accountable owner. */
    private function scoreLeadership(?Programme $programme): array
    {
        $policy = Plan::query()->where('plan_type', PlanType::Policy->value)->where('status', 'approved')->latest('id')->first();
        $attested = $policy?->attestations()->where('period_year', now()->year)->exists() ?? false;
        $accountable = $programme === null
            ? 0
            : RaciAssignment::query()
                ->where('assignable_type', 'bcms_programme')
                ->where('assignable_id', $programme->getKey())
                ->where('raci_role', RaciRole::Accountable->value)
                ->count();

        $anyPolicy = Plan::query()->where('plan_type', PlanType::Policy->value)->exists();

        $score = match (true) {
            ! $anyPolicy => 1,
            $policy === null => 2,          // drafted but never approved
            ! $attested && $accountable === 0 => 3,
            ! $attested || $accountable === 0 => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => (int) $anyPolicy + (int) ($policy !== null) + (int) $attested + (int) ($accountable > 0),
            'expected' => 4,
            'rationale' => sprintf(
                'Policy %s; attested for %d: %s; %d accountable owner(s) named on the programme.',
                $policy !== null ? 'approved' : ($anyPolicy ? 'drafted but not approved' : 'absent'),
                now()->year, $attested ? 'yes' : 'no', $accountable
            ),
            'summary' => ['policy_approved' => $policy !== null, 'attested_this_year' => $attested, 'accountable' => $accountable],
        ];
    }

    /** Clause 6 — measurable objectives with a baseline and a KRI. */
    private function scorePlanning(?Programme $programme): array
    {
        $objectives = Objective::query()->when($programme, fn ($q) => $q->where('programme_id', $programme->getKey()));
        $total = (clone $objectives)->count();

        if ($total === 0) {
            return $this->none('No business continuity objectives have been set, so clause 6.2 has nothing to evidence.');
        }

        $measurable = (clone $objectives)->whereNotNull('target_value')->count();
        $baselined = (clone $objectives)->whereNotNull('baseline_value')->count();
        $kriLinked = (clone $objectives)->whereNotNull('key_risk_indicator_id')->count();

        $score = match (true) {
            $measurable === 0 => 2,
            $measurable < $total => 3,
            $baselined < $total => 4,
            // Level 5 is measured objectives with a baseline AND a live KRI
            // feed, which is the only arrangement where progress is observed
            // rather than asserted at review time.
            $kriLinked < $total => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $measurable,
            'expected' => $total,
            'rationale' => sprintf(
                '%d of %d objective(s) carry a measurable target, %d a baseline, %d a linked KRI.',
                $measurable, $total, $baselined, $kriLinked
            ),
            'summary' => compact('total', 'measurable', 'baselined', 'kriLinked'),
        ];
    }

    /** Clause 7 — competence, evidenced. */
    private function scoreSupport(): array
    {
        $records = TrainingRecord::query()->count();

        if ($records === 0) {
            return $this->none('No training or competency records exist, so clauses 7.2 and 7.3 have nothing to evidence.');
        }

        $completed = TrainingRecord::query()->whereNotNull('completed_at')->count();
        $assessed = TrainingRecord::query()->where('competency_assessed', true)->count();
        $current = TrainingRecord::query()
            ->whereNotNull('completed_at')
            ->where(fn ($q) => $q->whereNull('next_due_date')->orWhereDate('next_due_date', '>=', now()->toDateString()))
            ->count();

        $score = match (true) {
            $completed === 0 => 2,
            // Attendance evidences 7.3 and does NOT evidence 7.2. A register
            // that stops at attendance proves people were in the room.
            $assessed === 0 => 3,
            $current < $completed => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $completed,
            'expected' => $records,
            'rationale' => sprintf(
                '%d of %d record(s) completed, %d with competence assessed (clause 7.2, not 7.3), %d still current.',
                $completed, $records, $assessed, $current
            ),
            'summary' => compact('records', 'completed', 'assessed', 'current'),
        ];
    }

    /** Clause 8.2 — BIA coverage of the process catalogue. */
    private function scoreAnalysis(): array
    {
        $processes = Process::query()->where('status', 'active')->count();

        if ($processes === 0) {
            return $this->none('The process catalogue is empty, so BIA coverage is undefined rather than zero.');
        }

        $withApproved = BiaAssessment::query()->where('status', 'approved')->distinct()->count('process_id');
        $withAny = BiaAssessment::query()->distinct()->count('process_id');

        // A BIA older than a year has stopped describing the business.
        $current = BiaAssessment::query()
            ->where('status', 'approved')
            ->where('approved_at', '>=', now()->subYear())
            ->distinct()->count('process_id');

        $coverage = $withApproved / $processes;

        $score = match (true) {
            $withAny === 0 => 1,
            $withApproved === 0 => 2,
            $coverage < 0.8 => 3,
            $current < $withApproved => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $withApproved,
            'expected' => $processes,
            'rationale' => sprintf(
                '%d of %d active process(es) have an approved BIA (%d%%); %d approved within the last year.',
                $withApproved, $processes, (int) round($coverage * 100), $current
            ),
            'summary' => compact('processes', 'withAny', 'withApproved', 'current'),
        ];
    }

    /** Clause 8.3 — a selected, approved strategy per critical process. */
    private function scoreStrategy(): array
    {
        $critical = Process::query()->where('status', 'active')->whereIn('criticality_tier', [1, 2])->count();

        if ($critical === 0) {
            return $this->none('No process has been tiered 1 or 2, so there is no strategy coverage to measure.');
        }

        $withStrategy = DB::table('bcms_strategies')
            ->whereNull('deleted_at')
            ->distinct()->count('process_id');
        $selected = DB::table('bcms_strategies')
            ->whereNull('deleted_at')->where('is_selected', true)
            ->distinct()->count('process_id');
        $approved = DB::table('bcms_strategies')
            ->whereNull('deleted_at')->where('is_selected', true)->where('approval_status', 'approved')
            ->distinct()->count('process_id');
        $gapClosed = DB::table('bcms_strategies')
            ->whereNull('deleted_at')->where('is_selected', true)
            ->where(fn ($q) => $q->whereNull('gap_vs_required_hours')->orWhere('gap_vs_required_hours', '<=', 0))
            ->distinct()->count('process_id');

        $score = match (true) {
            $withStrategy === 0 => 1,
            $selected === 0 => 2,
            $approved < $critical => 3,
            // Level 5 needs the selected strategy to actually MEET the required
            // RTO. An approved strategy with a known gap is a documented
            // failure to recover in time, not a mature one.
            $gapClosed < $approved => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $approved,
            'expected' => $critical,
            'rationale' => sprintf(
                '%d tier-1/2 process(es); %d have a strategy, %d selected, %d approved, %d meet the required RTO.',
                $critical, $withStrategy, $selected, $approved, $gapClosed
            ),
            'summary' => compact('critical', 'withStrategy', 'selected', 'approved', 'gapClosed'),
        ];
    }

    /** Clause 8.4 — plans, approved and inside their review date. */
    private function scorePlans(): array
    {
        $total = Plan::query()->where('plan_type', '!=', PlanType::Policy->value)->count();

        if ($total === 0) {
            return $this->none('No continuity plans exist, so plan currency is undefined rather than zero.');
        }

        $approved = Plan::query()->where('plan_type', '!=', PlanType::Policy->value)->where('status', 'approved')->count();
        $current = Plan::query()
            ->where('plan_type', '!=', PlanType::Policy->value)
            ->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('next_review_date')->orWhereDate('next_review_date', '>=', now()->toDateString()))
            ->count();
        $bound = DB::table('bcms_plan_sections')->where('is_overridden', false)->whereNotNull('source_binding')->distinct()->count('plan_id');

        $score = match (true) {
            $approved === 0 => 2,
            $current === 0 => 3,
            $current < $approved => 4,
            // Level 5 is plans whose content is BOUND to live BIA and call-tree
            // data, so they cannot silently drift from the analysis behind
            // them. That is the whole argument of Blueprint §2.4.
            $bound === 0 => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $current,
            'expected' => $total,
            'rationale' => sprintf(
                '%d plan(s); %d approved, %d inside their review date, %d with sections bound to live data.',
                $total, $approved, $current, $bound
            ),
            'summary' => compact('total', 'approved', 'current', 'bound'),
        ];
    }

    /** Clause 8.5 — the exercise programme, and whether it was actually delivered. */
    private function scoreExercises(): array
    {
        $programmes = ExerciseProgramme::query()->where('year', now()->year)->count();
        $planned = ExerciseOccurrence::query()->whereYear('scheduled_date', now()->year)->count();

        if ($programmes === 0 && $planned === 0) {
            return $this->none('No exercise programme exists for this year, so clause 8.5 has nothing to evidence.');
        }

        $completed = ExerciseOccurrence::query()->whereYear('scheduled_date', now()->year)->where('status', 'completed')->count();
        $missed = ExerciseOccurrence::query()->whereYear('scheduled_date', now()->year)->whereIn('status', ['missed', 'cancelled'])->count();
        $withAar = DB::table('bcms_aars')->whereNull('deleted_at')->where('status', 'final')->count();
        $approvedProgramme = ExerciseProgramme::query()->where('year', now()->year)->where('status', '!=', 'draft')->exists();

        $score = match (true) {
            $planned === 0 => 2,
            ! $approvedProgramme => 2,
            $completed === 0 => 3,
            // THE JUMP THIS WHOLE MODULE IS SOLD ON. A plan library nobody has
            // exercised stops at 3. Delivering the programme is 4.
            $missed > 0 || $withAar < $completed => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $completed,
            'expected' => $planned,
            'rationale' => sprintf(
                '%d occurrence(s) planned for %d, %d completed, %d missed or cancelled, %d with a final after-action report.',
                $planned, now()->year, $completed, $missed, $withAar
            ),
            'summary' => compact('planned', 'completed', 'missed', 'withAar', 'approvedProgramme'),
        ];
    }

    /** Clause 9 — evaluation: management review and internal audit. */
    private function scoreEvaluation(): array
    {
        $reviews = ManagementReview::query()->count();

        if ($reviews === 0) {
            return $this->none('No management review has been recorded, so clause 9.3 has nothing to evidence.');
        }

        $approved = ManagementReview::query()->where('status', 'approved')->count();
        $thisYear = ManagementReview::query()->where('status', 'approved')->whereYear('held_on', now()->year)->count();
        $withInputs = ManagementReview::query()->whereNotNull('inputs_captured_at')->count();
        $auditFindings = Finding::query()->where('source', 'audit')->count();

        $score = match (true) {
            $approved === 0 => 2,
            $thisYear === 0 => 3,
            $withInputs < $approved || $auditFindings === 0 => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $approved,
            'expected' => max(1, $reviews),
            'rationale' => sprintf(
                '%d management review(s), %d approved, %d held this year, %d with their clause 9.3 inputs captured; %d internal-audit finding(s).',
                $reviews, $approved, $thisYear, $withInputs, $auditFindings
            ),
            'summary' => compact('reviews', 'approved', 'thisYear', 'withInputs', 'auditFindings'),
        ];
    }

    /** Clause 10 — improvement: are findings actually being closed and verified? */
    private function scoreImprovement(): array
    {
        $findings = Finding::query()->count();

        if ($findings === 0) {
            return $this->none('No findings have been raised, so there is no improvement activity to measure. This is not a good score — it usually means nothing has been tested yet.');
        }

        $actions = CorrectiveAction::query()->count();
        $verified = CorrectiveAction::query()->where('status', CorrectiveActionStatus::Verified->value)->count();
        $overdue = CorrectiveAction::query()->where('status', CorrectiveActionStatus::Overdue->value)->count();
        $carried = CorrectiveAction::query()->whereNotNull('carried_to_occurrence_id')->count();

        $score = match (true) {
            $actions === 0 => 2,
            $verified === 0 => 3,
            $overdue > 0 => 3,
            // Level 5 is the ISO 22398 ladder actually turning: actions from one
            // exercise carried onto the next and closed there.
            $carried === 0 => 4,
            default => 5,
        };

        return [
            'score' => $score,
            'count' => $verified,
            'expected' => max(1, $actions),
            'rationale' => sprintf(
                '%d finding(s) and %d corrective action(s); %d verified, %d overdue, %d carried onto a later exercise.',
                $findings, $actions, $verified, $overdue, $carried
            ),
            'summary' => compact('findings', 'actions', 'verified', 'overdue', 'carried'),
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @return array{score: null, count: int, expected: int, rationale: string, summary: array<string, mixed>} */
    private function none(string $why): array
    {
        return ['score' => null, 'count' => 0, 'expected' => 0, 'rationale' => $why, 'summary' => []];
    }

    /**
     * @param  list<array{score: int, weight: float}>  $scored
     */
    private function weightedMean(array $scored): ?float
    {
        if ($scored === []) {
            return null;
        }

        $weight = array_sum(array_column($scored, 'weight'));

        if ($weight <= 0.0) {
            return null;
        }

        $total = 0.0;

        foreach ($scored as $entry) {
            $total += $entry['score'] * $entry['weight'];
        }

        return round($total / $weight, 2);
    }
}
