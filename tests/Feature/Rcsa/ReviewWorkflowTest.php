<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\Rcsa\RcsaLineComment;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaWorkflowService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Steps 8 and 9 — the state machine, the review, and the one sentence P5 is
 * accepted on:
 *
 *     "Returned assessments reopen only the flagged lines."
 *
 * Most of what follows is that sentence taken apart: what counts as flagged,
 * what happens to everything else, and every route by which an assessor might
 * otherwise reach a line the ORM did not ask them to change.
 */
class ReviewWorkflowTest extends ReviewTestCase
{
    /* ------------------------------------------------------------------ */
    /*  The acceptance criterion */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function returning_reopens_only_the_flagged_lines(): void
    {
        $assessment = $this->submittedAssessment(risks: 3);
        $lines = $this->linesOf($assessment);

        // Every line locked at submission — that is the state a return works
        // against, and if it were not true the test below would pass for the
        // wrong reason.
        $this->assertCount(3, $lines->filter(fn (RcsaAssessmentLine $l) => $l->isLocked()));

        $flagged = $lines->first();
        $untouched = $lines->skip(1);

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.claim', $assessment))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.lines.challenge', [$assessment, $flagged]), [
                'body' => 'The control rating looks generous for a process with no maker-checker.',
                'verdict' => RcsaAssessmentLine::ORM_CHALLENGED,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.return', $assessment), [
                'reason' => 'One control rating needs defending before this can be validated.',
            ])
            ->assertSessionHasNoErrors();

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::RETURNED, $assessment->status);

        // THE WHOLE CRITERION, in three assertions.
        $this->assertNull($flagged->fresh()->locked_at, 'The flagged line should have reopened.');

        foreach ($untouched as $line) {
            $this->assertNotNull(
                $line->fresh()->locked_at,
                "Line {$line->risk_no} was not flagged and must stay locked.",
            );
        }

        $this->assertSame(1, $assessment->lines()->whereNull('locked_at')->count());
    }

    #[Test]
    public function an_unflagged_line_cannot_be_edited_after_a_return(): void
    {
        $assessment = $this->submittedAssessment(risks: 2);
        [$flagged, $locked] = $this->linesOf($assessment)->all();

        $this->flagAndReturn($assessment, $flagged);

        // The one the ORM asked about: editable.
        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $flagged]), [
                'inherent_likelihood' => 2,
            ])
            ->assertOk();

        // The one it did not: 423, and the message says why rather than
        // "forbidden".
        $response = $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $locked]), [
                'inherent_likelihood' => 4,
            ]);

        $response->assertStatus(423);
        $this->assertStringContainsString('did not flag', $response->json('message'));

        $this->assertSame(1, (int) $locked->fresh()->inherent_likelihood);
    }

    /**
     * The rule has to hold at every write, not only at the obvious one. A bulk
     * apply that ignored the lock would be the way round it, and so would an
     * action plan.
     */
    #[Test]
    public function neither_bulk_apply_nor_action_plans_reach_a_locked_line(): void
    {
        $assessment = $this->submittedAssessment(risks: 2);
        [$flagged, $locked] = $this->linesOf($assessment)->all();

        $this->flagAndReturn($assessment, $flagged);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.bulk-apply', $assessment), [
                'line_ids' => [$flagged->id, $locked->id],
                'control_effectiveness' => 'Not Achieved',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Not Achieved', $flagged->fresh()->control_effectiveness);
        $this->assertSame('Fully Achieved', $locked->fresh()->control_effectiveness);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.plans.store', [$assessment, $locked]), [
                'control_to_implement' => 'Something that should not be recordable here.',
                'owner_id' => $this->actor->id,
                'target_date' => now()->addMonth()->toDateString(),
            ])
            ->assertStatus(423);

        $this->assertSame(0, $locked->actionPlans()->count());
    }

    /**
     * The service refuses it too, not just the controller.
     */
    #[Test]
    public function the_save_service_refuses_a_locked_line_on_its_own(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $result = app(RcsaAssessmentService::class)->apply(
            $line,
            ['inherent_likelihood' => 4],
            $this->actor,
        );

        $this->assertSame(RcsaAssessmentService::LOCKED, $result['status']);
        $this->assertSame(1, (int) $line->fresh()->inherent_likelihood);
    }

    /**
     * A return with nothing flagged would reopen nothing, and hand the assessor
     * back an assessment they cannot change.
     */
    #[Test]
    public function returning_with_nothing_flagged_is_refused(): void
    {
        $assessment = $this->submittedAssessment(risks: 2);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.return', $assessment), [
                'reason' => 'I would like this looked at again in general terms.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(RcsaAssessment::UNDER_REVIEW, $assessment->fresh()->status);
        $this->assertSame(2, $assessment->lines()->whereNotNull('locked_at')->count());
    }

    #[Test]
    public function a_returned_assessment_can_be_fixed_and_resubmitted(): void
    {
        $assessment = $this->submittedAssessment(risks: 2);
        $flagged = $this->linesOf($assessment)->first();

        $this->flagAndReturn($assessment, $flagged);

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $flagged]), [
                'inherent_likelihood' => 3,
                'assessment_rationale' => 'Re-rated after the ORM challenge on maker-checker.',
            ])
            ->assertOk();

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHasNoErrors();

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::SUBMITTED, $assessment->status);
        // A resubmission must not still carry the reason it was sent back.
        $this->assertNull($assessment->returned_reason);
        // And everything locks again, including the line that had reopened.
        $this->assertSame(0, $assessment->lines()->whereNull('locked_at')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  The state machine (§9.1) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_move_that_is_not_an_edge_is_refused(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot become');

        // Submitted straight to closed: not an edge, and the point of declaring
        // them is that no caller can invent one.
        app(RcsaWorkflowService::class)->transition($assessment, RcsaAssessment::CLOSED, $this->actor);
    }

    #[Test]
    public function every_transition_is_logged_with_who_what_and_why(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->flagAndReturn($assessment, $line, reason: 'The rating does not match the control described.');

        $log = $assessment->transitions()->get()->sortBy('id')->values();

        // submit, claim, return.
        $this->assertGreaterThanOrEqual(3, $log->count());

        $submit = $log->firstWhere('event', RcsaAssessmentTransition::SUBMIT);
        $this->assertSame(RcsaAssessment::IN_PROGRESS, $submit->from_status);
        $this->assertSame(RcsaAssessment::SUBMITTED, $submit->to_status);
        $this->assertSame($this->actor->id, $submit->user_id);

        $return = $log->firstWhere('event', RcsaAssessmentTransition::RETURN);
        $this->assertSame(RcsaAssessment::UNDER_REVIEW, $return->from_status);
        $this->assertSame(RcsaAssessment::RETURNED, $return->to_status);
        $this->assertSame($this->reviewer->id, $return->user_id);
        $this->assertSame('The rating does not match the control described.', $return->reason);
        $this->assertNotNull($return->created_at);
    }

    #[Test]
    public function validating_moves_it_and_leaves_it_read_only(): void
    {
        $assessment = $this->submittedAssessment(risks: 2);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.validate', $assessment), ['reason' => 'Ratings are consistent with the controls.'])
            ->assertRedirect(route('rcsa.review.index'));

        $assessment->refresh();

        $this->assertSame(RcsaAssessment::VALIDATED, $assessment->status);
        $this->assertSame($this->reviewer->id, $assessment->reviewed_by);
        $this->assertNotNull($assessment->reviewed_at);
        $this->assertFalse($assessment->acceptsEdits());
        $this->assertSame(2, $assessment->lines()->whereNotNull('locked_at')->count());
    }

    #[Test]
    public function escalating_keeps_it_under_review_and_raises_its_priority(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.escalate', $assessment), [
                'reason' => 'The unit disputes the appetite ceiling and this needs the Head of ORM.',
            ])
            ->assertSessionHasNoErrors();

        $assessment->refresh();

        // A FLAG, NOT A STATE — §9.1's machine has no escalated state.
        $this->assertSame(RcsaAssessment::UNDER_REVIEW, $assessment->status);
        $this->assertTrue($assessment->isEscalated());
        $this->assertSame($this->reviewer->id, $assessment->escalated_by);

        // Logged as a from == to row.
        $row = $assessment->transitions()->where('event', RcsaAssessmentTransition::ESCALATE)->sole();
        $this->assertSame($row->from_status, $row->to_status);

        // And it clears when the assessment is finally decided.
        $this->actingAs($this->reviewer)->post(route('rcsa.review.validate', $assessment));
        $this->assertFalse($assessment->fresh()->isEscalated());
    }

    #[Test]
    public function a_second_reviewer_cannot_take_a_claimed_assessment_by_accident(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $other = $this->userWith(self::REVIEW_PERMISSIONS);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($other)
            ->post(route('rcsa.review.claim', $assessment))
            ->assertSessionHas('error');

        $this->assertSame($this->reviewer->id, $assessment->fresh()->reviewer_id);

        // Claiming again as the holder is a no-op, not a second transition.
        $before = $assessment->transitions()->count();
        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));
        $this->assertSame($before, $assessment->fresh()->transitions()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  The two-person control */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_person_who_submitted_cannot_review_their_own_assessment(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        // Every review permission there is, held by the assessor.
        $this->grant(self::REVIEW_PERMISSIONS);

        $this->actingAs($this->actor)
            ->get(route('rcsa.review.show', $assessment))
            ->assertForbidden();

        $this->actingAs($this->actor)
            ->post(route('rcsa.review.validate', $assessment))
            ->assertForbidden();
    }

    #[Test]
    public function reviewing_and_deciding_are_different_authorities(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        // The ORM Analyst of §11: challenges everything, decides nothing.
        $analyst = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.review']);

        $this->actingAs($analyst)->get(route('rcsa.review.show', $assessment))->assertOk();

        $this->actingAs($analyst)
            ->post(route('rcsa.review.validate', $assessment), ['reason' => 'Looks right to me.'])
            ->assertForbidden();

        $this->actingAs($analyst)
            ->post(route('rcsa.review.return', $assessment), ['reason' => 'Please look at this again.'])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  The challenge (§9.2) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_challenge_records_a_suggestion_without_applying_it(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.lines.challenge', [$assessment, $line]), [
                'body' => 'A manual reconciliation is not "Fully Achieved" — I would call this Partially Achieved.',
                'verdict' => RcsaAssessmentLine::ORM_CHALLENGED,
                'suggested' => ['inherent_likelihood' => 4, 'control_effectiveness' => 'Partially Achieved'],
            ])
            ->assertSessionHasNoErrors();

        $comment = RcsaLineComment::sole();

        $this->assertSame(RcsaLineComment::CHALLENGE, $comment->type);
        $this->assertSame(4, $comment->suggested_values['inherent_likelihood']);

        // NOTHING APPLIED IT. The line still says what the business said.
        $line->refresh();
        $this->assertSame(1, (int) $line->inherent_likelihood);
        $this->assertSame('Fully Achieved', $line->control_effectiveness);
        $this->assertSame(RcsaAssessmentLine::ORM_CHALLENGED, $line->orm_status);
        $this->assertSame($this->reviewer->id, $line->orm_reviewer_id);
    }

    #[Test]
    public function a_flag_with_no_words_is_refused(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.lines.challenge', [$assessment, $line]), ['body' => 'no'])
            ->assertSessionHasErrors('body');

        $this->assertSame(RcsaAssessmentLine::ORM_PENDING, $line->fresh()->orm_status);
    }

    #[Test]
    public function a_suggested_rating_is_validated_against_the_lines_own_methodology(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)
            ->post(route('rcsa.review.lines.challenge', [$assessment, $line]), [
                'body' => 'This should be a seven on the five-point scale, obviously.',
                'suggested' => ['inherent_likelihood' => 7],
            ])
            ->assertSessionHasErrors('suggested.inherent_likelihood');
    }

    #[Test]
    public function accepting_a_line_does_not_erase_what_was_said_about_it(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)->post(route('rcsa.review.lines.challenge', [$assessment, $line]), [
            'body' => 'I initially thought this rating was too kind.',
        ]);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.lines.mark', [$assessment, $line]), [
            'verdict' => RcsaAssessmentLine::ORM_ACCEPTED,
        ]);

        $this->assertSame(RcsaAssessmentLine::ORM_ACCEPTED, $line->fresh()->orm_status);
        // The argument that produced the answer stays in the record.
        $this->assertSame(1, RcsaLineComment::count());

        // And an accepted line is not reopened by a later return.
        $this->assertFalse($line->fresh()->isFlaggedByOrm());
    }

    #[Test]
    public function the_assessor_can_answer_a_challenge_on_a_reopened_line(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $line = $this->linesOf($assessment)->sole();

        $this->flagAndReturn($assessment, $line);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.lines.respond', [$assessment, $line]), [
                'body' => 'The reconciliation is automated in the core system — evidence attached to the control.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, RcsaLineComment::count());
        $this->assertSame(RcsaLineComment::RESPONSE, RcsaLineComment::latest('id')->first()->type);

        // The reviewer is told their challenge was answered.
        $this->assertSame(
            1,
            DB::table('notifications_log')
                ->where('type', 'rcsa.line.responded')
                ->where('user_id', $this->reviewer->id)
                ->count(),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function flagAndReturn(
        RcsaAssessment $assessment,
        RcsaAssessmentLine $line,
        string $reason = 'One rating needs defending before this can be validated.',
    ): void {
        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)->post(route('rcsa.review.lines.challenge', [$assessment, $line]), [
            'body' => 'The control rating looks generous for a process with no maker-checker.',
            'verdict' => RcsaAssessmentLine::ORM_CHALLENGED,
        ]);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.return', $assessment), ['reason' => $reason]);
    }
}
