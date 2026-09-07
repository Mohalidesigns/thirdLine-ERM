<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaSubmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Step 7 and the submission gate.
 *
 * The acceptance criterion for P4 is rule 6: "submission is impossible with an
 * incomplete above-appetite line". Most of what follows is that sentence taken
 * apart — what counts as above appetite, what counts as complete, and what
 * happens at the moment somebody presses submit.
 */
class SubmissionTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa_assessment.submit']);
        Storage::fake('local');
    }

    /**
     * Score every line, above or within appetite as asked.
     *
     * 5 × 5 Not Achieved lands at 18.75 — VERY HIGH, treat, above appetite.
     * 1 × 1 Fully Achieved lands at 0.00 — VERY LOW, accept, within it.
     */
    private function scoreAll(RcsaAssessment $assessment, bool $aboveAppetite = true): void
    {
        $service = app(RcsaAssessmentService::class);

        foreach ($assessment->lines()->get() as $line) {
            $service->apply($line, $aboveAppetite
                ? ['inherent_likelihood' => 5, 'inherent_impact' => 5, 'control_effectiveness' => 'Not Achieved']
                : ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved'],
                $this->actor);
        }
    }

    private function completePlanFor(RcsaAssessmentLine $line): RcsaActionPlan
    {
        return $line->actionPlans()->create([
            'organization_id' => $this->organization->id,
            'control_to_implement' => 'Introduce a four-eyes check before release.',
            'owner_id' => $this->actor->id,
            'target_date' => now()->addMonths(3)->toDateString(),
            'status' => RcsaActionPlan::OPEN,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  The acceptance criterion */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_above_appetite_line_without_a_complete_plan_blocks_submission(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        $this->scoreAll($assessment, aboveAppetite: true);

        $line = $assessment->lines()->sole();

        $this->assertStringStartsWith('Above risk appetite', (string) $line->fresh()->appetite_status);

        /* --- No plan at all -------------------------------------------- */

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('error');

        $this->assertSame(RcsaAssessment::IN_PROGRESS, $assessment->fresh()->status);

        /* --- A plan missing its owner is not a plan --------------------- */

        $incomplete = $line->actionPlans()->create([
            'organization_id' => $this->organization->id,
            'control_to_implement' => 'Something will be done.',
            'owner_id' => null,
            'target_date' => now()->addMonth()->toDateString(),
            'status' => RcsaActionPlan::OPEN,
        ]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('error');

        $this->assertSame(RcsaAssessment::IN_PROGRESS, $assessment->fresh()->status);

        /* --- Complete it and submission goes through -------------------- */

        $incomplete->update(['owner_id' => $this->actor->id]);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertRedirect(route('rcsa.cycles.show', $cycle->id));

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->fresh()->status);
    }

    #[Test]
    public function a_line_within_appetite_needs_no_plan(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        $this->scoreAll($assessment, aboveAppetite: false);

        $this->assertSame(
            'Within risk appetite: Continue routine monitoring',
            $assessment->lines()->sole()->fresh()->appetite_status
        );

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHasNoErrors();

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->fresh()->status);
    }

    #[Test]
    public function an_unscored_line_blocks_submission_and_the_blockers_name_it(): void
    {
        foreach (range(1, 2) as $n) {
            $this->publishedRisk([
                'risk_no' => 'RETAIL-R'.$n,
                'potential_risk' => "Risk number {$n}, written long enough to be a valid statement.",
            ]);
        }

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::sole();
        $lines = $assessment->lines()->get();

        // Only the first is answered.
        app(RcsaAssessmentService::class)->apply(
            $lines[0],
            ['inherent_likelihood' => 1, 'inherent_impact' => 1, 'control_effectiveness' => 'Fully Achieved'],
            $this->actor,
        );

        $blockers = app(RcsaSubmissionService::class)->blockers($assessment->fresh());

        $this->assertCount(1, $blockers['issues']);
        $this->assertSame('unscored', $blockers['issues'][0]['type']);
        // A jump link needs the line, and a person needs the risk number.
        $this->assertSame($lines[1]->id, $blockers['issues'][0]['line_id']);
        $this->assertSame('RETAIL-R2', $blockers['issues'][0]['risk_no']);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('error');
    }

    #[Test]
    public function a_line_somebody_else_has_open_blocks_submission(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);

        $other = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete', 'rcsa_cycle.view']);

        $assessment->lines()->sole()->forceFill([
            'locked_by' => $other->id,
            'lock_expires_at' => now()->addMinutes(5),
        ])->save();

        // Submitting now would freeze a colleague's work part-finished.
        $blockers = app(RcsaSubmissionService::class)->blockers($assessment, $this->actor->id);

        $this->assertSame(['locked'], array_column($blockers['issues'], 'type'));

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('error');

        /* --- My own lock does not block me ------------------------------ */

        $assessment->lines()->sole()->forceFill(['locked_by' => $this->actor->id])->save();

        $this->assertSame([], app(RcsaSubmissionService::class)->blockers($assessment, $this->actor->id)['issues']);
    }

    #[Test]
    public function an_unjustified_treatment_override_blocks_submission(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: true);

        $line = $assessment->lines()->sole();
        $this->completePlanFor($line);

        $this->assertTrue(app(RcsaSubmissionService::class)->canSubmit($assessment->fresh()));

        // Overriding "treat" down to "accept" is exactly the decision that
        // needs a reason attached to it.
        $line->forceFill(['treatment_override' => 'accept', 'treatment_override_reason' => null])->save();

        $types = array_column(app(RcsaSubmissionService::class)->blockers($assessment->fresh())['issues'], 'type');

        $this->assertContains('override', $types);

        $line->forceFill(['treatment_override_reason' => 'The exposure is covered by a group-level insurance policy.'])->save();

        $this->assertTrue(app(RcsaSubmissionService::class)->canSubmit($assessment->fresh()));
    }

    /* ------------------------------------------------------------------ */
    /*  What submission does */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function submitting_locks_every_line_and_stamps_who_and_when(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);

        $this->actingAs($this->actor)->post(route('rcsa.assessments.submit', $assessment));

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->status);
        $this->assertSame($this->actor->id, $assessment->submitted_by);
        $this->assertNotNull($assessment->submitted_at);

        foreach ($assessment->lines()->get() as $line) {
            $this->assertNotNull($line->locked_at, 'Every line is frozen at submission.');
            // The advisory lock is released: it exists to stop two people
            // typing at once, and after submission nobody is typing.
            $this->assertNull($line->locked_by);
        }
    }

    #[Test]
    public function a_submitted_assessment_no_longer_accepts_edits(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);
        $line = $assessment->lines()->sole();

        $this->actingAs($this->actor)->post(route('rcsa.assessments.submit', $assessment));

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $line]), [
                'inherent_likelihood' => 5,
                'version' => $line->fresh()->version,
            ])
            ->assertForbidden();

        $this->assertSame(1, $line->fresh()->inherent_likelihood, 'The filed answer stands.');
    }

    #[Test]
    public function an_assessment_cannot_be_submitted_twice(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);

        $this->actingAs($this->actor)->post(route('rcsa.assessments.submit', $assessment));

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertForbidden();
    }

    #[Test]
    public function submitting_writes_a_pdf_snapshot_of_what_was_filed(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: true);
        $this->completePlanFor($assessment->lines()->sole());

        $this->actingAs($this->actor)->post(route('rcsa.assessments.submit', $assessment));

        $path = $assessment->fresh()->snapshot_path;

        $this->assertNotNull($path, 'A submission records what was filed.');
        Storage::disk('local')->assertExists($path);

        $contents = Storage::disk('local')->get($path);

        $this->assertStringStartsWith('%PDF', $contents, 'The snapshot is a real PDF.');
        $this->assertGreaterThan(1000, strlen($contents));
    }

    #[Test]
    public function a_snapshot_that_cannot_be_rendered_does_not_block_the_submission(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);

        // A rendering library failing at the end of a quarter's work must not
        // be what stops a risk champion filing it. The document is missing;
        // the submission is not refused.
        $this->mock(\ThirdLine\Reporting\DocumentRenderer::class, function ($mock) {
            $mock->shouldReceive('pdf')->andThrow(new \RuntimeException('dompdf fell over'));
        });

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHasNoErrors();

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->status);
        $this->assertNull($assessment->snapshot_path);
    }

    #[Test]
    public function submitting_notifies_the_operational_risk_function(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);

        // Whoever holds `rcsa_assessment.review` — P5's permission for exactly
        // this function. P4 shipped this pointed at `rcsa_cycle.close`, which
        // was the closest thing that existed at the time and is a different
        // authority: a cycle coordinator is not necessarily a reviewer. The
        // actor is not notified about their own submission.
        $reviewer = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.review']);

        $this->actingAs($this->actor)->post(route('rcsa.assessments.submit', $assessment));

        $notifications = DB::table('notifications_log')
            ->where('type', 'rcsa.assessment.submitted')
            ->get();

        $this->assertGreaterThanOrEqual(1, $notifications->count());
        $this->assertContains($reviewer->id, $notifications->pluck('user_id')->all());
        $this->assertNotContains($this->actor->id, $notifications->pluck('user_id')->all());
    }

    /* ------------------------------------------------------------------ */
    /*  Action plans */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_action_plan_needs_a_control_an_owner_and_a_future_date(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: true);
        $line = $assessment->lines()->sole();

        $post = fn (array $payload) => $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.plans.store', [$assessment, $line]), $payload);

        $post(['owner_id' => $this->actor->id, 'target_date' => now()->addMonth()->toDateString()])
            ->assertSessionHasErrors('control_to_implement');

        $post(['control_to_implement' => 'A properly described control.', 'target_date' => now()->addMonth()->toDateString()])
            ->assertSessionHasErrors('owner_id');

        $post(['control_to_implement' => 'A properly described control.', 'owner_id' => $this->actor->id])
            ->assertSessionHasErrors('target_date');

        // A remediation due last month is not a plan.
        $post([
            'control_to_implement' => 'A properly described control.',
            'owner_id' => $this->actor->id,
            'target_date' => now()->subMonth()->toDateString(),
        ])->assertSessionHasErrors('target_date');

        $post([
            'control_to_implement' => 'A properly described control.',
            'owner_id' => $this->actor->id,
            'target_date' => now()->addMonths(2)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $line->actionPlans()->count());
    }

    #[Test]
    public function a_line_may_carry_several_plans(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: true);
        $line = $assessment->lines()->sole();

        // Defect D5: the workbook has one cell, and a risk above appetite
        // routinely needs three actions with three owners and three dates.
        foreach (['First remediation step to take.', 'Second remediation step.', 'Third one.'] as $control) {
            $this->actingAs($this->actor)->post(route('rcsa.assessments.plans.store', [$assessment, $line]), [
                'control_to_implement' => $control,
                'owner_id' => $this->actor->id,
                'target_date' => now()->addMonths(2)->toDateString(),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $line->actionPlans()->count());
        $this->assertTrue(app(RcsaSubmissionService::class)->canSubmit($assessment->fresh()));
    }

    #[Test]
    public function an_overdue_plan_can_still_be_edited(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: true);
        $line = $assessment->lines()->sole();

        $plan = $this->completePlanFor($line);
        $plan->forceFill(['target_date' => now()->subMonth()->toDateString()])->save();

        // The future-date rule applies on CREATE only. A plan whose date has
        // passed is genuinely overdue, and that is exactly when somebody needs
        // to change it — refusing would make the overdue item uneditable.
        $this->actingAs($this->actor)
            ->put(route('rcsa.assessments.plans.update', [$assessment, $line, $plan]), [
                'control_to_implement' => 'A revised remediation, now that the first slipped.',
                'owner_id' => $this->actor->id,
                'target_date' => now()->subMonth()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith('A revised remediation', $plan->fresh()->control_to_implement);
    }

    #[Test]
    public function a_plan_cannot_be_attached_to_a_line_in_another_assessment(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'potential_risk' => 'A risk that belongs to Treasury rather than Retail.',
        ]);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $retail = RcsaAssessment::where('business_unit_id', $this->retail->id)->sole();
        $treasuryLine = RcsaAssessment::where('business_unit_id', $this->treasury->id)->sole()->lines()->sole();

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.plans.store', [$retail, $treasuryLine]), [
                'control_to_implement' => 'Reaching across assessments.',
                'owner_id' => $this->actor->id,
                'target_date' => now()->addMonth()->toDateString(),
            ])
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Authority */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function completing_and_submitting_are_different_authorities(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();
        $this->scoreAll($assessment, aboveAppetite: false);

        // §14 Q6: a tenant that wants a BU Head to approve before ORM sees it
        // takes `submit` off the champion, and this is the shape that allows.
        $champion = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view', 'rcsa_assessment.complete']);

        $this->actingAs($champion)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertForbidden();

        $this->assertSame(RcsaAssessment::IN_PROGRESS, $assessment->fresh()->status);
    }

    #[Test]
    public function the_workspace_reports_whether_it_can_be_submitted(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        $this->actingAs($this->actor)
            ->get(route('rcsa.assessments.show', $assessment))
            ->assertInertia(fn ($page) => $page->where('can.submit', true)->etc());

        $reader = $this->userWith(['rcsa_cycle.view', 'rcsa_assessment.view']);

        $this->actingAs($reader)
            ->get(route('rcsa.assessments.show', $assessment))
            ->assertInertia(fn ($page) => $page->where('can.submit', false)->etc());
    }
}
