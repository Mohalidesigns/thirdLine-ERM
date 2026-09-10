<?php

namespace Tests\Feature\Register;

use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\RiskControlMapping;
use App\Support\Migration\Ported;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/** Phase 3.2: the four register pages, and the writes they post. */
class RiskPagesTest extends RegisterTestCase
{
    #[Test]
    public function every_ported_route_is_listed_in_the_registry(): void
    {
        // Both sidebars consult this list to decide whether a link crosses the
        // renderer boundary; a ported route missing from it links wrongly.
        foreach (['risk.register.create', 'risk.register.show', 'risk.register.edit'] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_create_page_renders_with_its_lookups(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.register.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Register/Create')
                ->has('categories', 1)
                ->has('businessUnits', 1)
                ->has('users')
                ->has('options.likelihood', 5)
                ->has('options.impactDimensions', 4)
                ->where('canDraftWithAi', false)
            );
    }

    #[Test]
    public function the_ai_card_is_hidden_when_the_feature_is_off(): void
    {
        // The Blade page drew the AI Risk Statement Builder unconditionally,
        // so a tenant without the feature got a button posting to a route
        // behind `feature:ai_intelligence` — a 404 on click.
        config()->set('features.ai_intelligence', false);

        $this->actingAs($this->actor)
            ->get(route('risk.register.create'))
            ->assertInertia(fn (Assert $page) => $page->where('canDraftWithAi', false));
    }

    #[Test]
    public function the_detail_page_renders_every_tab(): void
    {
        $this->attachControl($this->risk, $this->control, isKey: true);

        $this->actingAs($this->actor)
            ->get(route('risk.register.show', $this->risk))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Register/Show')
                ->where('risk.risk_code', $this->risk->risk_code)
                ->has('metrics')
                ->has('assessments')
                ->has('controls', 1)
                ->where('controls.0.is_key_control', true)
                ->has('treatmentPlans')
                ->has('kris')
                ->has('auditTrail')
                ->has('availableControls')
                ->where('can.update', true)
                ->where('can.delete', true)
                ->where('can.mapControl', true)
            );
    }

    #[Test]
    public function a_mapped_control_leaves_the_available_list(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.register.show', $this->risk))
            ->assertInertia(fn (Assert $page) => $page->has('availableControls', 1));

        $this->attachControl($this->risk, $this->control);

        $this->actingAs($this->actor)
            ->get(route('risk.register.show', $this->risk))
            ->assertInertia(fn (Assert $page) => $page->has('availableControls', 0));
    }

    #[Test]
    public function the_edit_page_seeds_the_form_from_the_risk(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.register.edit', $this->risk))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Register/Edit')
                ->where('risk.id', $this->risk->id)
                ->where('risk.title', $this->risk->title)
                ->where('risk.business_unit_id', $this->unit->id)
                ->has('options.statuses', 4)
            );
    }

    #[Test]
    public function the_index_still_renders_the_grid(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.register.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Register/Index')
                ->where('total', 1)
                ->has('ratingCounts')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function storing_a_risk_creates_it_with_an_audit_trail(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.register.store'), $this->validPayload())
            ->assertRedirect();

        $risk = Risk::where('title', 'Core banking outage')->firstOrFail();

        $this->assertSame($this->actor->id, $risk->created_by);
        $this->assertSame('Self-Identified', $risk->risk_source);
        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_id' => $risk->id,
            'action_type' => 'created',
            'changed_by' => $this->actor->id,
        ]);
    }

    #[Test]
    public function updating_a_risk_without_a_risk_source_keeps_the_stored_one(): void
    {
        // REGRESSION: `risks.risk_source` is NOT NULL and the controller wrote
        // `?? null` into it, so saving the edit form with Risk Source left
        // blank — its select offers an empty option — was a 500.
        $this->risk->update(['risk_source' => 'Audit Finding']);

        $this->actingAs($this->actor)
            ->put(route('risk.register.update', $this->risk), $this->validPayload([
                'status' => 'active',
                'risk_source' => null,
            ]))
            ->assertRedirect(route('risk.register.show', $this->risk));

        $this->assertSame('Audit Finding', $this->risk->fresh()->risk_source);
    }

    #[Test]
    public function deleting_a_risk_records_the_deletion_before_removing_it(): void
    {
        $id = $this->risk->id;

        $this->actingAs($this->actor)
            ->delete(route('risk.register.destroy', $this->risk))
            ->assertRedirect(route('risk.register.index'));

        $this->assertSoftDeleted('risks', ['id' => $id]);
        $this->assertDatabaseHas('risk_audit_trail', ['entity_id' => $id, 'action_type' => 'deleted']);
    }

    #[Test]
    public function mapping_a_control_attaches_it(): void
    {
        // REGRESSION: the route parameter is `{register}` and the action's
        // parameter was `$risk`, so nothing bound and every call 403'd on the
        // tenancy check against an empty model.
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), [
                'control_id' => $this->control->id,
                'is_key_control' => true,
                'control_weight' => 40,
                'mapping_rationale' => 'Primary preventive control.',
            ])
            ->assertRedirect(route('risk.register.show', $this->risk));

        $this->assertDatabaseHas('risk_control_mapping', [
            'risk_id' => $this->risk->id,
            'control_id' => $this->control->id,
            'is_key_control' => true,
        ]);
    }

    #[Test]
    public function mapping_the_same_control_twice_reports_rather_than_duplicates(): void
    {
        $payload = ['control_id' => $this->control->id];

        $this->actingAs($this->actor)->post(route('risk.register.map-control', $this->risk), $payload);
        $this->actingAs($this->actor)
            ->post(route('risk.register.map-control', $this->risk), $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, RiskControlMapping::where('risk_id', $this->risk->id)->count());
    }

    #[Test]
    public function the_audit_trail_tab_shows_the_writes_in_reverse_order(): void
    {
        RiskAuditTrail::create([
            'entity_type' => $this->risk->getMorphClass(),
            'entity_id' => $this->risk->id,
            'organization_id' => $this->organization->id,
            'action_type' => 'created',
            'field_changed' => 'all',
            'changed_by' => $this->actor->id,
            'changed_at' => now()->subDay(),
        ]);
        RiskAuditTrail::create([
            'entity_type' => $this->risk->getMorphClass(),
            'entity_id' => $this->risk->id,
            'organization_id' => $this->organization->id,
            'action_type' => 'updated',
            'field_changed' => 'multiple',
            'changed_by' => $this->actor->id,
            'changed_at' => now(),
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.register.show', $this->risk))
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditTrail', 2)
                ->where('auditTrail.0.action_type', 'updated')
                ->where('auditTrail.1.action_type', 'created')
            );
    }
}
