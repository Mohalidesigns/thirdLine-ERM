<?php

namespace App\Services\Bcms\Bia;

use App\Enums\Bcms\BiaAssessmentStatus;
use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\BiaImpact;
use App\Models\Bcms\Process;
use App\Services\Bcms\Plans\PlanDriftDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Filling in, submitting and approving one business impact assessment.
 *
 * APPROVAL FIXES THE NUMBERS EVERYTHING DOWNSTREAM IS MEASURED AGAINST — the
 * process's criticality tier, every strategy's gap, the DR tier, the regulatory
 * return. That is why approving is a separate permission from completing, why
 * an approved assessment is immutable, and why the approver may not be the
 * assessor. A unit that approves its own impact analysis has not had one
 * reviewed, which is the same argument RCSA settled about self-assessment.
 *
 * THE BLOCKING RULES ARE CHECKED HERE, NOT ONLY IN THE FORM REQUEST. A form
 * request guards a screen; this service is also reached from the AI drafter and
 * from a seeder, and an assessment that could be submitted with an RTO longer
 * than its MTPD through any of those paths is a validator that does not
 * validate.
 *
 * APPROVING WRITES THE CRITICALITY TIER ONTO THE PROCESS. `bcms_processes.
 * criticality_tier` is stored rather than derived precisely so it cannot drift
 * when somebody edits a draft impact score, and this is the one path that sets
 * it (Phase 1's Process docblock says so).
 */
class BiaAssessmentService
{
    public function __construct(
        private BiaValidator $validator,
        private MtpdDeriver $deriver,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function start(Process $process, ?int $assessorId = null, ?int $campaignId = null, array $attributes = []): BiaAssessment
    {
        return BiaAssessment::query()->create(array_merge([
            'process_id' => $process->getKey(),
            'campaign_id' => $campaignId,
            'assessor_id' => $assessorId ?? $process->owner_id,
            'status' => BiaAssessmentStatus::Draft->value,
            'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
            'created_by' => auth()->id(),
        ], $attributes));
    }

    /**
     * Save the recovery objectives.
     *
     * Re-derives the proposed MTPD from the grid on every save, because the
     * assessor may have just changed a score — and the proposal is only useful
     * beside the answer if it is current.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function save(BiaAssessment $assessment, array $attributes, ?int $userId = null): BiaAssessment
    {
        $this->assertEditable($assessment);

        $assessment->fill($attributes);

        if ($assessment->status === BiaAssessmentStatus::Draft) {
            $assessment->status = BiaAssessmentStatus::InProgress;
        }

        $assessment->updated_by = $userId ?? auth()->id();
        $assessment->save();

        return $this->deriver->apply($assessment->refresh());
    }

    /**
     * Score one cell of the impact grid.
     *
     * A financial amount on a non-monetary category is REFUSED rather than
     * stored and ignored. Reputational damage has no naira figure, and a grid
     * that accepts one produces a total somebody will later put in a board pack
     * (development standard §5).
     */
    public function scoreImpact(
        BiaAssessment $assessment,
        ImpactCategory $category,
        ImpactHorizon $horizon,
        ?int $severity,
        ?int $financialAmountMinor = null,
        ?string $narrative = null,
    ): BiaImpact {
        $this->assertEditable($assessment);

        if ($severity !== null && ($severity < 1 || $severity > 5)) {
            throw new InvalidArgumentException('An impact severity is 1 to 5.');
        }

        if ($financialAmountMinor !== null && ! $category->isMonetary()) {
            throw new InvalidArgumentException(sprintf(
                '%s impact has no monetary amount. Score its severity and describe it; a naira figure against it '
                .'would be a number nothing computed.',
                ucfirst($category->value)
            ));
        }

        $impact = BiaImpact::query()->updateOrCreate(
            [
                'assessment_id' => $assessment->getKey(),
                'impact_category' => $category->value,
                'horizon' => $horizon->value,
            ],
            [
                'severity_score' => $severity,
                'financial_amount_minor' => $financialAmountMinor,
                'currency' => $financialAmountMinor === null ? null : 'NGN',
                'narrative' => $narrative,
            ]
        );

        $this->deriver->apply($assessment->refresh());

        return $impact;
    }

    /**
     * Submit for review.
     *
     * @throws InvalidArgumentException listing every blocking rule that failed
     */
    public function submit(BiaAssessment $assessment, ?int $userId = null): BiaAssessment
    {
        $this->assertEditable($assessment);

        $result = $this->validator->check($assessment);

        if ($result['blocking'] !== []) {
            throw new InvalidArgumentException(
                "This assessment cannot be submitted yet:\n · ".implode(
                    "\n · ",
                    array_column($result['blocking'], 'message')
                )
            );
        }

        if ($assessment->rto_hours === null || $assessment->mtpd_hours === null) {
            throw new InvalidArgumentException(
                'An assessment needs both a maximum tolerable period of disruption and a recovery time objective '
                .'before it can be submitted — they are what every plan downstream is built against.'
            );
        }

        $assessment->update([
            'status' => BiaAssessmentStatus::Submitted->value,
            'submitted_at' => now(),
            'updated_by' => $userId ?? auth()->id(),
        ]);

        return $assessment->refresh();
    }

    /**
     * Approve, fixing the numbers and the process's criticality tier.
     *
     * THE APPROVER MAY NOT BE THE ASSESSOR, and — for an AI-drafted assessment —
     * may not be a machine at all. Standing rule 4: nothing is approved without
     * a human action recorded, and `approved_by` is that record.
     */
    public function approve(BiaAssessment $assessment, int $approverId, ?int $criticalityTier = null): BiaAssessment
    {
        if ($assessment->status !== BiaAssessmentStatus::Submitted) {
            throw new InvalidArgumentException('Only a submitted assessment can be approved.');
        }

        if ($approverId === (int) $assessment->assessor_id) {
            throw new InvalidArgumentException(
                'A business impact assessment must be approved by somebody other than the assessor. A unit that '
                .'approves its own impact analysis has not had one reviewed.'
            );
        }

        $result = $this->validator->check($assessment);

        if ($result['blocking'] !== []) {
            throw new InvalidArgumentException(
                "This assessment cannot be approved:\n · ".implode("\n · ", array_column($result['blocking'], 'message'))
            );
        }

        return DB::transaction(function () use ($assessment, $approverId, $criticalityTier) {
            $assessment->update([
                'status' => BiaAssessmentStatus::Approved->value,
                'approved_by' => $approverId,
                'approved_at' => now(),
                'updated_by' => $approverId,
            ]);

            $tier = $criticalityTier ?? $this->tierFrom($assessment);

            if ($tier !== null && $assessment->process !== null) {
                // The ONE path that writes a criticality tier. It is stored so
                // it cannot drift when somebody edits a draft impact score.
                $assessment->process->update(['criticality_tier' => $tier]);
            }

            $assessment->refresh();

            // A new approved RTO is exactly the change a bound plan section is
            // there to notice, and noticing it a whole day later — when the
            // nightly sweep runs — is the difference between the flag being
            // part of the approval conversation and being a surprise. The
            // nightly command still exists, for the changes the application
            // never sees: a vendor soft-deleted in TPRM, a site closed.
            //
            // Failure here must not undo the approval. A plan-review flag is
            // worth less than the assessment itself, and the sweep will catch
            // whatever this missed within a day.
            try {
                app(PlanDriftDetector::class)->sweep();
            } catch (\Throwable $e) {
                Log::warning('BIA approval could not refresh plan drift flags', [
                    'assessment_id' => $assessment->getKey(),
                    'message' => $e->getMessage(),
                ]);
            }

            return $assessment;
        });
    }

    public function returnForRework(BiaAssessment $assessment, int $reviewerId, string $reason): BiaAssessment
    {
        if ($assessment->status !== BiaAssessmentStatus::Submitted) {
            throw new InvalidArgumentException('Only a submitted assessment can be returned.');
        }

        if (blank($reason)) {
            throw new InvalidArgumentException('A returned assessment must say what is wrong with it.');
        }

        $assessment->update([
            'status' => BiaAssessmentStatus::Returned->value,
            'mbco_description' => $assessment->mbco_description,
            'updated_by' => $reviewerId,
        ]);

        return $assessment->refresh();
    }

    /* ------------------------------------------------------------------ */

    /**
     * The criticality tier the approved objectives imply.
     *
     * From the RTO, because the tier's whole meaning is how quickly the process
     * must come back. A critical service is floored at tier 2 whatever its RTO,
     * since a regulatory designation is not something an RTO can argue away.
     */
    private function tierFrom(BiaAssessment $assessment): ?int
    {
        $rto = $assessment->rto_hours === null ? null : (float) $assessment->rto_hours;

        if ($rto === null) {
            return null;
        }

        $tier = match (true) {
            $rto <= 4 => 1,
            $rto <= 24 => 2,
            $rto <= 72 => 3,
            default => 4,
        };

        if ($assessment->process?->is_critical_service) {
            $tier = min($tier, 2);
        }

        return $tier;
    }

    private function assertEditable(BiaAssessment $assessment): void
    {
        if (! $assessment->status->isEditable()) {
            throw new InvalidArgumentException(sprintf(
                'This assessment is %s and cannot be edited. An approved assessment is superseded by a new one, '
                .'because every plan downstream quotes its numbers.',
                $assessment->status->label()
            ));
        }
    }
}
