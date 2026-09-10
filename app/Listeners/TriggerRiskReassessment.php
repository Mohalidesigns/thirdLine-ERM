<?php

namespace App\Listeners;

use App\Events\TreatmentCompleted;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\AssessmentChainService;
use App\Services\NotificationService;
use App\Services\RiskScoringService;
use App\Services\Workflow\ModuleApprovals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A completed treatment plan raises a reassessment. It does not move the risk.
 *
 * The previous version of this listener multiplied `risks.residual_score` by a
 * flat percentage held in a column that does not exist, and wrote the result
 * straight onto the risk. Every line of it was wrong, and because strict-mode
 * Eloquent is off the missing column read as null, so the branch never ran and
 * nothing ever threw. Completing a treatment has therefore never moved a risk
 * score in this platform, and the fix is not to make that write work.
 *
 * Everywhere else in the codebase residual risk is DERIVED — from control
 * effectiveness, through the tenant's scoring profile — and reaches the risk
 * row only through an approved assessment (RiskAssessmentBinding::onApproved).
 * A listener that wrote residual_* behind an unapproved percentage is exactly
 * what WP-10a removed. So what a completion produces here is a RiskAssessment
 * carrying the plan's own claim about the residual it would deliver, submitted
 * into the same approval flow a human-authored reassessment uses. The number
 * moves when a reviewer approves it, or it does not move at all.
 *
 * The residual on that assessment is recorded as an OVERRIDE, not a
 * derivation, with the plan named in the justification. That is honest: it
 * comes from a plan's stated intent, not from an assessment of the controls
 * that plan installed. `residual_source` exists precisely so a reviewer can
 * tell those apart, and AssessmentChainService::deriveResidual() deliberately
 * returns null with nothing rated rather than pretending residual == inherent.
 * The reviewer opening the draft gets the risk's controls pre-populated by
 * AssessmentChainService::controlsFor() and can turn the claim into a
 * derivation by rating them; this listener will not rate them on their behalf.
 */
class TriggerRiskReassessment
{
    /** Assessment states that are open — a decision is still outstanding. */
    private const OPEN_STATUSES = ['draft', 'in_review'];

    /** Who is told a reassessment is waiting when no workflow is published. */
    private const OVERSIGHT_ROLES = ['risk-manager', 'chief-risk-officer'];

    /** Plan strategy vocabulary → RiskAssessment::TREATMENT_STRATEGIES. */
    private const STRATEGY_MAP = [
        'mitigate' => 'reduce',
        'reduce' => 'reduce',
        'transfer' => 'transfer',
        'share' => 'share',
        'avoid' => 'avoid',
        'accept' => 'accept',
    ];

    public function __construct(
        private RiskScoringService $scoring,
        private AssessmentChainService $chain,
        private ModuleApprovals $approvals,
    ) {}

    /**
     * Deliberately synchronous.
     *
     * The reassessment has to exist by the time the person who completed the
     * plan is redirected back to it, and the work is two inserts. It reads no
     * request state either — the assessor is the plan's owner, not
     * `auth()->user()` — so it behaves identically when the completion comes
     * from a console command or a seeder, and could be queued later without
     * changing what it records.
     */
    public function handle(TreatmentCompleted $event): void
    {
        $treatment = $event->treatment;
        $risk = $event->risk;
        $profile = $this->scoring->profileForRisk($risk);

        $expected = $this->expectedResidual($treatment, $profile);

        // GAP 1 — a plan with no expected residual has made no claim about
        // what it would deliver. There is nothing to reassess against and no
        // defensible number to invent, so nothing is raised and nothing is
        // written to the plan either. Logged, because "the treatment completed
        // and the risk did not move" is a question somebody will ask.
        if ($expected === null) {
            Log::info('Treatment completed with no expected residual; no reassessment raised.', [
                'treatment_plan_id' => $treatment->id,
                'treatment_code' => $treatment->treatment_code,
                'risk_id' => $risk->id,
                'risk_code' => $risk->risk_code,
            ]);

            return;
        }

        // GAP 2 — the reassessment has to carry the inherent side forward
        // intact, because approving it rewrites the risk's inherent columns
        // (RiskAssessmentBinding::onApproved, and then
        // RiskScoringService::updateRiskFromAssessment via AssessmentApproved,
        // which recomputes inherent impact from the assessment's DIMENSION
        // columns and scores a dimensionless assessment at zero). Where
        // neither the last approved assessment nor the risk row carries a
        // likelihood and at least one scored impact dimension, raising a
        // reassessment would either invent that breakdown or gut the risk's
        // inherent score on approval. Neither is acceptable, so nothing is
        // raised.
        $basis = $this->inherentBasis($risk, $profile);

        if ($basis === null) {
            Log::warning('Treatment completed but the risk has no inherent basis to reassess from; no reassessment raised.', [
                'treatment_plan_id' => $treatment->id,
                'risk_id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'expected_residual' => $expected,
            ]);

            $this->recordDelivered($treatment, $risk, $expected, null, 'no_inherent_basis');

            return;
        }

        $existing = RiskAssessment::where('organization_id', $risk->organization_id)
            ->where('risk_id', $risk->id)
            ->orderByDesc('id')
            ->get(['id', 'status', 'evidence_refs']);

        // Idempotency, and not a theoretical concern: this listener is
        // registered twice — once in EventServiceProvider::$listen and once by
        // Laravel's automatic discovery of app/Listeners, which matches on the
        // handle() type-hint — so every TreatmentCompleted delivers it twice.
        // (Every other explicitly-registered listener in this application has
        // the same duplication; `php artisan event:list` shows both bindings.)
        // A retried queue job or a re-dispatched event has to be harmless for
        // the same reason. An assessment already carrying this plan's marker
        // means the completion has been handled; the plan's own record is left
        // exactly as the first delivery wrote it.
        $marker = $this->marker($treatment);

        if ($existing->first(fn (RiskAssessment $row) => in_array($marker, (array) $row->evidence_refs, true)) !== null) {
            return;
        }

        // GAP 3 — two treatments on one risk completing in the same window.
        // One open reassessment per risk. A second open assessment would mean
        // two competing residual numbers under review for the same risk and no
        // defensible answer to "which one was approved" — the same reason
        // WorkflowEngine::openInstanceFor() refuses a second instance over one
        // subject. The already-open assessment is NOT amended: it may be a
        // person's draft or already in review, and a listener editing the
        // numbers underneath a live review is worse than not raising one. The
        // second plan's claim is not lost — it is written to the plan's own
        // actual_risk_reduction below, and the reviewer holding the open
        // assessment can take it into account.
        $open = $existing->first(fn (RiskAssessment $row) => in_array($row->status, self::OPEN_STATUSES, true));

        if ($open !== null) {
            Log::info('Treatment completed while a reassessment of this risk was already open; no second one raised.', [
                'treatment_plan_id' => $treatment->id,
                'risk_id' => $risk->id,
                'open_assessment_id' => $open->id,
                'open_assessment_status' => $open->status,
                'expected_residual' => $expected,
            ]);

            $this->recordDelivered($treatment, $risk, $expected, null, 'reassessment_already_open');

            return;
        }

        // A plan whose expected residual is no better than where the risk
        // already sits still goes to a reviewer — nothing moves without an
        // approval — but it is worth saying out loud, because a treatment that
        // raises residual risk is either a data-entry error or a finding.
        if ($risk->residual_score !== null && $expected['score'] >= (int) $risk->residual_score) {
            Log::warning('Completed treatment claims a residual no better than the risk already carries.', [
                'treatment_plan_id' => $treatment->id,
                'risk_id' => $risk->id,
                'current_residual_score' => (int) $risk->residual_score,
                'expected_residual_score' => $expected['score'],
            ]);
        }

        $assessment = DB::transaction(function () use ($treatment, $risk, $basis, $expected) {
            return $this->raise($treatment, $risk, $basis, $expected);
        });

        $this->recordDelivered($treatment, $risk, $expected, $assessment, null);
    }

    /* ------------------------------------------------------------------ */
    /*  Raising the reassessment */
    /* ------------------------------------------------------------------ */

    /**
     * Create the assessment and put it into review by the normal route.
     *
     * @param  array<string, mixed>  $basis
     * @param  array{likelihood: int, impact: int, score: int}  $expected
     */
    private function raise(TreatmentPlan $treatment, Risk $risk, array $basis, array $expected): RiskAssessment
    {
        $assessment = new RiskAssessment([
            'organization_id' => $risk->organization_id,
            'risk_id' => $risk->id,
            'assessment_type' => 'triggered',
            'assessment_date' => ($treatment->completion_date ?? now())->toDateString(),
            // Never auth()->id(): the completion can come from a console
            // command, and the person accountable for the plan is the right
            // assessor of what it delivered. RiskAssessmentBinding::ownerId()
            // reads this column.
            'assessor_id' => $treatment->owner_id ?? $risk->risk_owner_id ?? $treatment->created_by,
            'status' => 'draft',

            // The inherent side is carried forward unchanged. Completing a
            // treatment does not change how bad the risk would be without
            // controls; it changes what is left after them.
            'likelihood_score' => $basis['likelihood'],
            'impact_financial' => $basis['dimensions']['financial'] ?? null,
            'impact_operational' => $basis['dimensions']['operational'] ?? null,
            'impact_reputational' => $basis['dimensions']['reputational'] ?? null,
            'impact_regulatory' => $basis['dimensions']['regulatory'] ?? null,
            'impact_strategic' => $basis['dimensions']['strategic'] ?? null,
            'impact_score' => $basis['impact_score'],
            'overall_score' => $basis['overall_score'],
            'overall_rating' => $basis['overall_rating'],

            'treatment_strategy' => self::STRATEGY_MAP[strtolower((string) $treatment->strategy)] ?? null,
            'previous_assessment_id' => $basis['previous_assessment_id'],
            'evidence_refs' => [$this->marker($treatment)],
            'assessment_notes' => $this->notes($treatment, $basis),
        ]);

        $assessment->save();

        // Steps 7 and 8 through the service that owns them. With no control
        // ratings on this assessment, effectiveness is unknown and
        // deriveResidual() returns null by design — so the plan's expected
        // pair is applied as an override, with the justification the override
        // path requires. What it must NOT do is invent an effectiveness figure
        // so that a derivation can be manufactured.
        $this->chain->applyToAssessment($assessment, [
            'likelihood' => $expected['likelihood'],
            'impact' => $expected['impact'],
            'justification' => $this->justification($treatment, $expected),
        ]);

        // The same route the Submit button takes: the engine where the tenant
        // has published a definition, the status change and a notification to
        // the oversight roles where it has not. There is no second approval
        // path here — RiskAssessmentBinding::onApproved is what moves the risk,
        // however the decision is reached.
        $started = $this->approvals->submit(
            'risk_assessment_approval',
            $assessment,
            ['raised_by' => 'treatment_completion', 'treatment_plan_id' => $treatment->id],
            $treatment->owner,
        );

        if (! $started) {
            $this->approvals->markSubmitted($assessment);
            $this->notifyOversight($risk, $treatment, $assessment);
        }

        return $assessment->refresh();
    }

    /**
     * Tell the people who approve assessments that one is waiting.
     *
     * Only on the engine-less path — where a definition is published the
     * engine notifies whoever it assigns the task to. `whereHas` rather than
     * Spatie's `role()` scope: that scope throws when the role has never been
     * created, and a tenant without a risk-manager role should get no
     * notification, not an exception in the middle of completing a plan.
     */
    private function notifyOversight(Risk $risk, TreatmentPlan $treatment, RiskAssessment $assessment): void
    {
        $reference = 'ASS-'.str_pad((string) $assessment->id, 4, '0', STR_PAD_LEFT);

        $approvers = User::where('organization_id', $risk->organization_id)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::OVERSIGHT_ROLES))
            ->get();

        foreach ($approvers as $approver) {
            NotificationService::send(
                $risk->organization_id,
                $approver->id,
                'approval_request',
                "Risk reassessment awaiting review: {$reference}",
                "Treatment plan {$treatment->treatment_code} on risk {$risk->risk_code} has been completed. "
                    ."A reassessment carrying the plan's expected residual risk is awaiting review.",
                ['entity_type' => 'risk_assessment', 'entity_id' => $assessment->id],
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  What the plan claimed, and what it delivered */
    /* ------------------------------------------------------------------ */

    /**
     * The reference that ties a reassessment back to the completion that
     * raised it. Written to `evidence_refs`, which is the column for exactly
     * this — what this assessment is evidenced by — and read back to make a
     * repeated delivery of the event a no-op.
     */
    private function marker(TreatmentPlan $treatment): string
    {
        return 'treatment_plan:'.$treatment->id;
    }

    /**
     * The residual the plan said it would leave behind.
     *
     * Both axes or nothing: half a residual is not a claim. Values are clamped
     * to the tenant's matrix rather than rejected, for the reason
     * RiskScoringService gives — an organization that moves from 5×5 to 4×4
     * still has plans carrying a 5, and clamping is a no-op on the 1–5 range
     * TreatmentPlanController validates.
     *
     * @return array{likelihood: int, impact: int, score: int}|null
     */
    private function expectedResidual(TreatmentPlan $treatment, ScoringProfile $profile): ?array
    {
        $likelihood = $treatment->expected_residual_likelihood;
        $impact = $treatment->expected_residual_impact;

        if ($likelihood === null || $impact === null) {
            return null;
        }

        $likelihood = (int) max(1, min($profile->matrix_rows, (int) $likelihood));
        $impact = (int) max(1, min($profile->matrix_cols, (int) $impact));

        return [
            'likelihood' => $likelihood,
            'impact' => $impact,
            'score' => $this->scoring->calculateScore($likelihood, $impact, $profile),
        ];
    }

    /**
     * Record the completion on the plan itself.
     *
     * `treatment_plans.actual_risk_reduction` is the column shaped for this —
     * same keys as `expected_risk_reduction` — and nothing has ever written
     * it. It is filled when the plan completes rather than when the
     * reassessment is approved, because the baseline it is measured against is
     * the risk's residual AT COMPLETION, and that number is gone once an
     * approval has moved it.
     *
     * What is recorded is therefore the movement the completed plan CLAIMS,
     * from where the risk stood to where the plan said it would leave it, and
     * `confirmed` says so: it is false until a reviewer approves the
     * reassessment named in `risk_assessment_id`. Writing an unqualified
     * "actual" here would be the same lie the old listener told, one column
     * over. `not_raised_reason` records the cases where the completion could
     * not raise a reassessment at all, so a plan whose claim went nowhere is
     * visible rather than silent.
     *
     * @param  array{likelihood: int, impact: int, score: int}  $expected
     */
    private function recordDelivered(
        TreatmentPlan $treatment,
        Risk $risk,
        array $expected,
        ?RiskAssessment $assessment,
        ?string $notRaisedReason,
    ): void {
        $fromLikelihood = $risk->residual_likelihood === null ? null : (int) $risk->residual_likelihood;
        $fromImpact = $risk->residual_impact === null ? null : (int) $risk->residual_impact;

        $treatment->update([
            'actual_risk_reduction' => [
                // Same shape as expected_risk_reduction, so the two are
                // comparable without a translation layer.
                'likelihood_reduction' => $fromLikelihood === null ? null : $fromLikelihood - $expected['likelihood'],
                'impact_reduction' => $fromImpact === null ? null : $fromImpact - $expected['impact'],
                'from' => ['likelihood' => $fromLikelihood, 'impact' => $fromImpact],
                'to' => ['likelihood' => $expected['likelihood'], 'impact' => $expected['impact']],
                'basis' => 'expected_residual',
                'source' => 'treatment_completion',
                'risk_assessment_id' => $assessment?->id,
                'confirmed' => false,
                'not_raised_reason' => $notRaisedReason,
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The inherent side */
    /* ------------------------------------------------------------------ */

    /**
     * The inherent risk this reassessment carries forward.
     *
     * Three sources, most specific first: the risk's last approved assessment
     * (where the impact dimension breakdown actually lives), then the risk's
     * own `inherent_impact_*` dimension columns, then the plain scalar
     * `risks.inherent_impact`.
     *
     * That third source was deliberately excluded when this listener was
     * written, because approval used to recompute impact from the dimension
     * columns and scored a dimensionless assessment at ZERO — a reassessment
     * built on the scalar would have rated a Critical risk Low the moment it
     * was approved. RiskScoringService::statedImpact() now falls back to the
     * stated `impact_score` and skips the inherent block entirely when impact
     * is unstated, so the scalar is safe to carry forward.
     *
     * Keeping it excluded is not the conservative choice it looks like: most
     * risks in this schema carry a scalar inherent impact and no dimension
     * breakdown, so refusing them would mean treatment completion quietly
     * doing nothing for the majority of the register — the original bug in a
     * new costume.
     *
     * @return array{likelihood: int, dimensions: array<string, int|null>, impact_score: int, overall_score: int, overall_rating: string, previous_assessment_id: int|null}|null
     */
    private function inherentBasis(Risk $risk, ScoringProfile $profile): ?array
    {
        $previous = RiskAssessment::where('organization_id', $risk->organization_id)
            ->where('risk_id', $risk->id)
            ->where('status', 'approved')
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->first();

        $candidates = [];

        if ($previous !== null) {
            $candidates[] = [
                'likelihood' => $previous->likelihood_score,
                'dimensions' => [
                    'financial' => $previous->impact_financial,
                    'operational' => $previous->impact_operational,
                    'reputational' => $previous->impact_reputational,
                    'regulatory' => $previous->impact_regulatory,
                    'strategic' => $previous->impact_strategic,
                ],
                'previous_assessment_id' => $previous->id,
            ];
        }

        $candidates[] = [
            'likelihood' => $risk->inherent_likelihood,
            'dimensions' => [
                'financial' => $risk->inherent_impact_financial,
                'operational' => $risk->inherent_impact_operational,
                'reputational' => $risk->inherent_impact_reputational,
                'regulatory' => $risk->inherent_impact_regulatory,
                'strategic' => null,
            ],
            'previous_assessment_id' => $previous?->id,
        ];

        // Last resort: the scalar the register actually carries. No
        // dimensions, so `impact_score` is stated directly rather than
        // aggregated from a breakdown that does not exist.
        $candidates[] = [
            'likelihood' => $risk->inherent_likelihood,
            'dimensions' => [
                'financial' => null,
                'operational' => null,
                'reputational' => null,
                'regulatory' => null,
                'strategic' => null,
            ],
            'impact_score' => $risk->inherent_impact,
            'previous_assessment_id' => $previous?->id,
        ];

        foreach ($candidates as $candidate) {
            $likelihood = (int) ($candidate['likelihood'] ?? 0);
            $dimensions = array_map(
                fn ($value) => $value === null ? null : (int) $value,
                $candidate['dimensions'],
            );

            if ($likelihood < 1) {
                continue;
            }

            $hasDimensions = collect($dimensions)->filter(fn ($value) => $value !== null)->isNotEmpty();
            $statedImpact = (int) ($candidate['impact_score'] ?? 0);

            if (! $hasDimensions && $statedImpact < 1) {
                continue;
            }

            $impactScore = $hasDimensions
                ? $this->scoring->calculateImpact($dimensions, $risk->organization_id, $profile)
                : $statedImpact;

            if ($impactScore < 1) {
                continue;
            }

            $overall = $this->scoring->calculateScore($likelihood, $impactScore, $profile);

            return [
                'likelihood' => $likelihood,
                'dimensions' => $dimensions,
                'impact_score' => $impactScore,
                'overall_score' => $overall,
                'overall_rating' => $this->scoring->calculateRating($overall, $profile),
                'previous_assessment_id' => $candidate['previous_assessment_id'],
            ];
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Narrative */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $basis */
    private function notes(TreatmentPlan $treatment, array $basis): string
    {
        $reference = $treatment->treatment_code ?: 'TP-'.$treatment->id;
        $completed = optional($treatment->completion_date)->toDateString() ?? now()->toDateString();

        return "Raised automatically on completion of treatment plan {$reference} "
            ."(\"{$treatment->action_title}\") on {$completed}.\n\n"
            .'Inherent risk is carried forward from '
            .($basis['previous_assessment_id'] !== null
                ? 'assessment ASS-'.str_pad((string) $basis['previous_assessment_id'], 4, '0', STR_PAD_LEFT)
                : 'the risk register')
            .' — completing a treatment changes what is left after controls, not the inherent exposure. '
            .'The residual on this assessment is the plan\'s own expected residual and has not been derived '
            .'from control effectiveness; rate the risk\'s controls to replace it with a derivation before approving.';
    }

    /** @param array{likelihood: int, impact: int, score: int} $expected */
    private function justification(TreatmentPlan $treatment, array $expected): string
    {
        $reference = $treatment->treatment_code ?: 'TP-'.$treatment->id;

        return "Expected residual risk stated by completed treatment plan {$reference}: "
            ."likelihood {$expected['likelihood']} × impact {$expected['impact']} = {$expected['score']}. "
            .'Recorded as an override because it is the plan\'s stated intent, not a derivation from '
            .'the effectiveness of the controls the plan installed.';
    }
}
