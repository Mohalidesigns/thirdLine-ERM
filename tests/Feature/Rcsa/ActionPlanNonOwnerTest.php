<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * RCSA-15 (verify): who may move somebody else's remediation plan.
 *
 * The E2E run saw a non-owner record progress on another user's plan. The rule
 * is: the OWNER, or the second line (`rcsa_actionplan.close`) — and nobody else,
 * however many other action-plan permissions they hold. Both halves are pinned
 * on the database, not the redirect.
 */
class ActionPlanNonOwnerTest extends ReviewTestCase
{
    private User $owner;

    private RcsaActionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.update']);

        $assessment = $this->submittedAssessment(risks: 1, aboveAppetite: true);
        $this->plan = $this->linesOf($assessment)->values()->first()->actionPlans()->sole();
        $this->plan->forceFill([
            'owner_id' => $this->owner->id,
            'target_date' => now()->addDays(20)->toDateString(),
            'progress_pct' => 10,
        ])->save();
    }

    #[Test]
    public function a_non_owner_without_the_second_line_permission_changes_nothing_on_any_of_the_three_actions(): void
    {
        $stranger = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.verify']);
        $before = $this->plan->fresh()->only(['progress_pct', 'status', 'target_date', 'extension_requested_by']);

        $this->actingAs($stranger)
            ->patch(route('rcsa.action-plans.progress', $this->plan), ['progress_pct' => 90])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('rcsa.action-plans.complete', $this->plan), [
                'completion_evidence' => 'Claimed by somebody who does not own this plan, in full.',
            ])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('rcsa.action-plans.extension', $this->plan), [
                'proposed_target_date' => now()->addMonths(2)->toDateString(),
                'extension_reason' => 'Asked for by somebody who does not own this plan at all.',
            ])
            ->assertForbidden();

        $this->assertEquals($before, $this->plan->fresh()->only(['progress_pct', 'status', 'target_date', 'extension_requested_by']));
    }

    #[Test]
    public function the_second_line_may_record_progress_on_somebody_elses_plan_and_it_is_written(): void
    {
        $orm = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.update', 'rcsa_actionplan.close']);

        $this->actingAs($orm)
            ->patch(route('rcsa.action-plans.progress', $this->plan), ['progress_pct' => 25])
            ->assertSessionHasNoErrors();

        $this->assertSame(25, (int) $this->plan->fresh()->progress_pct);
    }

    #[Test]
    public function a_holder_of_close_alone_without_the_update_permission_is_stopped_at_the_route(): void
    {
        $closer = $this->userWith(['rcsa_actionplan.view', 'rcsa_actionplan.close']);

        $this->actingAs($closer)
            ->patch(route('rcsa.action-plans.progress', $this->plan), ['progress_pct' => 60])
            ->assertForbidden();

        $this->assertSame(10, (int) $this->plan->fresh()->progress_pct);
    }
}
