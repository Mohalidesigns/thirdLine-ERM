<?php

namespace Tests\Feature\Controls;

use App\Models\Control;
use App\Models\ControlTest;
use App\Policies\ControlPolicy;
use App\Policies\ControlTestPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3.4: ControlPolicy and ControlTestPolicy, including the two inline
 * Gate closures the latter absorbed.
 */
class ControlPoliciesTest extends ControlsTestCase
{
    #[Test]
    public function both_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(ControlPolicy::class, Gate::getPolicyFor(Control::class));
        $this->assertInstanceOf(ControlTestPolicy::class, Gate::getPolicyFor(ControlTest::class));
    }

    #[Test]
    public function the_module_no_longer_defines_its_abilities_as_inline_gates(): void
    {
        $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringNotContainsString("Gate::define('review-control-test'", $provider);
        $this->assertStringNotContainsString("Gate::define('resubmit-control-test'", $provider);
    }

    #[Test]
    public function the_workflow_engines_spelling_of_the_ability_still_resolves(): void
    {
        // WorkflowEngine::canAct() asks `can('review-control-test', $test)`
        // through ControlTestBinding::gate(). Laravel camel-cases a hyphenated
        // ability on a model, so it lands on ControlTestPolicy::reviewControlTest()
        // — which is why the binding did not have to change.
        $reviewer = $this->userWith([], 'reviewer@example.test');
        $test = $this->makeTest(['status' => 'pending_review', 'reviewer_id' => $reviewer->id]);

        $this->assertTrue($reviewer->can('review-control-test', $test));
        $this->assertTrue($reviewer->can('review', $test), 'and the policy method the screens use agrees');

        $stranger = $this->userWith([], 'stranger@example.test');
        $this->assertFalse($stranger->can('review-control-test', $test));
    }

    #[Test]
    public function review_is_the_assigned_reviewer_or_an_approver_role(): void
    {
        $reviewer = $this->userWith([], 'rev@example.test');
        $compliance = $this->userWith([], 'compliance@example.test', ['compliance-officer']);
        $bystander = $this->userWith(['control_test.review'], 'bystander@example.test');

        $test = $this->makeTest(['status' => 'pending_review', 'reviewer_id' => $reviewer->id]);

        $this->assertTrue($reviewer->can('review', $test), 'the assigned reviewer');
        $this->assertTrue($compliance->can('review', $test), 'an approver-class role');
        $this->assertFalse($bystander->can('review', $test), 'a colleague with the permission but neither role nor assignment');
    }

    #[Test]
    public function resubmission_is_the_tester_or_the_original_creator(): void
    {
        $tester = $this->userWith([], 'tester@example.test');
        $stranger = $this->userWith([], 'stranger2@example.test');

        $test = $this->makeTest(['status' => 'rejected', 'tester_id' => $tester->id]);

        $this->assertTrue($tester->can('resubmit', $test), 'the assigned tester');
        $this->assertTrue($this->actor->can('resubmit', $test), 'the original creator');
        $this->assertFalse($stranger->can('resubmit', $test));
    }

    #[Test]
    public function control_abilities_each_require_their_own_permission(): void
    {
        $viewer = $this->userWith(['control.view'], 'viewer@example.test');

        $this->assertTrue($viewer->can('viewAny', Control::class));
        $this->assertTrue($viewer->can('view', $this->control));
        $this->assertFalse($viewer->can('create', Control::class));
        $this->assertFalse($viewer->can('update', $this->control));
        $this->assertFalse($viewer->can('delete', $this->control));
        $this->assertFalse($viewer->can('linkRisk', $this->control), 'linking is an edit of the mappings');
    }

    #[Test]
    public function a_record_from_another_tenant_is_never_reachable(): void
    {
        $foreignTest = $this->foreignTest();

        $this->assertFalse($this->actor->can('view', $this->foreignControl));
        $this->assertFalse($this->actor->can('update', $this->foreignControl));
        $this->assertFalse($this->actor->can('view', $foreignTest));
        $this->assertFalse($this->actor->can('review', $foreignTest));
    }

    #[Test]
    public function the_detail_routes_404_across_tenants(): void
    {
        $this->actingAs($this->actor)->get('/risk/controls/'.$this->foreignControl->id)->assertNotFound();
        $this->actingAs($this->actor)->get('/risk/control-tests/'.$this->foreignTest()->id)->assertNotFound();
    }

    private function foreignTest(): ControlTest
    {
        return \App\Support\Tenancy\TenantContext::bypass(fn () => ControlTest::create([
            'organization_id' => $this->otherOrg->id,
            'control_id' => $this->foreignControl->id,
            'test_code' => 'CT-FOREIGN',
            'title' => 'Theirs',
            'test_type' => 'walkthrough',
            'tester_id' => $this->otherActor->id,
            'scheduled_date' => '2026-06-30',
            'status' => 'pending_review',
            'created_by' => $this->otherActor->id,
        ]), 'test fixture');
    }
}
