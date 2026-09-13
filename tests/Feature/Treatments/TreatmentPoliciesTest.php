<?php

namespace Tests\Feature\Treatments;

use App\Models\TreatmentPlan;
use App\Policies\TreatmentPlanPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * TreatmentPlanPolicy (migration Phase 3.5), including the two abilities it
 * absorbed from AppServiceProvider.
 */
class TreatmentPoliciesTest extends TreatmentsTestCase
{
    #[Test]
    public function the_policy_is_discovered_for_the_model(): void
    {
        $this->assertInstanceOf(TreatmentPlanPolicy::class, Gate::getPolicyFor(TreatmentPlan::class));
    }

    #[Test]
    public function the_absorbed_gate_closures_are_gone(): void
    {
        // Both abilities must resolve through the policy now, not through a
        // Gate::define. An ability with neither would silently deny.
        $this->assertFalse(Gate::has('approve-treatment-plan'));
        $this->assertFalse(Gate::has('resubmit-treatment-plan'));
    }

    /**
     * The name the workflow engine uses. TreatmentPlanBinding::gate() returns
     * 'approve-treatment-plan', and WorkflowEngine::canAct() asks for it by
     * that name — Laravel must land it on approveTreatmentPlan().
     */
    #[Test]
    public function the_workflow_spelling_reaches_the_policy(): void
    {
        $approver = $this->userWith(['treatment.view'], 'cro@example.test', ['chief-risk-officer']);
        $bystander = $this->userWith(['treatment.view'], 'nobody@example.test', ['branch-manager']);

        $this->assertTrue($approver->can('approve-treatment-plan', $this->plan));
        $this->assertFalse($bystander->can('approve-treatment-plan', $this->plan));

        $this->assertTrue($this->plan->owner->can('resubmit-treatment-plan', $this->plan));
        $this->assertFalse($bystander->can('resubmit-treatment-plan', $this->plan));
    }

    #[Test]
    public function approving_needs_an_approver_role_not_a_permission(): void
    {
        // Holds treatment.approve, but is not a risk manager or CRO.
        $permissioned = $this->userWith(['treatment.view', 'treatment.approve'], 'perm@example.test', ['branch-manager']);
        // Holds the role but not the module permission — the workflow engine's
        // own actor looks like this, which is why the ability does not ask for
        // treatment.approve.
        $roled = $this->userWith([], 'rm@example.test', ['risk-manager']);

        $this->assertFalse($permissioned->can('approve', $this->plan));
        $this->assertTrue($roled->can('approve', $this->plan));
    }

    #[Test]
    public function rejecting_is_the_same_decision_as_approving(): void
    {
        $approver = $this->userWith([], 'rm2@example.test', ['risk-manager']);
        $bystander = $this->userWith(['treatment.view'], 'nobody2@example.test', ['branch-manager']);

        $this->assertTrue($approver->can('reject', $this->plan));
        $this->assertFalse($bystander->can('reject', $this->plan));
    }

    #[Test]
    public function resubmitting_is_the_owner_or_the_creator(): void
    {
        $creator = $this->userWith(['treatment.view'], 'creator@example.test');
        $owner = $this->userWith(['treatment.view'], 'owner@example.test');
        $stranger = $this->userWith(['treatment.view'], 'stranger@example.test');

        $plan = $this->makePlan(['owner_id' => $owner->id, 'created_by' => $creator->id]);

        $this->assertTrue($owner->can('resubmit', $plan));
        $this->assertTrue($creator->can('resubmit', $plan));
        $this->assertFalse($stranger->can('resubmit', $plan));

        // Submitting a draft is the same set.
        $this->assertTrue($owner->can('submit', $plan));
        $this->assertFalse($stranger->can('submit', $plan));
    }

    /**
     * The lifecycle rule is deliberately NOT in the policy: a plan in the
     * wrong status is answered with a flash message, not a 403, and canAct()
     * asks this ability about tasks nobody has decided yet.
     */
    #[Test]
    public function approving_does_not_depend_on_the_status(): void
    {
        $approver = $this->userWith([], 'rm3@example.test', ['risk-manager']);

        foreach (['not_started', 'pending_review', 'approved', 'rejected'] as $status) {
            $plan = $this->makePlan(['status' => $status]);

            $this->assertTrue($approver->can('approve', $plan), "status {$status}");
        }
    }

    #[Test]
    public function every_ability_stops_at_the_tenant_boundary(): void
    {
        $foreignPlan = null;

        \ThirdLine\Platform\Tenancy\TenantContext::bypass(function () use (&$foreignPlan) {
            $foreignPlan = TreatmentPlan::create([
                'organization_id' => $this->otherOrg->id,
                'risk_id' => $this->foreignRisk->id,
                'treatment_code' => 'TP-FOREIGN',
                'strategy' => 'mitigate',
                'action_title' => 'Theirs',
                'action_description' => 'Another bank.',
                'owner_id' => $this->otherActor->id,
                'priority' => 'high',
                'status' => 'pending_review',
                'target_date' => now()->addDays(30)->toDateString(),
                'created_by' => $this->otherActor->id,
            ]);
        }, 'test fixture');

        $approver = $this->userWith(['treatment.view', 'treatment.edit', 'treatment.delete'], 'rm4@example.test', ['risk-manager']);

        foreach (['view', 'update', 'delete', 'approve', 'reject', 'resubmit', 'comment'] as $ability) {
            $this->assertFalse($approver->can($ability, $foreignPlan), $ability);
        }
    }

    #[Test]
    public function viewing_and_editing_ask_for_their_own_permissions(): void
    {
        $viewer = $this->userWith(['treatment.view'], 'viewer@example.test');
        $editor = $this->userWith(['treatment.view', 'treatment.edit'], 'editor@example.test');
        $nobody = $this->userWith([], 'none@example.test');

        $this->assertTrue($viewer->can('view', $this->plan));
        $this->assertTrue($viewer->can('viewAny', TreatmentPlan::class));
        $this->assertFalse($viewer->can('update', $this->plan));

        $this->assertTrue($editor->can('update', $this->plan));
        $this->assertFalse($editor->can('delete', $this->plan));

        $this->assertFalse($nobody->can('view', $this->plan));
        $this->assertFalse($nobody->can('create', TreatmentPlan::class));
    }
}
