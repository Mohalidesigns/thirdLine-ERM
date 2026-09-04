<?php

namespace Tests\Feature\Assessments;

use App\Models\RiskAssessment;
use App\Policies\RiskAssessmentPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3.3: RiskAssessmentPolicy, including the two inline Gate closures it
 * absorbed — `approve-risk-assessment` and `resubmit-risk-assessment`.
 */
class RiskAssessmentPolicyTest extends PolicyTestSupport
{
    #[Test]
    public function the_policy_is_discovered_for_the_model(): void
    {
        // Named for the model, not the module: a policy Laravel's convention
        // does not find authorises nothing, and every ability would silently
        // fall through to false.
        $this->assertInstanceOf(RiskAssessmentPolicy::class, Gate::getPolicyFor(RiskAssessment::class));
    }

    #[Test]
    public function the_module_no_longer_defines_its_abilities_as_inline_gates(): void
    {
        $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringNotContainsString("Gate::define('approve-risk-assessment'", $provider);
        $this->assertStringNotContainsString("Gate::define('resubmit-risk-assessment'", $provider);
    }

    #[Test]
    public function each_ability_requires_its_own_permission(): void
    {
        $viewer = $this->userWith(['assessment.view'], 'viewer@example.test');
        $assessment = $this->makeAssessment();

        $this->assertTrue($viewer->can('viewAny', RiskAssessment::class));
        $this->assertTrue($viewer->can('view', $assessment));
        $this->assertFalse($viewer->can('create', RiskAssessment::class));
        $this->assertFalse($viewer->can('update', $assessment));
        $this->assertFalse($viewer->can('submit', $assessment));
    }

    #[Test]
    public function approval_is_the_assigned_reviewer_or_a_risk_manager(): void
    {
        $reviewer = $this->userWith([], 'reviewer@example.test');
        $manager = $this->userWith([], 'manager@example.test', ['risk-manager']);
        $bystander = $this->userWith(['assessment.approve'], 'bystander@example.test');

        $assessment = $this->makeAssessment(['status' => 'in_review', 'reviewer_id' => $reviewer->id]);

        $this->assertTrue($reviewer->can('approve', $assessment), 'the assigned reviewer');
        $this->assertTrue($manager->can('approve', $assessment), 'a risk manager');
        $this->assertFalse($bystander->can('approve', $assessment), 'a colleague holding the permission but neither role nor assignment');

        // Rejecting is the same decision taken the other way.
        $this->assertSame($reviewer->can('approve', $assessment), $reviewer->can('reject', $assessment));
    }

    #[Test]
    public function only_the_original_assessor_may_resubmit(): void
    {
        $assessment = $this->makeAssessment(['status' => 'rejected']);
        $stranger = $this->userWith(['assessment.submit'], 'stranger@example.test');

        $this->assertTrue($this->actor->can('resubmit', $assessment));
        $this->assertFalse($stranger->can('resubmit', $assessment));
    }

    #[Test]
    public function the_lifecycle_rule_is_not_in_the_policy(): void
    {
        // WorkflowEngine::canAct() asks `approve` about tasks, and the screens
        // answer a wrong status with a flash message rather than a 403. If the
        // status check moved into the policy, both would change behaviour.
        $draft = $this->makeAssessment(['status' => 'draft']);
        // Holds the route's permission as well as the role, so what the
        // request meets is the controller's guard and not the middleware.
        $manager = $this->userWith(['assessment.approve'], 'manager2@example.test', ['risk-manager']);

        $this->assertTrue($manager->can('approve', $draft), 'the policy does not care about status');

        // The controller is what refuses it, and it says so rather than 403ing.
        $this->actingAs($manager)
            ->post(route('risk.assessments.approve', $draft))
            ->assertRedirect()
            ->assertSessionHas('error', 'Only in-review assessments can be approved.');
    }

    #[Test]
    public function an_assessment_from_another_tenant_is_never_reachable(): void
    {
        $foreign = $this->foreignAssessment();

        $this->assertFalse($this->actor->can('view', $foreign));
        $this->assertFalse($this->actor->can('update', $foreign));
        $this->assertFalse($this->actor->can('approve', $foreign));
    }

    #[Test]
    public function the_detail_route_404s_for_an_assessment_in_another_tenant(): void
    {
        $this->actingAs($this->actor)
            ->get('/risk/assessments/'.$this->foreignAssessment()->id)
            ->assertNotFound();
    }
}
