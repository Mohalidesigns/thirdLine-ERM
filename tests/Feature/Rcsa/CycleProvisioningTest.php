<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Rcsa\RcsaSystem;
use App\Services\Rcsa\RcsaCycleService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Step 3 of the process flow: "the system populates Process, Risk & Control".
 */
class CycleProvisioningTest extends CycleTestCase
{
    #[Test]
    public function opening_a_cycle_provisions_one_assessment_per_unit_and_a_line_per_published_risk(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk(['risk_no' => 'RETAIL-R2', 'potential_risk' => 'A second risk in the same business unit.']);
        $this->publishedRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'potential_risk' => 'A risk that belongs to Treasury rather than Retail.',
        ]);

        // A draft and a retired risk: neither is approved master data, so
        // neither is carried in. This is rule 1 of the process flow.
        $this->makeRisk(['risk_no' => 'RETAIL-R9', 'status' => RcsaRegisterRisk::DRAFT]);
        $this->makeRisk(['risk_no' => 'RETAIL-R8', 'status' => RcsaRegisterRisk::RETIRED]);

        $cycle = $this->makeCycle();

        $result = app(RcsaCycleService::class)->open($cycle, $this->actor);

        $this->assertSame(['assessments' => 2, 'lines' => 3], $result);
        $this->assertSame(RcsaCycle::OPEN, $cycle->fresh()->status);
        $this->assertSame($this->actor->id, $cycle->fresh()->opened_by);

        $this->assertSame(3, RcsaAssessmentLine::count(), 'Only published risks are provisioned.');

        $retail = RcsaAssessment::where('business_unit_id', $this->retail->id)->sole();
        $this->assertSame(2, $retail->lines()->count());
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $retail->status);
        $this->assertSame(0, $retail->completion_pct);
    }

    #[Test]
    public function a_line_is_a_snapshot_that_a_later_universe_edit_cannot_rewrite(): void
    {
        $system = RcsaSystem::create([
            'organization_id' => $this->organization->id,
            'code' => 'FIN', 'name' => 'Finacle', 'is_active' => true,
        ]);

        $risk = $this->publishedRisk([
            'risk_no' => 'RETAIL-R1',
            'potential_risk' => 'The wording as it stood when the cycle was opened.',
            'risk_driver' => 'The driver as it stood then.',
            'risk_category' => 'Operational',
            'system_ids' => [$system->id],
            'sub_process_id' => $this->kyc->id,
        ]);

        $risk->controls()->create([
            'organization_id' => $this->organization->id,
            'description' => 'The control that was in place.',
        ]);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $line = RcsaAssessmentLine::sole();

        // The snapshot, including the workbook's single Existing Control cell.
        $this->assertSame('The wording as it stood when the cycle was opened.', $line->potential_risk);
        $this->assertSame('Retail Banking', $line->business_unit_name);
        $this->assertSame('Customer Onboarding', $line->process_name);
        $this->assertSame('KYC Verification', $line->sub_process_name);
        $this->assertSame(['Finacle'], $line->system_names);
        $this->assertSame('The control that was in place.', $line->existing_control);
        $this->assertSame($risk->id, $line->register_risk_id, 'The provenance link survives.');

        /* --- Now rewrite the universe entirely ------------------------- */

        $risk->update([
            'potential_risk' => 'A completely different statement written in November.',
            'risk_driver' => 'A different driver.',
            'risk_category' => 'Market',
        ]);
        $risk->controls()->delete();

        $line->refresh();

        // Editing master data must not rewrite the assessment the Board Risk
        // Committee signed. This is the single most important property of the
        // schema.
        $this->assertSame('The wording as it stood when the cycle was opened.', $line->potential_risk);
        $this->assertSame('The driver as it stood then.', $line->risk_driver);
        $this->assertSame('Operational', $line->risk_category);
        $this->assertSame('The control that was in place.', $line->existing_control);
    }

    #[Test]
    public function several_controls_become_the_workbooks_single_existing_control_cell(): void
    {
        $risk = $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        foreach (['First control.', 'Second control.', 'Third control.'] as $index => $description) {
            $risk->controls()->create([
                'organization_id' => $this->organization->id,
                'description' => $description,
                'sort_order' => ($index + 1) * 10,
            ]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $this->assertSame(
            "First control.\nSecond control.\nThird control.",
            RcsaAssessmentLine::sole()->existing_control
        );
    }

    #[Test]
    public function a_cycle_can_be_narrowed_to_named_business_units_for_a_pilot(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'potential_risk' => 'A risk that belongs to Treasury rather than Retail.',
        ]);

        $cycle = $this->makeCycle();

        $result = app(RcsaCycleService::class)->open($cycle, $this->actor, [$this->treasury->id]);

        $this->assertSame(1, $result['assessments']);
        $this->assertSame($this->treasury->id, RcsaAssessment::sole()->business_unit_id);
    }

    #[Test]
    public function a_cycle_is_not_opened_twice(): void
    {
        $cycle = $this->openedCycle();

        // Re-provisioning would either duplicate every line or discard scoring
        // already done. An assessment whose line count changes underneath the
        // assessor is not a document anybody can sign.
        $this->expectExceptionMessageMatches('/Only a draft cycle can be opened/');

        app(RcsaCycleService::class)->open($cycle, $this->actor);
    }

    #[Test]
    public function opening_with_an_empty_universe_explains_itself(): void
    {
        // Every risk here is a draft, which is exactly the state a tenant is in
        // straight after their first import — imported rows arrive as drafts.
        $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        $cycle = $this->makeCycle();

        $this->expectExceptionMessageMatches('/no published risks/');

        app(RcsaCycleService::class)->open($cycle, $this->actor);
    }

    #[Test]
    public function opening_a_cycle_locks_its_methodology(): void
    {
        $this->assertFalse($this->methodology()->is_locked);

        $this->openedCycle();

        // Re-cutting a band under a live cycle would silently re-rate every
        // line already scored.
        $this->assertTrue($this->methodology()->fresh()->is_locked);
        $this->assertNotNull($this->methodology()->fresh()->locked_at);
    }

    #[Test]
    public function a_line_is_pinned_to_the_methodology_its_cycle_named(): void
    {
        $cycle = $this->openedCycle();

        $this->assertSame($cycle->methodology_id, RcsaAssessmentLine::sole()->methodology_id);
    }

    /* ------------------------------------------------------------------ */
    /*  The link to last cycle */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_new_cycle_links_each_line_to_the_last_time_that_risk_was_scored(): void
    {
        $risk = $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $first = $this->makeCycle(['name' => 'RCSA 2025 H2', 'period_start' => '2025-07-01', 'period_end' => '2025-12-31']);
        app(RcsaCycleService::class)->open($first, $this->actor);

        $firstLine = RcsaAssessmentLine::sole();

        // Score it, because only a SCORED prior line is a basis for comparison.
        app(\App\Services\Rcsa\RcsaAssessmentService::class)->apply(
            $firstLine,
            ['inherent_likelihood' => 3, 'inherent_impact' => 3, 'control_effectiveness' => 'Mostly Achieved'],
            $this->actor,
        );

        $second = $this->makeCycle(['name' => 'RCSA 2026 H1']);
        app(RcsaCycleService::class)->open($second, $this->actor);

        $secondLine = RcsaAssessmentLine::where('id', '!=', $firstLine->id)->sole();

        $this->assertSame($firstLine->id, $secondLine->prior_cycle_line_id);
        $this->assertSame(9, $secondLine->priorLine->inherent_score);
    }

    #[Test]
    public function an_unscored_previous_cycle_is_not_offered_as_a_comparison(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $first = $this->makeCycle(['name' => 'RCSA 2025 H2', 'period_start' => '2025-07-01', 'period_end' => '2025-12-31']);
        app(RcsaCycleService::class)->open($first, $this->actor);

        $firstLine = RcsaAssessmentLine::sole();

        $second = $this->makeCycle(['name' => 'RCSA 2026 H1']);
        app(RcsaCycleService::class)->open($second, $this->actor);

        $secondLine = RcsaAssessmentLine::where('id', '!=', $firstLine->id)->sole();

        // Offering a blank as "last cycle" would invite an assessor to accept
        // nothing as agreement.
        $this->assertNull($secondLine->prior_cycle_line_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Closing */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function closing_a_cycle_freezes_every_assessment_under_it(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        $this->assertTrue($assessment->acceptsEdits());

        app(RcsaCycleService::class)->close($cycle, $this->actor);

        // The assessment is still `in_progress`; the CYCLE is what freezes it.
        // Without consulting the cycle, a unit left unfinished when the quarter
        // closed would go on accepting edits and drift away from the figures
        // the closed cycle reported.
        $assessment = $assessment->fresh();
        $assessment->load('cycle');

        $this->assertSame(RcsaAssessment::IN_PROGRESS, $assessment->status);
        $this->assertFalse($assessment->acceptsEdits());
    }
}
