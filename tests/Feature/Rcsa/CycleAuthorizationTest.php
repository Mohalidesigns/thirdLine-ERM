<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Policies\Rcsa\RcsaAssessmentPolicy;
use App\Policies\Rcsa\RcsaCyclePolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Who may run a cycle, who may fill one in, and what the flag hides.
 */
class CycleAuthorizationTest extends CycleTestCase
{
    #[Test]
    public function the_policies_are_discovered_for_their_models(): void
    {
        // A policy in the wrong namespace is not an error — every ability
        // silently returns false, which reads as a permissions problem.
        $this->assertInstanceOf(RcsaCyclePolicy::class, Gate::getPolicyFor(RcsaCycle::class));
        $this->assertInstanceOf(RcsaAssessmentPolicy::class, Gate::getPolicyFor(RcsaAssessment::class));
    }

    #[Test]
    public function opening_a_cycle_is_a_separate_authority_from_scheduling_one(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $cycle = $this->makeCycle();

        // A coordinator who drafts the quarter's cycle but does not decide when
        // it starts — opening copies the whole universe and cannot be undone.
        $coordinator = $this->userWith(['rcsa_cycle.view', 'rcsa_cycle.manage']);

        $this->actingAs($coordinator)
            ->put(route('rcsa.cycles.update', $cycle), [
                'name' => 'RCSA 2026 H1 (revised)',
                'period_start' => '2026-01-01',
                'period_end' => '2026-06-30',
                'methodology_id' => $this->methodology()->id,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($coordinator)
            ->post(route('rcsa.cycles.open', $cycle))
            ->assertForbidden();

        $this->assertSame(RcsaCycle::DRAFT, $cycle->fresh()->status);
    }

    #[Test]
    public function an_assessor_can_score_but_cannot_run_the_cycle(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        // The plan's Risk Champion.
        $champion = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view', 'rcsa_assessment.complete']);

        $this->actingAs($champion)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->version,
            ])
            ->assertOk();

        $this->actingAs($champion)->post(route('rcsa.cycles.close', $cycle))->assertForbidden();
        $this->actingAs($champion)->delete(route('rcsa.cycles.destroy', $cycle))->assertForbidden();
    }

    #[Test]
    public function a_reader_cannot_change_a_line(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $line = $assessment->lines()->sole();

        $reader = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view']);

        $this->actingAs($reader)->get(route('rcsa.assessments.show', $assessment))->assertOk();

        $this->actingAs($reader)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->version,
            ])
            ->assertForbidden();

        $this->actingAs($reader)
            ->post(route('rcsa.assessments.bulk-apply', $assessment), [
                'line_ids' => [$line->id],
                'control_effectiveness' => 'Fully Achieved',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_line_from_another_assessment_is_not_found(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'potential_risk' => 'A risk that belongs to Treasury rather than Retail.',
        ]);

        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);

        $retail = RcsaAssessment::where('business_unit_id', $this->retail->id)->sole();
        $treasuryLine = RcsaAssessment::where('business_unit_id', $this->treasury->id)->sole()->lines()->sole();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$retail, $treasuryLine]), [
                'inherent_likelihood' => 3,
                'version' => $treasuryLine->version,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function another_tenants_cycle_and_assessment_are_not_found(): void
    {
        $foreign = TenantContext::bypass(fn () => RcsaCycle::create([
            'organization_id' => $this->otherOrg->id,
            'name' => 'Another bank\'s cycle',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'methodology_id' => $this->methodology()->id,
            'status' => RcsaCycle::OPEN,
        ]));

        $foreignAssessment = TenantContext::bypass(fn () => RcsaAssessment::create([
            'organization_id' => $this->otherOrg->id,
            'cycle_id' => $foreign->id,
            'business_unit_id' => $this->foreignUnit->id,
            'status' => RcsaAssessment::IN_PROGRESS,
        ]));

        $this->actingAs($this->actor)->get(route('rcsa.cycles.show', $foreign->id))->assertNotFound();
        $this->actingAs($this->actor)->post(route('rcsa.cycles.close', $foreign->id))->assertNotFound();
        $this->actingAs($this->actor)->get(route('rcsa.assessments.show', $foreignAssessment->id))->assertNotFound();
    }

    #[Test]
    public function an_opened_cycle_cannot_be_edited_or_deleted(): void
    {
        $cycle = $this->openedCycle();

        // Its period and methodology are load-bearing — assessments already
        // reference them — and deleting would take a quarter's work with it.
        $this->actingAs($this->actor)
            ->put(route('rcsa.cycles.update', $cycle), [
                'name' => 'Renamed',
                'period_start' => '2020-01-01',
                'period_end' => '2020-06-30',
                'methodology_id' => $this->methodology()->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame('RCSA 2026 H1', $cycle->fresh()->name);

        $this->actingAs($this->actor)
            ->delete(route('rcsa.cycles.destroy', $cycle))
            ->assertSessionHas('error');

        $this->assertNotNull($cycle->fresh());
    }

    #[Test]
    public function the_routes_do_not_exist_when_the_flag_is_off(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        config()->set('features.rcsa_v2', false);

        $this->actingAs($this->actor)->get(route('rcsa.cycles.index'))->assertNotFound();
        $this->actingAs($this->actor)->get(route('rcsa.cycles.show', $cycle))->assertNotFound();
        $this->actingAs($this->actor)->get(route('rcsa.assessments.show', $assessment))->assertNotFound();
    }

    #[Test]
    public function a_cycle_cannot_be_scheduled_against_another_tenants_methodology(): void
    {
        $foreignMethodology = TenantContext::bypass(fn () => \App\Models\Rcsa\RcsaMethodology::create([
            'organization_id' => $this->otherOrg->id,
            'code' => 'other-bank',
            'name' => "Another bank's methodology",
            'version' => '1.0',
            'status' => 'active',
        ]));

        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.store'), [
                'name' => 'RCSA 2026 H1',
                'period_start' => '2026-01-01',
                'period_end' => '2026-06-30',
                'methodology_id' => $foreignMethodology->id,
            ])
            ->assertSessionHasErrors('methodology_id');
    }

    #[Test]
    public function the_system_methodology_can_be_used_by_any_tenant(): void
    {
        // organization_id NULL means "belongs to everybody". A plain
        // tenant-bound exists rule would reject the default and make it
        // impossible to schedule a cycle on a fresh install.
        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.store'), [
                'name' => 'RCSA 2026 H1',
                'period_start' => '2026-01-01',
                'period_end' => '2026-06-30',
                'methodology_id' => $this->methodology()->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, RcsaCycle::count());
    }
}
