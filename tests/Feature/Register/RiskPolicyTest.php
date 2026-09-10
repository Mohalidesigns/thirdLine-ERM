<?php

namespace Tests\Feature\Register;

use App\Models\Risk;
use App\Policies\RiskPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/** Phase 3.2: RiskPolicy — permission first, then tenancy, then node scope. */
class RiskPolicyTest extends RegisterTestCase
{
    #[Test]
    public function the_policy_is_discovered_for_the_model(): void
    {
        $this->assertInstanceOf(RiskPolicy::class, Gate::getPolicyFor(Risk::class));
    }

    #[Test]
    public function each_ability_requires_its_own_permission(): void
    {
        $viewer = $this->userWith(['risk.view'], 'viewer@example.test');

        $this->assertTrue($viewer->can('view', $this->risk));
        $this->assertTrue($viewer->can('viewAny', Risk::class));
        $this->assertFalse($viewer->can('create', Risk::class));
        $this->assertFalse($viewer->can('update', $this->risk));
        $this->assertFalse($viewer->can('delete', $this->risk));
    }

    #[Test]
    public function a_user_with_no_risk_permissions_can_do_nothing(): void
    {
        $nobody = $this->userWith([], 'nobody@example.test');

        $this->assertFalse($nobody->can('viewAny', Risk::class));
        $this->assertFalse($nobody->can('view', $this->risk));
        $this->assertFalse($nobody->can('create', Risk::class));
    }

    #[Test]
    public function mapping_a_control_and_editing_attributes_ride_on_update(): void
    {
        $editor = $this->userWith(['risk.view', 'risk.edit'], 'editor@example.test');
        $viewer = $this->userWith(['risk.view'], 'viewer2@example.test');

        $this->assertTrue($editor->can('mapControl', $this->risk));
        $this->assertTrue($editor->can('updateAttributes', $this->risk));
        $this->assertFalse($viewer->can('mapControl', $this->risk));
        $this->assertFalse($viewer->can('updateAttributes', $this->risk));
    }

    #[Test]
    public function a_risk_from_another_tenant_is_never_reachable(): void
    {
        // Every permission, and still no: the organisation check runs after
        // the permission and before anything else.
        $this->assertFalse($this->actor->can('view', $this->foreignRisk));
        $this->assertFalse($this->actor->can('update', $this->foreignRisk));
        $this->assertFalse($this->actor->can('delete', $this->foreignRisk));
    }

    #[Test]
    public function the_detail_route_404s_for_a_risk_in_another_tenant(): void
    {
        // Route-model binding resolves through the tenancy scope, so the
        // record's existence is not itself the answer.
        $this->actingAs($this->actor)
            ->get('/risk/register/'.$this->foreignRisk->id)
            ->assertNotFound();
    }

    #[Test]
    public function the_edit_and_delete_routes_refuse_a_viewer(): void
    {
        $viewer = $this->userWith(['risk.view'], 'viewer3@example.test');

        $this->actingAs($viewer)->get(route('risk.register.edit', $this->risk))->assertForbidden();
        $this->actingAs($viewer)->delete(route('risk.register.destroy', $this->risk))->assertForbidden();
    }
}
