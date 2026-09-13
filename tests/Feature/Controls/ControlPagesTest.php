<?php

namespace Tests\Feature\Controls;

use App\Models\Control;
use App\Models\RiskControlMapping;
use App\Support\Migration\Ported;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/** Phase 3.4: the control library pages, and the writes they post. */
class ControlPagesTest extends ControlsTestCase
{
    #[Test]
    public function every_ported_route_is_listed_in_the_registry(): void
    {
        foreach ([
            'risk.controls.create', 'risk.controls.show', 'risk.controls.edit',
            'risk.control-tests.dashboard', 'risk.control-tests.create',
            'risk.control-tests.show', 'risk.control-tests.edit',
        ] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_create_page_offers_the_vocabularies_the_validator_accepts(): void
    {
        // The selects are fed from the model constants the Form Requests
        // validate against, so a form cannot offer a value the server rejects.
        $props = $this->actingAs($this->actor)
            ->get(route('risk.controls.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Controls/Create')->has('risks')->has('users'))
            ->inertiaProps();

        $this->assertSame(Control::TYPES, $props['options']['types']);
        $this->assertSame(Control::NATURES, $props['options']['natures']);
        $this->assertSame(Control::FREQUENCIES, $props['options']['frequencies']);
        $this->assertSame(Control::EFFECTIVENESS_RATINGS, $props['options']['effectivenessRatings']);
        $this->assertSame(Control::STATUSES, $props['options']['statuses']);
    }

    #[Test]
    public function the_detail_page_draws_the_mappings_and_the_testing_history(): void
    {
        $this->attachControl($this->risk, $this->control, weight: 2.0, isKey: true);
        $this->makeTest(['status' => 'completed', 'result' => 'effective']);

        $this->actingAs($this->actor)
            ->get(route('risk.controls.show', $this->control))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Controls/Show')
                ->where('control.control_code', $this->control->control_code)
                ->has('linkedRisks', 1)
                ->where('linkedRisks.0.is_key_control', true)
                // 2, not 2.0: json_encode drops the zero fraction.
                ->where('linkedRisks.0.control_weight', 2)
                ->has('tests', 1)
                ->where('tests.0.result', 'effective')
                // A risk already mapped is not offered again: the mapping is
                // unique on (risk_id, control_id).
                ->has('linkableRisks', 0)
                ->where('can.update', true)
                ->where('can.linkRisk', true)
            );
    }

    #[Test]
    public function the_edit_page_seeds_the_form_from_the_control(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.controls.edit', $this->control))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Controls/Edit')
                ->where('control.id', $this->control->id)
                ->where('control.control_type', 'preventive')
                ->where('control.business_unit_id', $this->unit->id)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function storing_a_control_generates_its_code_and_links_the_chosen_risks(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.store'), $this->validControl(['risk_ids' => [$this->risk->id]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $control = Control::where('name', 'Daily suspense account reconciliation')->firstOrFail();

        $this->assertMatchesRegularExpression('/^CTL-\d{4}$/', $control->control_code);
        $this->assertDatabaseHas('risk_control_mapping', [
            'risk_id' => $this->risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Linked during control creation',
        ]);
    }

    #[Test]
    public function linking_a_control_to_a_risk_writes_the_columns_the_table_actually_has(): void
    {
        // REGRESSION. The controller wrote `weight`, `rationale`,
        // `mapping_status` and `linked_by` — none of which are columns of
        // `risk_control_mapping`, and none of which are fillable. Mass
        // assignment dropped all four, leaving `mapping_rationale` unset; that
        // column is NOT NULL, so linking from this screen was a constraint
        // violation every time.
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), [
                'risk_id' => $this->risk->id,
                'weight' => 40,
                'rationale' => 'Primary preventive control for this exposure.',
                'is_key_control' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('risk_control_mapping', [
            'risk_id' => $this->risk->id,
            'control_id' => $this->control->id,
            'control_weight' => 40,
            'is_key_control' => true,
            'mapping_rationale' => 'Primary preventive control for this exposure.',
        ]);
    }

    #[Test]
    public function linking_without_a_weight_leaves_the_column_default(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), ['risk_id' => $this->risk->id])
            ->assertSessionHasNoErrors();

        $mapping = RiskControlMapping::where('control_id', $this->control->id)->firstOrFail();

        // 1.00, the column default — not a null overwriting it.
        $this->assertSame('1.00', (string) $mapping->control_weight);
    }

    #[Test]
    public function linking_the_same_risk_twice_is_reported_rather_than_duplicated(): void
    {
        $payload = ['risk_id' => $this->risk->id];

        $this->actingAs($this->actor)->post(route('risk.controls.link-risk', $this->control), $payload);
        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $this->control), $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, RiskControlMapping::where('control_id', $this->control->id)->count());
    }

    #[Test]
    public function unlinking_removes_the_mapping(): void
    {
        $this->attachControl($this->risk, $this->control);

        $this->actingAs($this->actor)
            ->delete(route('risk.controls.unlink-risk', [$this->control, $this->risk]))
            ->assertRedirect();

        $this->assertSame(0, RiskControlMapping::where('control_id', $this->control->id)->count());
    }

    #[Test]
    public function a_control_linked_to_a_risk_cannot_be_deleted(): void
    {
        $this->attachControl($this->risk, $this->control);

        $this->actingAs($this->actor)
            ->delete(route('risk.controls.destroy', $this->control))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('controls', ['id' => $this->control->id]);
    }

    #[Test]
    public function an_unlinked_control_is_deleted(): void
    {
        $id = $this->control->id;

        $this->actingAs($this->actor)
            ->delete(route('risk.controls.destroy', $this->control))
            ->assertRedirect(route('risk.controls.index'));

        $this->assertSoftDeleted('controls', ['id' => $id]);
    }
}
