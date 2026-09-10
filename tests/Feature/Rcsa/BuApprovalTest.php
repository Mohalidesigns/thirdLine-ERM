<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Services\Rcsa\RcsaWorkflowService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §9.1's optional business-unit-head step, and §14 Q6.
 *
 * OFF BY DEFAULT, and the first test says so. A bank that has not asked for the
 * step must not find its assessments waiting in a state nobody knows to look
 * at — which is exactly what a setting defaulting the wrong way would do.
 */
class BuApprovalTest extends ReviewTestCase
{
    private function enableBuApproval(): void
    {
        $this->organization->forceFill([
            'settings' => array_merge((array) $this->organization->settings, [
                'rcsa' => ['bu_approval_required' => true],
            ]),
        ])->save();
    }

    #[Test]
    public function the_step_ships_off_and_submission_goes_straight_to_orm(): void
    {
        $this->assertFalse(app(RcsaWorkflowService::class)->requiresBuApproval($this->organization));

        $assessment = $this->submittedAssessment(risks: 1);

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->status);
    }

    #[Test]
    public function with_the_step_on_it_waits_for_the_head_before_reaching_orm(): void
    {
        $this->enableBuApproval();

        $head = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.approve']);
        $this->retail->forceFill(['head_id' => $head->id])->save();

        $assessment = $this->submittedAssessment(risks: 1);

        $this->assertSame(RcsaAssessment::BU_APPROVAL, $assessment->status);

        // Not in the ORM's queue yet — the business has not finished signing
        // off, and putting it there would have reviewers open unfinished work.
        $this->assertNotContains(
            $assessment->id,
            array_column(app(\App\Services\Rcsa\RcsaReviewService::class)->queue(), 'id'),
        );

        // The head was told, and nobody else.
        $this->assertSame(
            1,
            DB::table('notifications_log')
                ->where('type', 'rcsa.assessment.awaiting_approval')
                ->where('user_id', $head->id)
                ->count(),
        );

        // Lines lock at FILING, not at approval: the head reviews the same
        // frozen document the ORM will.
        $this->assertSame(0, $assessment->lines()->whereNull('locked_at')->count());
        $this->assertNotNull($assessment->snapshot_path);

        $this->actingAs($head)
            ->post(route('rcsa.assessments.approve', $assessment))
            ->assertSessionHasNoErrors();

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->status);
        $this->assertContains(
            $assessment->id,
            array_column(app(\App\Services\Rcsa\RcsaReviewService::class)->queue(), 'id'),
        );
    }

    #[Test]
    public function nobody_approves_their_own_submission(): void
    {
        $this->enableBuApproval();

        // The assessor holds the approval permission too — which a small unit
        // plausibly would.
        $this->grant(['rcsa_assessment.approve']);

        $assessment = $this->submittedAssessment(risks: 1);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.approve', $assessment))
            ->assertForbidden();

        $this->assertSame(RcsaAssessment::BU_APPROVAL, $assessment->fresh()->status);
    }

    /**
     * The head has no per-line challenge, so their return is wholesale — and
     * that asymmetry with the ORM's return is deliberate.
     */
    #[Test]
    public function the_heads_return_reopens_everything_because_they_flagged_nothing(): void
    {
        $this->enableBuApproval();

        $head = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.approve']);

        $assessment = $this->submittedAssessment(risks: 3);

        $this->actingAs($head)
            ->post(route('rcsa.assessments.reject', $assessment), [
                'reason' => 'The operational losses last quarter are not reflected in these ratings at all.',
            ])
            ->assertSessionHasNoErrors();

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::RETURNED, $assessment->status);
        $this->assertSame(3, $assessment->lines()->whereNull('locked_at')->count());
        $this->assertStringContainsString('operational losses', (string) $assessment->returned_reason);
    }

    #[Test]
    public function a_head_cannot_send_it_back_without_saying_why(): void
    {
        $this->enableBuApproval();

        $head = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.approve']);
        $assessment = $this->submittedAssessment(risks: 1);

        $this->actingAs($head)
            ->post(route('rcsa.assessments.reject', $assessment), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(RcsaAssessment::BU_APPROVAL, $assessment->fresh()->status);
    }

    /**
     * An approval step with no named approver still has to be visible. It waits
     * where somebody can find it rather than being forwarded as though it had
     * been approved.
     */
    #[Test]
    public function a_unit_with_no_head_still_waits_rather_than_being_forwarded(): void
    {
        $this->enableBuApproval();

        $this->assertNull($this->retail->fresh()->head_id);

        $assessment = $this->submittedAssessment(risks: 1);

        $this->assertSame(RcsaAssessment::BU_APPROVAL, $assessment->status);
        $this->assertSame(
            0,
            DB::table('notifications_log')->where('type', 'rcsa.assessment.awaiting_approval')->count(),
        );
    }
}
