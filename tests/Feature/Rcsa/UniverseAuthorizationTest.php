<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use App\Policies\Rcsa\RcsaRegisterRiskPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Who may do what to the universe, and what the flag hides.
 */
class UniverseAuthorizationTest extends UniverseTestCase
{
    /**
     * A policy in the wrong namespace is not an error — every ability
     * silently returns false, which reads as a permissions problem and is not
     * one. This is the assertion that would have caught it.
     */
    #[Test]
    public function the_policy_is_discovered_for_the_model(): void
    {
        $this->assertInstanceOf(
            RcsaRegisterRiskPolicy::class,
            Gate::getPolicyFor(RcsaRegisterRisk::class)
        );
    }

    #[Test]
    public function publishing_is_a_separate_authority_from_editing(): void
    {
        // The plan's Risk Champion: knows the unit's risks, enters them, and
        // does not decide what goes into the assessment (§14 Q8).
        $champion = $this->userWith(['rcsa_universe.view', 'rcsa_universe.create', 'rcsa_universe.update']);
        $risk = $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        $this->actingAs($champion)
            ->put(route('rcsa.universe.update', $risk), $this->riskPayload(['risk_no' => 'RETAIL-R1']))
            ->assertSessionHasNoErrors();

        $this->actingAs($champion)
            ->post(route('rcsa.universe.publish'), ['ids' => [$risk->id]])
            ->assertForbidden();

        $this->assertSame(RcsaRegisterRisk::DRAFT, $risk->fresh()->status);
    }

    #[Test]
    public function each_write_route_refuses_a_caller_without_its_permission(): void
    {
        $risk = $this->makeRisk(['risk_no' => 'RETAIL-R1']);
        $viewer = $this->userWith(['rcsa_universe.view']);

        $this->actingAs($viewer)->post(route('rcsa.universe.store'), $this->riskPayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('rcsa.universe.update', $risk), $this->riskPayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('rcsa.universe.destroy', $risk))->assertForbidden();
        $this->actingAs($viewer)->post(route('rcsa.universe.duplicate', $risk), ['business_unit_id' => $this->treasury->id])->assertForbidden();
        $this->actingAs($viewer)->post(route('rcsa.universe.publish'), ['ids' => [$risk->id]])->assertForbidden();
        $this->actingAs($viewer)->post(route('rcsa.universe.retire'), ['ids' => [$risk->id]])->assertForbidden();
        $this->actingAs($viewer)->post(route('rcsa.universe.bulk-update'), ['ids' => [$risk->id], 'risk_category' => 'Market'])->assertForbidden();
        $this->actingAs($viewer)->post(route('rcsa.universe.processes.store'), [
            'business_unit_id' => $this->retail->id,
            'name' => 'Card Issuance',
        ])->assertForbidden();
    }

    #[Test]
    public function another_tenants_risk_is_not_found_rather_than_forbidden(): void
    {
        // Route-model binding runs inside the tenancy scope, so the row is not
        // there to bind — and a 404 does not confirm that it exists.
        $foreign = TenantContext::bypass(fn () => RcsaRegisterRisk::create([
            'organization_id' => $this->otherOrg->id,
            'business_unit_id' => $this->foreignUnit->id,
            'risk_no' => 'FOREIGN-R1',
            'potential_risk' => 'A risk belonging to an entirely different bank.',
            'risk_category' => 'Operational',
        ]));

        $this->actingAs($this->actor)
            ->put(route('rcsa.universe.update', $foreign->id), $this->riskPayload())
            ->assertNotFound();

        $this->actingAs($this->actor)
            ->delete(route('rcsa.universe.destroy', $foreign->id))
            ->assertNotFound();
    }

    #[Test]
    public function publishing_another_tenants_id_changes_nothing(): void
    {
        $foreign = TenantContext::bypass(fn () => RcsaRegisterRisk::create([
            'organization_id' => $this->otherOrg->id,
            'business_unit_id' => $this->foreignUnit->id,
            'risk_no' => 'FOREIGN-R1',
            'potential_risk' => 'A risk belonging to an entirely different bank.',
            'risk_category' => 'Operational',
        ]));

        // The id is accepted by validation — it is an integer — and simply
        // does not resolve inside the tenant scope, so nothing is published.
        $this->actingAs($this->actor)
            ->post(route('rcsa.universe.publish'), ['ids' => [$foreign->id]])
            ->assertRedirect();

        $this->assertSame(
            RcsaRegisterRisk::DRAFT,
            TenantContext::bypass(fn () => $foreign->fresh()->status)
        );
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('rcsa.universe.index'))->assertRedirect(route('login'));
    }
}
