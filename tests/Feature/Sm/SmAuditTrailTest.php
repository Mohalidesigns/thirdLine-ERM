<?php

namespace Tests\Feature\Sm;

use App\Models\RiskAuditTrail;
use App\Models\Sm\Scenario;
use App\Support\MorphTypes;
use PHPUnit\Framework\Attributes\Test;

/**
 * The audit trail (§A1.11, gate checklist §A).
 *
 * AUDIT IS `AuditTrailService` → `risk_audit_trail`, NOT A NEW `sm_audit_logs`. Sm is an
 * ERM/Risk sub-module (plan §6.1) and that trail is what the register, RCSA and the KRI cycle
 * write through; `BcmsAuditable` → `bcms_audit_logs` is BCMS's, and copying it would put a second
 * audit mechanism inside the module the first belongs to.
 *
 * THE MORPH ALIAS IS THE LOAD-BEARING DETAIL. `AppServiceProvider` calls
 * `Relation::enforceMorphMap()`, so an unaliased model THROWS the moment anything asks for its
 * morph class — and `AuditTrailService::record()` asks on EVERY WRITE. Before `sm_scenario` was
 * added to `MorphTypes`, every Sm write would have been a 500. This suite pins the alias, the
 * spelling the trail stores, and the five action types the module writes.
 */
class SmAuditTrailTest extends SmTestCase
{
    #[Test]
    public function the_model_is_registered_in_the_morph_map_under_the_frozen_alias(): void
    {
        $this->assertSame(Scenario::class, MorphTypes::map()['sm_scenario']);
        $this->assertSame('sm_scenario', MorphTypes::aliasFor(Scenario::class));
        $this->assertSame('sm_scenario', (new Scenario)->getMorphClass());

        // §A1.11 measured this rather than assuming it, per ADR 0014: the alias has to fit
        // `risk_audit_trail.entity_type` (50) and every action type has to fit `action_type` (64).
        $this->assertLessThanOrEqual(RiskAuditTrail::ENTITY_TYPE_MAX, strlen('sm_scenario'));

        foreach (['create', 'update', 'status_changed', 'signoff_recorded', 'note_added', 'allocation_changed'] as $action) {
            $this->assertLessThanOrEqual(RiskAuditTrail::ACTION_TYPE_MAX, strlen($action), $action);
        }
    }

    #[Test]
    public function the_alias_is_not_one_a_neighbouring_module_already_owns(): void
    {
        // §A1.11: a bare `scenario` would compete with `Bcms\Scenario` and
        // `QuantificationScenario`, and `getMorphClass()` returns the FIRST match — "a coin toss
        // written into customer data". Three aliases, three distinct models.
        $map = MorphTypes::map();

        $scenarioAliases = array_keys(array_filter($map, fn (string $class) => str_contains($class, 'Scenario')));

        $this->assertContains('sm_scenario', $scenarioAliases);
        $this->assertContains('quantification_scenario', $scenarioAliases);
        $this->assertNotContains('scenario', $map, 'a bare `scenario` alias is the coin toss §A1.11 refused');

        // One alias, one class — no two aliases may point at the same model either.
        $this->assertSame(count($map), count(array_unique($map)));
    }

    #[Test]
    public function creating_a_scenario_writes_a_create_row_under_the_sm_scenario_alias(): void
    {
        $this->actingAs($this->actor)
            ->post(route('scenario.store'), ['name' => 'Loss of key staff'])
            ->assertSessionHasNoErrors();

        $scenario = Scenario::query()->sole();

        $row = RiskAuditTrail::query()
            ->where('entity_type', 'sm_scenario')
            ->where('entity_id', $scenario->id)
            ->where('action_type', 'create')
            ->sole();

        $this->assertSame('reference', $row->field_changed);
        $this->assertSame($scenario->reference, $row->new_value);
        $this->assertSame($this->organization->id, (int) $row->organization_id);
        $this->assertSame($this->actor->id, (int) $row->changed_by);
    }

    #[Test]
    public function a_content_edit_writes_one_update_row_per_changed_field(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design', 'background' => 'Before']);

        $this->actingAs($this->actor)->put(route('scenario.update', $scenario), [
            'background' => 'After',
            'weighting' => 'high',
        ])->assertSessionHasNoErrors();

        $rows = RiskAuditTrail::query()
            ->where('entity_type', 'sm_scenario')
            ->where('entity_id', $scenario->id)
            ->where('action_type', 'update')
            ->orderBy('id')
            ->get();

        // `AuditTrailService::recordChanges()` walks `getChanges()` — before/after from the model,
        // never a hand-typed pair — and skips `updated_at`, which changes on every save and would
        // otherwise be the loudest row in the trail. Compared as a SET because `getChanges()`
        // yields columns in the model's attribute order, which is not the request's key order.
        $changed = $rows->pluck('field_changed')->all();
        sort($changed);
        $this->assertSame(['background', 'weighting'], $changed);

        // `updated_by` is NOT among them: the fixture already set it to this actor, so it did not
        // change, and a row recording "updated_by changed from me to me" would be noise on a trail
        // an examiner reads.
        $this->assertNotContains('updated_by', $rows->pluck('field_changed')->all());
        $this->assertNotContains('updated_at', $rows->pluck('field_changed')->all());

        $background = $rows->firstWhere('field_changed', 'background');
        $this->assertSame('Before', $background->old_value);
        $this->assertSame('After', $background->new_value);
    }

    #[Test]
    public function a_transition_writes_status_changed_with_the_from_and_to_states(): void
    {
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'in_design'])
            ->assertSessionHasNoErrors();

        $row = RiskAuditTrail::query()
            ->where('entity_id', $scenario->id)
            ->where('action_type', 'status_changed')
            ->sole();

        $this->assertSame('status', $row->field_changed);
        $this->assertSame('draft', $row->old_value);
        $this->assertSame('in_design', $row->new_value);
    }

    #[Test]
    public function a_refused_transition_writes_nothing_at_all(): void
    {
        // The audit row rides the SAME transaction as the status change (C1.3), so a refusal
        // leaves no trace — and a trail that records a change the database rolled back is a lie.
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'signed_off'])
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            RiskAuditTrail::query()->where('entity_id', $scenario->id)->where('action_type', 'status_changed')->count()
        );
        $this->assertSame('draft', $scenario->fresh()->status);
    }

    #[Test]
    public function a_note_and_a_sign_off_each_write_their_own_action_type(): void
    {
        $scenario = $this->makeScenario(['status' => 'validated']);

        $this->actingAs($this->actor)
            ->post(route('scenario.notes.store', $scenario), ['kind' => 'dissent', 'body' => 'I disagree with the timeline.'])
            ->assertSessionHasNoErrors();

        $noteRow = RiskAuditTrail::query()
            ->where('entity_id', $scenario->id)
            ->where('action_type', 'note_added')
            ->sole();

        $this->assertSame('kind', $noteRow->field_changed);
        $this->assertSame('dissent', $noteRow->new_value);

        $this->actingAs($this->actor)
            ->post(route('scenario.signoffs.store', $scenario), $this->signoffPayload())
            ->assertSessionHasNoErrors();

        $signoffRows = RiskAuditTrail::query()
            ->where('entity_id', $scenario->id)
            ->where('action_type', 'signoff_recorded')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $signoffRows, 'one row per source that signed');
        $this->assertSame(['source_1', 'source_2'], $signoffRows->pluck('field_changed')->all());
        $this->assertSame(['approved', 'approved'], $signoffRows->pluck('new_value')->all());
    }

    #[Test]
    public function an_allocation_change_writes_allocation_changed_with_the_totals_before_and_after(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        $this->actingAs($this->actor)->put(route('scenario.allocations.update', $scenario), [
            'allocations' => [
                ['business_unit_id' => $this->units[0]->id, 'percent' => '60.00'],
                ['business_unit_id' => $this->units[1]->id, 'percent' => '40.00'],
            ],
        ])->assertSessionHasNoErrors();

        $row = RiskAuditTrail::query()
            ->where('entity_id', $scenario->id)
            ->where('action_type', 'allocation_changed')
            ->sole();

        $this->assertSame('allocations', $row->field_changed);
        // NULL before, not "0.00" — the same rule the payload follows (spec 03 §5.4): a rate over
        // nothing is undefined, not zero.
        $this->assertSame('', $row->old_value);
        $this->assertSame('100.00', $row->new_value);
    }

    #[Test]
    public function the_trail_is_readable_through_the_polymorphic_relation(): void
    {
        // `RiskAuditTrail::auditable()` resolves through the morph map rather than a private copy
        // of it. This is the assertion that would have caught the original defect, where the
        // service wrote "Risk" while the relationship queried "risk" and returned nothing for
        // every change recorded through it.
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'in_design'])
            ->assertSessionHasNoErrors();

        $row = RiskAuditTrail::query()->where('action_type', 'status_changed')->sole();

        $this->assertInstanceOf(Scenario::class, $row->auditable);
        $this->assertSame($scenario->id, $row->auditable->id);
    }

    #[Test]
    public function the_trail_stays_append_only_for_sm_rows_too(): void
    {
        // The chain is sealed before insert and the model refuses every later UPDATE and DELETE,
        // so an Sm audit row is exactly as immutable as a register one.
        $scenario = $this->makeScenario(['status' => 'draft']);

        $this->actingAs($this->actor)
            ->post(route('scenario.status', $scenario), ['to' => 'in_design'])
            ->assertSessionHasNoErrors();

        $row = RiskAuditTrail::query()->where('action_type', 'status_changed')->sole();

        $this->assertNotNull($row->hash);

        $this->expectException(\App\Models\AuditTrailIsAppendOnly::class);
        $row->action_type = 'tampered';
        $row->save();
    }

    #[Test]
    public function quantitative_edits_write_audit_trail_entries(): void
    {
        $scenario = $this->makeScenario(['status' => 'in_design']);

        // 1. Scorecard
        $this->actingAs($this->actor)->put(route('scenario.scorecard.update', $scenario), [
            'control_nature' => 'mixed',
            'components' => [
                [
                    'component_code' => 'control_environment',
                    'efficiency_level' => 4,
                    'weighting_factor' => 5,
                ],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $scenario->id)
                ->where('action_type', 'scorecard_updated')
                ->exists()
        );

        // 2. Frequency
        $this->actingAs($this->actor)->put(route('scenario.frequency.update', $scenario), [
            'drivers' => [
                [
                    'driver_type' => 'events',
                    'criterion' => 'Events',
                    'computation_role' => 'count',
                    'value' => 5,
                ],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $scenario->id)
                ->where('action_type', 'frequency_updated')
                ->exists()
        );

        // 3. Impact
        $this->actingAs($this->actor)->put(route('scenario.impact.update', $scenario), [
            'types' => [
                [
                    'name' => 'Operational loss',
                    'aggregation_template' => 'staff_turnover_interval',
                    'eligible_to_transfer' => true,
                    'criteria' => [
                        [
                            'label' => 'Staff count',
                            'computation_role' => 'count',
                            'typical_value' => 5,
                            'serious_value' => 10,
                            'extreme_value' => 20,
                        ],
                    ],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $scenario->id)
                ->where('action_type', 'impact_updated')
                ->exists()
        );

        // 4. Insurance
        $this->actingAs($this->actor)->put(route('scenario.insurance.update', $scenario), [
            'coverages' => [
                [
                    'severity' => 'typical',
                    'deductible_local' => 100,
                    'limit_local' => 500,
                    'deductible_group' => 1000,
                    'limit_group' => 5000,
                    'recovery_rate' => 0.9,
                    'probability_of_cover' => 0.95,
                    'insurer_default_haircut' => 0.02,
                ],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            RiskAuditTrail::query()
                ->where('entity_id', $scenario->id)
                ->where('action_type', 'insurance_updated')
                ->exists()
        );
    }
}
