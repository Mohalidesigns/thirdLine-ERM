<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\User;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaReviewService;
use App\Services\Rcsa\RcsaTreatmentOverrideService;
use App\Services\Rcsa\RcsaWorkflowService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * §14 Q5 — the second signature on a treatment override.
 *
 * P3 shipped the override and made its justification mandatory. Nobody had to
 * agree with it: an assessor could turn TREAT into ACCEPT on a risk above
 * appetite, write a sentence, and file it.
 *
 * The setting is OFF by default and the first test pins that nothing changed.
 * Everything after it turns the setting on.
 */
class TreatmentOverrideApprovalTest extends ReviewTestCase
{
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approver = $this->userWith([
            'rcsa_assessment.view',
            'rcsa_assessment.review',
            'rcsa_assessment.validate',
            'rcsa_assessment.approve_override',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The switch is off */
    /* ------------------------------------------------------------------ */

    /**
     * The default is exactly P3's behaviour, and this is the test that says so.
     */
    #[Test]
    public function with_approval_off_an_override_takes_effect_immediately(): void
    {
        $assessment = $this->scoredAssessment();
        $line = $this->overrideOn($assessment, 'accept');

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_NONE, $line->treatment_override_status);
        $this->assertSame('accept', $line->effectiveTreatment());
        $this->assertNull($line->treatment_override_requested_at);
    }

    /* ------------------------------------------------------------------ */
    /*  The switch is on */
    /* ------------------------------------------------------------------ */

    /**
     * A PENDING OVERRIDE IS NOT IN FORCE, and that is the whole point.
     *
     * If it were, the approval would be decorative: the departure would
     * already be on the export, the dashboard and the board pack, and the
     * approver would be ratifying something that had been true for a week.
     */
    #[Test]
    public function a_requested_override_does_not_take_effect_until_it_is_decided(): void
    {
        $this->requireApproval();

        $assessment = $this->scoredAssessment();
        $line = $this->overrideOn($assessment, 'accept');

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_PENDING, $line->treatment_override_status);
        $this->assertSame('accept', $line->treatment_override);

        // The calculated treatment is what is true until somebody agrees.
        $this->assertSame($line->risk_treatment, $line->effectiveTreatment());
        $this->assertNotSame('accept', $line->effectiveTreatment());
    }

    #[Test]
    public function approving_puts_it_in_force_with_a_name_and_a_time_against_it(): void
    {
        $this->requireApproval();

        $line = $this->overrideOn($this->scoredAssessment(), 'accept');

        $decided = app(RcsaTreatmentOverrideService::class)
            ->approve($line, $this->approver, 'Compensating control accepted by the committee.');

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_APPROVED, $decided->treatment_override_status);
        $this->assertSame('accept', $decided->effectiveTreatment());
        $this->assertSame($this->approver->id, $decided->treatment_override_decided_by);
        $this->assertNotNull($decided->treatment_override_decided_at);
    }

    #[Test]
    public function rejecting_leaves_the_calculated_treatment_standing(): void
    {
        $this->requireApproval();

        $line = $this->overrideOn($this->scoredAssessment(), 'accept');
        $calculated = $line->risk_treatment;

        $decided = app(RcsaTreatmentOverrideService::class)
            ->reject($line, $this->approver, 'The compensating control is not evidenced.');

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_REJECTED, $decided->treatment_override_status);
        $this->assertSame($calculated, $decided->effectiveTreatment());

        // The request itself is kept. An assessor has to be able to see what
        // they asked for and what they were told.
        $this->assertSame('accept', $decided->treatment_override);
        $this->assertSame('The compensating control is not evidenced.', $decided->treatment_override_decision_note);
    }

    /** An assessor told only "no" cannot act on it. */
    #[Test]
    public function a_rejection_without_a_reason_is_refused(): void
    {
        $this->requireApproval();

        $line = $this->overrideOn($this->scoredAssessment(), 'accept');

        $this->expectException(\InvalidArgumentException::class);

        app(RcsaTreatmentOverrideService::class)->reject($line, $this->approver, '   ');
    }

    /**
     * A second signature that can be the first one's is not a second signature.
     */
    #[Test]
    public function the_requester_cannot_decide_their_own_override(): void
    {
        $this->requireApproval();

        $line = $this->overrideOn($this->scoredAssessment(), 'accept');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be approved by the person who requested it');

        app(RcsaTreatmentOverrideService::class)->approve($line, $this->actor);
    }

    /* ------------------------------------------------------------------ */
    /*  What a change to the request does */
    /* ------------------------------------------------------------------ */

    /**
     * The approver agreed to ACCEPT, not to "whatever this assessor decides
     * next".
     */
    #[Test]
    public function changing_an_approved_override_puts_it_back_in_the_queue(): void
    {
        $this->requireApproval();

        $assessment = $this->scoredAssessment();
        $line = $this->overrideOn($assessment, 'accept');

        app(RcsaTreatmentOverrideService::class)->approve($line, $this->approver);

        $again = $this->overrideOn($assessment, 'mitigate');

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_PENDING, $again->treatment_override_status);
        $this->assertNull($again->treatment_override_decided_by);
        $this->assertNull($again->treatment_override_decided_at);
        $this->assertSame($again->risk_treatment, $again->effectiveTreatment());
    }

    /**
     * Otherwise an assessor could quietly un-approve an override by editing the
     * likelihood beside it.
     */
    #[Test]
    public function saving_an_unrelated_field_does_not_reset_an_approval(): void
    {
        $this->requireApproval();

        $assessment = $this->scoredAssessment();
        $line = $this->overrideOn($assessment, 'accept');

        app(RcsaTreatmentOverrideService::class)->approve($line, $this->approver);

        app(RcsaAssessmentService::class)->apply($line->refresh(), [
            'assessment_rationale' => 'Rewritten after the walkthrough.',
        ], $this->actor);

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_APPROVED, $line->refresh()->treatment_override_status);
        $this->assertSame('accept', $line->effectiveTreatment());
    }

    /** An override nobody is asking for any more must not block anything. */
    #[Test]
    public function clearing_the_override_withdraws_the_request(): void
    {
        $this->requireApproval();

        $assessment = $this->scoredAssessment();
        $this->overrideOn($assessment, 'accept');

        $line = $this->overrideOn($assessment, null);

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_NONE, $line->treatment_override_status);
        $this->assertNull($line->treatment_override_requested_at);
    }

    /* ------------------------------------------------------------------ */
    /*  The gate */
    /* ------------------------------------------------------------------ */

    /**
     * VALIDATION IS THE GATE, NOT SUBMISSION.
     *
     * Blocking the submission would deadlock — the approver's permission sits
     * with the second line, which the business unit is not — so the decision
     * belongs in front of the reviewer who is already reading the assessment.
     */
    #[Test]
    public function an_assessment_cannot_be_validated_with_an_override_awaiting_a_decision(): void
    {
        $this->requireApproval();

        $assessment = $this->submittedAssessment(risks: 2);
        $line = $assessment->lines()->first();

        $this->requestOverride($line, 'accept');

        $workflow = app(RcsaWorkflowService::class);

        try {
            $workflow->validate($assessment->refresh(), $this->approver);
            $this->fail('Validation should have been refused while an override was undecided.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('treatment override', $e->getMessage());
            $this->assertStringContainsString((string) $line->risk_no, $e->getMessage());
        }

        app(RcsaTreatmentOverrideService::class)->approve($line->refresh(), $this->approver);

        $validated = $workflow->validate($assessment->refresh(), $this->approver);

        $this->assertSame(RcsaAssessment::VALIDATED, $validated->status);
    }

    /** A submission is not blocked — only the acceptance at the far end is. */
    #[Test]
    public function submission_is_not_blocked_by_a_pending_override(): void
    {
        $this->requireApproval();

        $assessment = $this->submittedAssessment(risks: 1);

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->status);
    }

    /**
     * Counted separately from the reviewer's own accept/flag verdicts, so that
     * clearing one cannot look like clearing the other.
     */
    #[Test]
    public function the_review_summary_lists_what_is_awaiting_a_decision(): void
    {
        $this->requireApproval();

        $assessment = $this->submittedAssessment(risks: 2);
        $line = $assessment->lines()->first();

        $this->requestOverride($line, 'accept');

        $summary = app(RcsaReviewService::class)->summary($assessment->refresh());

        $this->assertCount(1, $summary['overrides_awaiting']);
        $this->assertSame((int) $line->id, $summary['overrides_awaiting'][0]['line_id']);
        $this->assertSame('accept', $summary['overrides_awaiting'][0]['to']);
    }

    /* ------------------------------------------------------------------ */
    /*  Over HTTP */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_reviewer_without_the_permission_cannot_decide_an_override(): void
    {
        $this->requireApproval();

        $assessment = $this->submittedAssessment(risks: 1);
        $line = $assessment->lines()->first();

        $this->requestOverride($line, 'accept');

        // $this->reviewer holds review, validate and return — but NOT
        // approve_override, which is the separation Q5 asks for.
        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.lines.override', [$assessment, $line]), [
                'decision' => RcsaAssessmentLine::OVERRIDE_APPROVED,
            ])
            ->assertForbidden();

        $this->assertSame(
            RcsaAssessmentLine::OVERRIDE_PENDING,
            $line->refresh()->treatment_override_status,
        );
    }

    #[Test]
    public function the_approver_can_decide_it_over_http(): void
    {
        $this->requireApproval();

        $assessment = $this->submittedAssessment(risks: 1);
        $line = $assessment->lines()->first();

        $this->requestOverride($line, 'accept');

        $this->actingAs($this->approver)
            ->from(route('rcsa.review.show', $assessment))
            ->post(route('rcsa.review.lines.override', [$assessment, $line]), [
                'decision' => RcsaAssessmentLine::OVERRIDE_REJECTED,
                'note' => 'Not evidenced.',
            ])
            ->assertRedirect();

        $this->assertSame(RcsaAssessmentLine::OVERRIDE_REJECTED, $line->refresh()->treatment_override_status);
    }

    /** The rejection note is required by the route as well as by the service. */
    #[Test]
    public function rejecting_over_http_without_a_note_is_a_validation_error(): void
    {
        $this->requireApproval();

        $assessment = $this->submittedAssessment(risks: 1);
        $line = $assessment->lines()->first();

        $this->requestOverride($line, 'accept');

        $this->actingAs($this->approver)
            ->from(route('rcsa.review.show', $assessment))
            ->post(route('rcsa.review.lines.override', [$assessment, $line]), [
                'decision' => RcsaAssessmentLine::OVERRIDE_REJECTED,
            ])
            ->assertSessionHasErrors('note');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Write an override straight onto a SUBMITTED line and request approval.
     *
     * A submitted line is locked, so this cannot go through
     * RcsaAssessmentService::apply() — which is the point of these fixtures:
     * the override was requested before submission and is still waiting when
     * the reviewer picks the assessment up.
     */
    private function requestOverride(RcsaAssessmentLine $line, string $treatment): void
    {
        $line->forceFill([
            'treatment_override' => $treatment,
            'treatment_override_reason' => 'A compensating manual check runs daily.',
        ])->save();

        app(RcsaTreatmentOverrideService::class)->request($line, $this->actor);
    }

    private function requireApproval(): void
    {
        $this->organization->forceFill([
            'settings' => array_replace_recursive(
                (array) $this->organization->settings,
                ['rcsa' => ['treatment_override_approval_required' => true]],
            ),
        ])->save();
    }

    /** A cycle whose Retail assessment is scored and still open for edits. */
    private function scoredAssessment(): RcsaAssessment
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();

        $this->scoreAll($assessment, aboveAppetite: true);

        return $assessment->refresh();
    }

    private function overrideOn(RcsaAssessment $assessment, ?string $treatment): RcsaAssessmentLine
    {
        $line = $assessment->lines()->first();

        app(RcsaAssessmentService::class)->apply($line, [
            'treatment_override' => $treatment,
            'treatment_override_reason' => $treatment === null
                ? null
                : 'A compensating manual check runs daily.',
        ], $this->actor);

        return $line->refresh();
    }
}
