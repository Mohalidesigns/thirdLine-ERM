<?php

namespace Tests\Feature\Sm;

use App\Models\Issue;
use App\Models\Sm\ScenarioAction;
use PHPUnit\Framework\Attributes\Test;

class VariantsAndActionsTest extends SmTestCase
{
    #[Test]
    public function default_variants_are_seeded_when_variants_tab_is_visited(): void
    {
        $scenario = $this->makeScenario();

        $this->assertSame(0, $scenario->variants()->count());

        $response = $this->actingAs($this->actor)
            ->get(route('scenario.show', ['scenario' => $scenario, 'tab' => 'variants']))
            ->assertOk();

        $this->assertSame(3, $scenario->variants()->count());

        $variants = $scenario->variants()->orderBy('sort_order')->get();
        $this->assertSame('tier_1', $variants[0]->tier);
        $this->assertSame('tier_2', $variants[1]->tier);
        $this->assertSame('tier_3', $variants[2]->tier);
        $this->assertFalse($variants[0]->is_cumulative);
        $this->assertTrue($variants[1]->is_cumulative);
        $this->assertTrue($variants[2]->is_cumulative);

        $response->assertInertia(fn ($page) => $page
            ->has('variants.items', 3)
            ->where('variants.items.0.tier', 'tier_1')
            ->where('variants.items.1.tier', 'tier_2')
            ->where('variants.items.2.tier', 'tier_3')
        );
    }

    #[Test]
    public function variants_can_be_saved_with_triggers_impacts_responses_and_owners(): void
    {
        $scenario = $this->makeScenario();

        $payload = [
            'variants' => [
                [
                    'tier' => 'tier_1',
                    'name' => 'Scenario 1 (Contained Outbreak)',
                    'narrative' => 'Fewer than 50 cases nationwide.',
                    'is_cumulative' => false,
                    'triggers' => ['Confirmed cases < 50', 'Zero cases in bank locations'],
                    'operational_impacts' => ['Minimal service disruption', 'No reputational damage'],
                    'responses' => ['Daily monitoring', 'Health precautions in office space'],
                    'response_owners' => ['Brands and Communication', 'Human Resources'],
                ],
                [
                    'tier' => 'tier_2',
                    'name' => 'Scenario 2 (Regional Transmission & BCP)',
                    'narrative' => 'Moderate community transmission.',
                    'is_cumulative' => true,
                    'triggers' => ['50-100 confirmed cases', 'Noticeable branch disruption'],
                    'operational_impacts' => ['Branch operating hours reduced', 'Staff absenteeism'],
                    'responses' => ['Activation of BCP', 'Remote work enabled'],
                    'response_owners' => ['Crisis Management Team', 'Corporate Services'],
                ],
                [
                    'tier' => 'tier_3',
                    'name' => 'Scenario 3 (Nationwide Crisis)',
                    'narrative' => 'Major national emergency.',
                    'is_cumulative' => true,
                    'triggers' => ['Above 100 cases', 'Federal movement restrictions'],
                    'operational_impacts' => ['Severe disruption', 'Cyberattack spike'],
                    'responses' => ['Full BCP & Crisis Management', 'Safe haven activation'],
                    'response_owners' => ['Executive Committee', 'ERM'],
                ],
            ],
        ];

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.variants.update', $scenario), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Scenario variants saved.');

        $v1 = $scenario->variants()->where('tier', 'tier_1')->first();
        $this->assertNotNull($v1);
        $this->assertSame('Scenario 1 (Contained Outbreak)', $v1->name);
        $this->assertCount(2, $v1->triggers);
        $this->assertSame('Confirmed cases < 50', $v1->triggers[0]);
        $this->assertCount(2, $v1->response_owners);
        $this->assertSame('Brands and Communication', $v1->response_owners[0]);

        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_type' => 'sm_scenario',
            'entity_id' => $scenario->id,
            'action_type' => 'variants_updated',
        ]);
    }

    #[Test]
    public function frozen_scenario_refuses_variants_update(): void
    {
        $scenario = $this->makeScenario(['status' => 'signed_off']);

        $payload = [
            'variants' => [
                ['tier' => 'tier_1', 'name' => 'T1'],
            ],
        ];

        $response = $this->actingAs($this->actor)
            ->put(route('scenario.variants.update', $scenario), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    #[Test]
    public function actions_can_be_created_updated_and_deleted(): void
    {
        $scenario = $this->makeScenario();

        // 1. Create a gap remediation action
        $gapData = [
            'kind' => ScenarioAction::KIND_GAP_REMEDIATION,
            'risk_area' => 'Operational disruption',
            'current_position' => 'BCP documented, digital channels operational.',
            'identified_gap' => 'Absence of proper documentation for VPN access rights.',
            'action_plan' => 'Dept heads to audit and approve VPN access lists.',
            'department' => 'Corporate Services',
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => ScenarioAction::STATUS_NOT_STARTED,
        ];

        $res1 = $this->actingAs($this->actor)
            ->post(route('scenario.actions.store', $scenario), $gapData);

        $res1->assertRedirect();
        $res1->assertSessionHas('success', 'Scenario action created.');

        $action = $scenario->actions()->where('risk_area', 'Operational disruption')->first();
        $this->assertNotNull($action);
        $this->assertSame(ScenarioAction::KIND_GAP_REMEDIATION, $action->kind);
        $this->assertNull($action->completed_at);

        // 2. Update action to completed
        $updateData = [
            'status' => ScenarioAction::STATUS_COMPLETED,
            'proof_of_closure' => 'VPN access matrix signed off by Head of IT and ERM.',
        ];

        $res2 = $this->actingAs($this->actor)
            ->put(route('scenario.actions.update', ['scenario' => $scenario, 'action' => $action]), $updateData);

        $res2->assertRedirect();
        $res2->assertSessionHas('success', 'Scenario action updated.');

        $fresh = $action->fresh();
        $this->assertSame(ScenarioAction::STATUS_COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame('VPN access matrix signed off by Head of IT and ERM.', $fresh->proof_of_closure);

        // 3. Delete action
        $res3 = $this->actingAs($this->actor)
            ->delete(route('scenario.actions.destroy', ['scenario' => $scenario, 'action' => $action]));

        $res3->assertRedirect();
        $res3->assertSessionHas('success', 'Scenario action deleted.');

        $this->assertNull(ScenarioAction::find($action->id));
    }

    #[Test]
    public function action_can_be_promoted_to_enterprise_issue(): void
    {
        $scenario = $this->makeScenario();

        $action = ScenarioAction::create([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'kind' => ScenarioAction::KIND_GAP_REMEDIATION,
            'risk_area' => 'Cybersecurity risk',
            'current_position' => 'NAC solution implemented.',
            'identified_gap' => 'Absence of 24/7 SOC monitoring.',
            'action_plan' => 'Establish 24/7 SOC monitoring with third-party MSSP.',
            'department' => 'Information Security',
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => ScenarioAction::STATUS_IN_PROGRESS,
            'sort_order' => 1,
        ]);

        $this->assertNull($action->issue_id);

        $res = $this->actingAs($this->actor)
            ->post(route('scenario.actions.promote', ['scenario' => $scenario, 'action' => $action]));

        $res->assertRedirect();
        $res->assertSessionHas('success');

        $fresh = $action->fresh();
        $this->assertNotNull($fresh->issue_id);

        $issue = Issue::find($fresh->issue_id);
        $this->assertNotNull($issue);
        $this->assertSame('SCENARIO_ANALYSIS', $issue->issue_source);
        $this->assertSame('OPERATIONAL_RESILIENCE', $issue->issue_category);
        $this->assertStringContainsString('Cybersecurity risk', $issue->title);
        $this->assertSame($action->action_plan, $issue->action_plan);
        $this->assertSame(1, $issue->remediationActions()->count());

        // Attempting to re-promote is refused
        $res2 = $this->actingAs($this->actor)
            ->post(route('scenario.actions.promote', ['scenario' => $scenario, 'action' => $fresh]));
        $res2->assertRedirect();
        $res2->assertSessionHas('error');

        // Deleting promoted action is refused
        $res3 = $this->actingAs($this->actor)
            ->delete(route('scenario.actions.destroy', ['scenario' => $scenario, 'action' => $fresh]));
        $res3->assertRedirect();
        $res3->assertSessionHas('error');
    }

    #[Test]
    public function global_action_tracker_renders_and_filters_actions(): void
    {
        $scenario = $this->makeScenario();

        ScenarioAction::create([
            'organization_id' => $scenario->organization_id,
            'sm_scenario_id' => $scenario->id,
            'kind' => ScenarioAction::KIND_IMMEDIATE,
            'time_horizon' => ScenarioAction::HORIZON_TODAY,
            'risk_area' => 'Health & Safety',
            'action_plan' => 'Circulate awareness bulletin to all staff.',
            'status' => ScenarioAction::STATUS_NOT_STARTED,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->actor)
            ->get(route('scenario.actions.index'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->has('stats.total')
            ->has('actions.data', 1)
            ->where('actions.data.0.risk_area', 'Health & Safety')
            ->where('actions.data.0.time_horizon', 'today')
        );
    }

    #[Test]
    public function deck_view_renders_presentation_deck(): void
    {
        $scenario = $this->makeScenario();

        $response = $this->actingAs($this->actor)
            ->get(route('scenario.deck', $scenario))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->has('scenario.title')
            ->has('variants')
            ->has('gap_actions')
            ->has('immediate_actions')
            ->has('quantitative')
        );
    }
}
