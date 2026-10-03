<?php

namespace Tests\Feature\Rcsa;

use App\Models\BusinessUnit;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\Rcsa\RcsaCycle;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaSubmissionService;
use App\Services\Rcsa\RcsaWorkflowService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Opening and closing a cycle both move an assessment without going through
 * its own state machine — RcsaWorkflowService::transition()'s EDGES has
 * nothing to check a bulk, cycle-driven move against — and both used to write
 * nothing to `rcsa_assessment_transitions` because of it. An assessment
 * validated on Tuesday and closed with its cycle on Friday had a history that
 * stopped at "validated" while its status said "closed".
 */
class CycleCloseHistoryTest extends ReviewTestCase
{
    /**
     * Three business units, three outcomes: one submitted and waiting, one
     * validated by the ORM, one nobody ever touched past scoring. Closing the
     * cycle must explain all three, not just the ones whose status field
     * actually moves.
     */
    #[Test]
    public function closing_a_cycle_writes_one_history_row_per_assessment_it_touches(): void
    {
        $ops = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'OPS',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk(['risk_no' => 'TREAS-R1', 'business_unit_id' => $this->treasury->id, 'process_id' => null]);
        $this->publishedRisk(['risk_no' => 'OPS-R1', 'business_unit_id' => $ops->id, 'process_id' => null]);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $retailAssessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
        $treasuryAssessment = RcsaAssessment::query()->where('business_unit_id', $this->treasury->id)->sole();
        $opsAssessment = RcsaAssessment::query()->where('business_unit_id', $ops->id)->sole();

        // Opening already wrote an OPEN row for every assessment it created —
        // this is the other half of the same defect, checked once here rather
        // than in a test of its own.
        foreach ([$retailAssessment, $treasuryAssessment, $opsAssessment] as $assessment) {
            $open = RcsaAssessmentTransition::where('assessment_id', $assessment->id)
                ->where('event', RcsaAssessmentTransition::OPEN)
                ->sole();

            $this->assertNull($open->from_status);
            $this->assertSame(RcsaAssessment::IN_PROGRESS, $open->to_status);
            $this->assertSame($this->actor->id, $open->user_id);
        }

        $this->scoreAll($retailAssessment);
        $this->scoreAll($treasuryAssessment);
        $this->scoreAll($opsAssessment);

        app(RcsaSubmissionService::class)->submit($retailAssessment, $this->actor);

        app(RcsaSubmissionService::class)->submit($treasuryAssessment, $this->actor);
        $workflow = app(RcsaWorkflowService::class);
        $workflow->claim($treasuryAssessment->fresh(), $this->reviewer);
        $workflow->validate($treasuryAssessment->fresh(), $this->reviewer);

        // $opsAssessment is left exactly `in_progress` — nobody ever submitted it.

        $this->assertSame(RcsaAssessment::SUBMITTED, $retailAssessment->fresh()->status);
        $this->assertSame(RcsaAssessment::VALIDATED, $treasuryAssessment->fresh()->status);
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $opsAssessment->fresh()->status);

        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.close', $cycle), ['reason' => 'Quarter closed on schedule.'])
            ->assertRedirect();

        $this->assertSame(RcsaCycle::CLOSED, $cycle->fresh()->status);

        // submitted -> closed
        $this->assertSame(RcsaAssessment::CLOSED, $retailAssessment->fresh()->status);
        // validated -> closed
        $this->assertSame(RcsaAssessment::CLOSED, $treasuryAssessment->fresh()->status);
        // in_progress stays in_progress: the CYCLE freezes it, not its own status.
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $opsAssessment->fresh()->status);

        $retailClose = RcsaAssessmentTransition::where('assessment_id', $retailAssessment->id)
            ->where('event', RcsaAssessmentTransition::CLOSE)->sole();
        $this->assertSame(RcsaAssessment::SUBMITTED, $retailClose->from_status);
        $this->assertSame(RcsaAssessment::CLOSED, $retailClose->to_status);
        $this->assertSame($this->actor->id, $retailClose->user_id);
        $this->assertSame('Quarter closed on schedule.', $retailClose->reason);

        $treasuryClose = RcsaAssessmentTransition::where('assessment_id', $treasuryAssessment->id)
            ->where('event', RcsaAssessmentTransition::CLOSE)->sole();
        $this->assertSame(RcsaAssessment::VALIDATED, $treasuryClose->from_status);
        $this->assertSame(RcsaAssessment::CLOSED, $treasuryClose->to_status);

        // The one that never changed status still gets a row: the close
        // touched it too, and a history that only speaks when `status`
        // literally moves would go silent for exactly the assessment that
        // most needs explaining — one that stopped accepting edits with no
        // status change to show for it.
        $opsClose = RcsaAssessmentTransition::where('assessment_id', $opsAssessment->id)
            ->where('event', RcsaAssessmentTransition::CLOSE)->sole();
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $opsClose->from_status);
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $opsClose->to_status);
        $this->assertSame($this->actor->id, $opsClose->user_id);

        $this->assertFalse($opsAssessment->fresh()->acceptsEdits());

        // And the audit screen — what an examiner actually reads — shows it.
        $this->actingAs($this->actor)
            ->get(route('rcsa.audit.show', $retailAssessment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RcsaAudit/Show')
                ->where('transitions', fn ($transitions) => collect($transitions)->contains(
                    fn ($row) => $row['event'] === RcsaAssessmentTransition::CLOSE && $row['to'] === RcsaAssessment::CLOSED,
                )));
    }

    /**
     * A doubled-up click, or two coordinators closing the same cycle within
     * the same instant. The cheap `status === CLOSED` check that runs before
     * opening a transaction cannot see the other request's write; only a
     * `lockForUpdate()` re-read inside the transaction can, and without it a
     * second close writes a second CLOSE row over every assessment the first
     * one already touched.
     */
    #[Test]
    public function a_second_close_is_refused_and_writes_no_second_set_of_rows(): void
    {
        $cycle = $this->openedCycle();
        $assessment = RcsaAssessment::sole();

        app(RcsaCycleService::class)->close($cycle, $this->actor);

        $closeRows = fn () => RcsaAssessmentTransition::where('assessment_id', $assessment->id)
            ->where('event', RcsaAssessmentTransition::CLOSE)
            ->count();

        $this->assertSame(1, $closeRows());

        // Through the HTTP endpoint, the way a second click actually arrives.
        // The controller catches the RuntimeException and flashes an error
        // rather than 500ing.
        $this->actingAs($this->actor)
            ->post(route('rcsa.cycles.close', $cycle), ['reason' => 'Clicked twice.'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, $closeRows(), 'A second close wrote a second history row.');
        $this->assertSame(RcsaCycle::CLOSED, $cycle->fresh()->status);

        // And calling the service directly still throws, the same as before
        // the lock was added — the guard is additive, not a replacement.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This cycle is already closed.');

        app(RcsaCycleService::class)->close($cycle->fresh(), $this->actor);
    }
}
