<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\User;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaSubmissionService;
use Illuminate\Support\Facades\Storage;

/**
 * Fixtures for the P5 tests: a cycle with a submitted assessment, and a
 * REVIEWER WHO IS NOT THE ASSESSOR.
 *
 * That second part is not incidental. §9 is a two-person control, the policy
 * enforces it, and a test suite whose reviewer and assessor are the same user
 * would be asserting against a screen no reviewer can reach.
 */
abstract class ReviewTestCase extends CycleTestCase
{
    protected User $reviewer;

    /** @var list<string> */
    protected const REVIEW_PERMISSIONS = [
        'rcsa_assessment.view',
        'rcsa_assessment.review',
        'rcsa_assessment.validate',
        'rcsa_assessment.return',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->grant(['rcsa_assessment.submit']);

        $this->reviewer = $this->userWith(self::REVIEW_PERMISSIONS);
    }

    /**
     * Score every line of an assessment, above or within appetite.
     *
     * 5 × 5 Not Achieved lands at 18.75 — VERY HIGH, above appetite.
     * 1 × 1 Fully Achieved lands at 0.00 — VERY LOW, within it.
     */
    protected function scoreAll(RcsaAssessment $assessment, bool $aboveAppetite = false): void
    {
        $service = app(RcsaAssessmentService::class);

        foreach ($assessment->lines()->get() as $line) {
            $service->apply($line, $aboveAppetite
                ? ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved']
                : ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved'],
                $this->actor);

            if ($aboveAppetite) {
                $line->actionPlans()->create([
                    'organization_id' => $this->organization->id,
                    'control_to_implement' => 'Introduce a four-eyes check before release.',
                    'owner_id' => $this->actor->id,
                    'target_date' => now()->addMonths(3)->toDateString(),
                    'status' => RcsaActionPlan::OPEN,
                ]);
            }
        }
    }

    /**
     * A cycle whose Retail assessment carries `$risks` scored lines and has
     * been submitted by the assessor.
     */
    protected function submittedAssessment(int $risks = 3, bool $aboveAppetite = false): RcsaAssessment
    {
        for ($i = 1; $i <= $risks; $i++) {
            $this->publishedRisk(['risk_no' => "RETAIL-R{$i}"]);
        }

        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();

        $this->scoreAll($assessment, $aboveAppetite);

        app(RcsaSubmissionService::class)->submit($assessment, $this->actor);

        return $assessment->refresh();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, RcsaAssessmentLine> */
    protected function linesOf(RcsaAssessment $assessment)
    {
        return $assessment->lines()->get();
    }
}
