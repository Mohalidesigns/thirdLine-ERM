<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\RiskAuditTrail;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaDashboardService;
use App\Services\Rcsa\RcsaSubmissionService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * RCSA-05: "Not started" is the existing `draft` status.
 *
 * Opening a cycle creates every assessment `draft`; the first saved rating
 * moves it to `in_progress`, through the state machine, exactly once. Before
 * this every assessment was born `in_progress`, so a unit nobody had touched
 * looked identical to one half-way through.
 */
class AssessmentStartTest extends ReviewTestCase
{
    private RcsaAssessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk(['risk_no' => 'RETAIL-R2']);

        app(RcsaCycleService::class)->open($this->makeCycle(), $this->actor);

        $this->assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
    }

    /** @return \Illuminate\Support\Collection<int, RcsaAssessmentTransition> */
    private function transitions(RcsaAssessment $assessment, ?string $event = null)
    {
        return RcsaAssessmentTransition::query()
            ->where('assessment_id', $assessment->id)
            ->when($event !== null, fn ($q) => $q->where('event', $event))
            ->orderBy('id')
            ->get();
    }

    private function saveField(string $field, mixed $value, int $lineIndex = 0)
    {
        $line = $this->assessment->lines()->get()->get($lineIndex);

        return $this->actingAs($this->actor)->patchJson(
            route('rcsa.assessments.lines.update', [$this->assessment, $line]),
            [$field => $value, 'version' => $line->version],
        );
    }

    #[Test]
    public function an_opened_cycle_creates_every_assessment_as_draft_with_a_creation_row(): void
    {
        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->status);
        $this->assertSame(0, (int) $this->assessment->completion_pct);

        // The §9.1 rule: every status movement has a row. Creation is
        // `none -> draft`, event `open`, by whoever opened the cycle — and
        // nothing says `in_progress`, because nothing has been started.
        $rows = $this->transitions($this->assessment);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->from_status);
        $this->assertSame(RcsaAssessment::DRAFT, $rows[0]->to_status);
        $this->assertSame(RcsaAssessmentTransition::OPEN, $rows[0]->event);
        $this->assertSame($this->actor->id, $rows[0]->user_id);
        $this->assertNotNull($rows[0]->created_at);
    }

    #[Test]
    public function the_first_saved_rating_starts_it_with_exactly_one_transition_row(): void
    {
        $this->saveField('inherent_likelihood', 3)->assertOk()->assertJsonPath('status', RcsaAssessment::IN_PROGRESS);

        $this->assertSame(RcsaAssessment::IN_PROGRESS, $this->assessment->fresh()->status);

        $start = $this->transitions($this->assessment, RcsaAssessmentTransition::START);

        $this->assertCount(1, $start);
        $this->assertSame(RcsaAssessment::DRAFT, $start[0]->from_status);
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $start[0]->to_status);
        $this->assertSame($this->actor->id, $start[0]->user_id);

        // The estate-wide trail saw it too.
        $this->assertSame(1, RiskAuditTrail::query()
            ->where('entity_type', $this->assessment->getMorphClass())
            ->where('entity_id', $this->assessment->id)
            ->where('action_type', 'rcsa_transition')
            ->where('old_value', RcsaAssessment::DRAFT)
            ->where('new_value', RcsaAssessment::IN_PROGRESS)
            ->count());
    }

    #[Test]
    public function a_second_save_writes_no_further_transition(): void
    {
        $this->saveField('inherent_likelihood', 3)->assertOk();
        $this->saveField('inherent_impact', 4)->assertOk();
        // And a first save on a DIFFERENT line of the same assessment — the
        // shape two champions in one unit produce.
        $this->saveField('inherent_likelihood', 2, lineIndex: 1)->assertOk();

        $this->assertCount(1, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
        $this->assertCount(2, $this->transitions($this->assessment));
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $this->assessment->fresh()->status);
    }

    #[Test]
    public function a_save_that_changes_nothing_does_not_start_it(): void
    {
        $line = $this->assessment->lines()->first();
        $line->forceFill(['inherent_likelihood' => 3])->save();

        // The same answer re-sent is not somebody doing the work.
        $this->saveField('inherent_likelihood', 3)->assertOk();

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);
        $this->assertCount(0, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
    }

    #[Test]
    public function a_version_only_save_does_not_start_it(): void
    {
        $line = $this->assessment->lines()->first();

        // `version` is bumped by every save, so "did anything change" is
        // always true — only a RATING counts. A PATCH of nothing but the
        // version is not somebody assessing.
        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$this->assessment, $line]), ['version' => $line->version])
            ->assertOk()
            ->assertJsonPath('status', RcsaAssessment::DRAFT);

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);
        $this->assertCount(0, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
    }

    #[Test]
    public function a_rationale_only_save_does_not_start_it(): void
    {
        $this->saveField('assessment_rationale', 'Nothing has moved since the last review.')->assertOk();

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);
        $this->assertCount(0, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
        $this->assertSame(
            'Nothing has moved since the last review.',
            $this->assessment->lines()->first()->assessment_rationale,
            'the rationale itself is still saved',
        );
    }

    #[Test]
    public function an_override_only_save_does_not_start_it(): void
    {
        $line = $this->assessment->lines()->first();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$this->assessment, $line]), [
                'treatment_override' => 'accept',
                'treatment_override_reason' => 'Accepted by the committee.',
                'version' => $line->version,
            ])
            ->assertOk();

        $this->assertSame('accept', $line->fresh()->treatment_override);
        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);
        $this->assertCount(0, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
    }

    #[Test]
    public function a_rationale_saved_alongside_a_rating_still_starts_it(): void
    {
        $line = $this->assessment->lines()->first();

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$this->assessment, $line]), [
                'inherent_impact' => 2,
                'assessment_rationale' => 'Impact reduced after the process change.',
                'version' => $line->version,
            ])
            ->assertOk()
            ->assertJsonPath('status', RcsaAssessment::IN_PROGRESS);

        $this->assertCount(1, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
    }

    #[Test]
    public function a_refused_save_does_not_start_it(): void
    {
        $line = $this->assessment->lines()->first();

        // A stale version is a conflict: nothing is saved, so nothing starts.
        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$this->assessment, $line]), [
                'inherent_likelihood' => 3,
                'version' => $line->version + 5,
            ])
            ->assertStatus(409);

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);
    }

    #[Test]
    public function bulk_apply_starts_it(): void
    {
        $ids = $this->assessment->lines()->pluck('id')->all();

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.bulk-apply', $this->assessment), [
                'line_ids' => $ids,
                'control_effectiveness' => 'Mostly Achieved',
            ])
            ->assertSessionHas('success', '2 risks updated.');

        $this->assertSame(RcsaAssessment::IN_PROGRESS, $this->assessment->fresh()->status);
        $this->assertCount(1, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
    }

    #[Test]
    public function bulk_apply_to_one_risk_reads_in_the_singular(): void
    {
        $id = $this->assessment->lines()->value('id');

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.bulk-apply', $this->assessment), [
                'line_ids' => [$id],
                'control_effectiveness' => 'Mostly Achieved',
            ])
            ->assertSessionHas('success', '1 risk updated.');
    }

    #[Test]
    public function recomputing_scores_without_an_actor_does_not_start_it(): void
    {
        // Three answers stored, scores lost — the state the lost-update race
        // used to leave behind — on an assessment nobody has started.
        $line = $this->assessment->lines()->first();
        $line->forceFill([
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'control_effectiveness' => 'Mostly Achieved',
        ])->save();

        $repaired = app(RcsaAssessmentService::class)->recomputeUnscored($this->assessment);

        $this->assertSame(1, $repaired);
        $this->assertNotNull($line->fresh()->residual_level);
        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);
        $this->assertCount(0, $this->transitions($this->assessment, RcsaAssessmentTransition::START));
    }

    #[Test]
    public function an_assessment_can_be_submitted_straight_from_draft(): void
    {
        // Rated by some route that never went through apply() — a restored
        // backup, a data fix. The edge draft -> submitted exists and still works.
        foreach ($this->assessment->lines()->get() as $line) {
            $line->forceFill([
                'inherent_likelihood' => 1,
                'inherent_impact' => 1,
                'control_effectiveness' => 'Fully Achieved',
            ])->save();
        }

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);

        $result = app(RcsaSubmissionService::class)->submit($this->assessment->fresh(), $this->actor);

        $this->assertSame(RcsaAssessment::SUBMITTED, $result['status']);

        $submit = $this->transitions($this->assessment, RcsaAssessmentTransition::SUBMIT)->sole();
        $this->assertSame(RcsaAssessment::DRAFT, $submit->from_status);
    }

    #[Test]
    public function the_dashboard_counts_units_nobody_has_started_separately(): void
    {
        $this->publishedRisk(['risk_no' => 'TREAS-R1', 'business_unit_id' => $this->treasury->id, 'process_id' => null]);

        // A second cycle so Treasury has an assessment too.
        $cycle = $this->makeCycle(['name' => 'RCSA 2026 H2', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31']);
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $dashboard = app(RcsaDashboardService::class)->for($this->actor);

        $headline = $dashboard->headline($cycle->id);
        $this->assertSame(2, $headline['units']);
        $this->assertSame(2, $headline['units_not_started']);

        // Start one of them.
        $retail = RcsaAssessment::query()->where('cycle_id', $cycle->id)->where('business_unit_id', $this->retail->id)->sole();
        app(RcsaAssessmentService::class)->apply($retail->lines()->first(), ['inherent_likelihood' => 2], $this->actor);

        $headline = $dashboard->headline($cycle->id);
        $this->assertSame(2, $headline['units']);
        $this->assertSame(1, $headline['units_not_started']);

        $statuses = collect($dashboard->completionTracker($cycle->id))->pluck('status', 'business_unit')->all();
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $statuses['Retail Banking']);
        $this->assertSame(RcsaAssessment::DRAFT, $statuses['Treasury']);
    }

    #[Test]
    public function the_deadline_reminder_still_chases_a_unit_that_has_not_started(): void
    {
        $this->retail->forceFill(['head_id' => $this->actor->id])->save();
        $this->assessment->cycle->forceFill(['due_date' => now()->addDays(7)->toDateString()])->save();

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);

        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications_log')->where('type', 'rcsa.cycle.due')->count());
    }

    #[Test]
    public function closing_the_cycle_leaves_an_unstarted_assessment_draft_and_explains_it(): void
    {
        $cycle = $this->assessment->cycle;

        $this->actingAs($this->actor)->post(route('rcsa.cycles.close', $cycle))->assertRedirect();

        $this->assertSame(RcsaAssessment::DRAFT, $this->assessment->fresh()->status);

        $close = $this->transitions($this->assessment, RcsaAssessmentTransition::CLOSE)->sole();
        $this->assertSame(RcsaAssessment::DRAFT, $close->from_status);
        $this->assertSame(RcsaAssessment::DRAFT, $close->to_status);
    }
}
