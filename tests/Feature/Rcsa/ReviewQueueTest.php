<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Services\Rcsa\RcsaReviewService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ORM review queue of §9.2 — age, BU, line count, above-appetite count and
 * a risk-weighted priority sort.
 *
 * The count that matters is above-appetite, and it is the one worth testing
 * hardest: it is computed in SQL from the band names the METHODOLOGY says are
 * above the ceiling, not by loading every line and asking. A queue whose
 * headline number is wrong is a queue reviewers stop trusting.
 */
class ReviewQueueTest extends ReviewTestCase
{
    #[Test]
    public function the_queue_carries_what_a_reviewer_needs_to_choose(): void
    {
        $assessment = $this->submittedAssessment(risks: 3, aboveAppetite: true);

        DB::table('rcsa_assessments')->where('id', $assessment->id)->update([
            'submitted_at' => now()->subDays(5),
        ]);

        $row = collect(app(RcsaReviewService::class)->queue())->firstWhere('id', $assessment->id);

        $this->assertNotNull($row);
        $this->assertSame($this->retail->name, $row['business_unit']);
        $this->assertSame(3, $row['lines_count']);
        $this->assertSame(3, $row['above_appetite_count']);
        $this->assertSame(5, $row['age_days']);
        $this->assertSame($this->actor->name, $row['submitted_by']);
        $this->assertSame(3 * RcsaReviewService::ABOVE_APPETITE_WEIGHT + 5, $row['priority']);
    }

    /**
     * Age is measured from FILING, not from creation. An assessment drafted in
     * January and submitted yesterday has not been waiting since January.
     */
    #[Test]
    public function age_is_measured_from_submission(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        DB::table('rcsa_assessments')->where('id', $assessment->id)->update([
            'created_at' => now()->subDays(90),
            'submitted_at' => now()->subDays(2),
        ]);

        $row = collect(app(RcsaReviewService::class)->queue())->firstWhere('id', $assessment->id);

        $this->assertSame(2, $row['age_days']);
    }

    #[Test]
    public function risk_outranks_age_and_an_escalation_outranks_both(): void
    {
        $service = app(RcsaReviewService::class);

        // Four above appetite, filed today, against nothing above appetite and
        // a working month of waiting.
        $this->assertGreaterThan(
            $service->priority(aboveAppetite: 0, ageDays: 20),
            $service->priority(aboveAppetite: 4, ageDays: 0),
        );

        $this->assertGreaterThan(
            $service->priority(aboveAppetite: 50, ageDays: 300),
            $service->priority(aboveAppetite: 0, ageDays: 0, escalated: true),
        );
    }

    #[Test]
    public function the_queue_holds_only_what_is_actually_waiting(): void
    {
        // Treasury too, so the cycle provisions a second assessment nobody has
        // filed — a cycle only provisions the units that have published risks.
        $this->publishedRisk(['risk_no' => 'TREAS-R1', 'business_unit_id' => $this->treasury->id]);

        $submitted = $this->submittedAssessment(risks: 1);

        // Every other assessment the cycle provisioned is still in progress,
        // and the queue is a list of what has been HANDED OVER — a unit still
        // typing into its grid is not the second line's problem yet.
        $inProgress = RcsaAssessment::query()->whereKeyNot($submitted->id)->get();
        $this->assertTrue($inProgress->isNotEmpty());

        $queue = app(RcsaReviewService::class)->queue();
        $ids = array_column($queue, 'id');

        $this->assertContains($submitted->id, $ids);

        foreach ($inProgress as $other) {
            $this->assertNotContains($other->id, $ids);
        }

        // And it leaves once it is decided.
        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $submitted));
        $this->actingAs($this->reviewer)->post(route('rcsa.review.validate', $submitted));

        $this->assertNotContains($submitted->id, array_column(app(RcsaReviewService::class)->queue(), 'id'));
    }

    /**
     * The queue must not draw a Review link onto a 403.
     *
     * `RcsaAssessmentPolicy::review()` refuses the submitter, so an assessor
     * who also holds `rcsa_assessment.review` — a risk-manager does — would
     * otherwise see their own filing listed and be refused on clicking it. Found
     * by opening the page as the assessor; nothing in this suite saw it,
     * because every other test here reviews as somebody else.
     */
    #[Test]
    public function the_service_does_not_offer_a_reviewer_their_own_submission_unless_asked(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->grant(self::REVIEW_PERMISSIONS);

        // The service's DEFAULT still excludes it — escalation and every other
        // caller get a queue of work the viewer may actually take. Only the
        // review screen opts in, to show the filer where their own work is.
        $this->assertNotContains(
            $assessment->id,
            array_column(app(RcsaReviewService::class)->queue(viewerId: $this->actor->id, viewer: $this->actor), 'id'),
        );

        // The route refuses it, as before.
        $this->actingAs($this->actor)->get(route('rcsa.review.show', $assessment))->assertForbidden();

        // And it is still there for everybody else.
        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('queue', 1));
    }

    #[Test]
    public function the_review_summary_counts_what_has_and_has_not_been_decided(): void
    {
        $assessment = $this->submittedAssessment(risks: 3, aboveAppetite: true);
        $lines = $this->linesOf($assessment);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.claim', $assessment));

        $this->actingAs($this->reviewer)->post(route('rcsa.review.lines.mark', [$assessment, $lines[0]]), [
            'verdict' => RcsaAssessmentLine::ORM_ACCEPTED,
        ]);

        $this->actingAs($this->reviewer)->post(route('rcsa.review.lines.challenge', [$assessment, $lines[1]]), [
            'body' => 'This one needs a second look at the control description.',
        ]);

        $summary = app(RcsaReviewService::class)->summary($assessment->refresh());

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['accepted']);
        $this->assertSame(1, $summary['flagged']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(3, $summary['above_appetite']);
        $this->assertSame($lines[2]->id, $summary['undecided'][0]['line_id']);
    }

    #[Test]
    public function the_queue_screen_renders_for_a_reviewer_and_404s_behind_the_flag(): void
    {
        $this->submittedAssessment(risks: 1);

        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('RcsaReview/Index')->has('queue', 1));

        config()->set('features.rcsa_v2', false);

        $this->actingAs($this->reviewer)->get(route('rcsa.review.index'))->assertNotFound();
    }

    /**
     * P4 wrote the snapshot and nothing read it. The reviewer is the first
     * person with a reason to want the document rather than the rows.
     */
    #[Test]
    public function the_reviewer_can_download_the_pdf_as_it_was_filed(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->assertNotNull($assessment->snapshot_path);

        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.snapshot', $assessment))
            ->assertOk()
            ->assertDownload();
    }

    #[Test]
    public function a_missing_snapshot_is_a_404_rather_than_a_fresh_render(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        \Illuminate\Support\Facades\Storage::disk('local')->delete($assessment->snapshot_path);

        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.snapshot', $assessment))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  RCSA-03: own filings are listed, flagged, and never actionable */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_screen_lists_the_viewers_own_filing_flagged_and_keeps_it_out_of_mine(): void
    {
        $assessment = $this->submittedAssessment(risks: 2, aboveAppetite: true);

        $this->grant(self::REVIEW_PERMISSIONS);

        $this->actingAs($this->actor)
            ->get(route('rcsa.review.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RcsaReview/Index')
                ->has('queue', 1)
                ->where('queue.0.id', $assessment->id)
                ->where('queue.0.filed_by_viewer', true)
                // Every field the table renders, priority included.
                ->where('queue.0.lines_count', 2)
                ->where('queue.0.above_appetite_count', 2)
                ->has('queue.0.priority')
                ->has('queue.0.age_days')
                ->has('mine', 0));

        // For anybody else the same row is not flagged.
        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.index'))
            ->assertInertia(fn ($page) => $page
                ->has('queue', 1)
                ->where('queue.0.filed_by_viewer', false));
    }

    #[Test]
    public function an_own_filing_claimed_by_the_viewer_never_appears_in_mine(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        // Whatever the row says about a reviewer, `mine` is claimable work.
        DB::table('rcsa_assessments')->where('id', $assessment->id)->update(['reviewer_id' => $this->actor->id]);

        $this->grant(self::REVIEW_PERMISSIONS);

        $this->actingAs($this->actor)
            ->get(route('rcsa.review.index'))
            ->assertInertia(fn ($page) => $page->has('queue', 1)->has('mine', 0));
    }

    #[Test]
    public function opening_ones_own_filing_explains_segregation_of_duties_with_a_403(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->grant(self::REVIEW_PERMISSIONS);

        $this->actingAs($this->actor)
            ->get(route('rcsa.review.show', $assessment))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('RcsaReview/SegregationOfDuties')
                ->where('assessment.id', $assessment->id)
                ->where('assessment.business_unit', 'Retail Banking')
                ->has('assessment.cycle')
                ->has('assessment.submitted_at')
                ->where('urls.queue', route('rcsa.review.index'))
                ->where('urls.assessment', route('rcsa.assessments.show', $assessment)));
    }

    #[Test]
    public function the_submitter_without_review_permission_gets_the_plain_403(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $response = $this->actingAs($this->actor)->get(route('rcsa.review.show', $assessment));

        $response->assertForbidden();
        $this->assertNotSame('RcsaReview/SegregationOfDuties', $response->original?->name ?? null);
        $this->assertStringNotContainsString('SegregationOfDuties', (string) $response->getContent());
    }

    #[Test]
    public function a_stranger_without_the_permission_gets_the_plain_403(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $outsider = $this->userWith(['rcsa_assessment.view']);

        $response = $this->actingAs($outsider)->get(route('rcsa.review.show', $assessment));

        $response->assertForbidden();
        $this->assertStringNotContainsString('SegregationOfDuties', (string) $response->getContent());
    }

    #[Test]
    public function a_reviewer_who_did_not_file_it_still_opens_it(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.show', $assessment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('RcsaReview/Show'));
    }

    /* ------------------------------------------------------------------ */
    /*  RCSA-08: "1 risks are locked" */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_submit_flash_says_one_risk_is_locked_not_one_risks_are(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);
        $assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
        $this->scoreAll($assessment);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 risk is locked')
                && ! str_contains($m, '1 risks'));
    }

    #[Test]
    public function the_submit_flash_pluralises_several_risks_and_the_bu_approval_branch(): void
    {
        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $this->publishedRisk(['risk_no' => 'RETAIL-R2']);
        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);
        $assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
        $this->scoreAll($assessment);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 risks are locked'));
    }

    #[Test]
    public function the_business_unit_approval_flash_says_one_risk_is_locked(): void
    {
        $this->organization->forceFill([
            'settings' => array_merge((array) $this->organization->settings, [
                'rcsa' => ['bu_approval_required' => true],
            ]),
        ])->save();
        $head = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.approve']);
        $this->retail->forceFill(['head_id' => $head->id])->save();

        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);
        $cycle = $this->makeCycle();
        app(\App\Services\Rcsa\RcsaCycleService::class)->open($cycle, $this->actor);
        $assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
        $this->scoreAll($assessment);

        $this->actingAs($this->actor)
            ->post(route('rcsa.assessments.submit', $assessment))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 risk is locked until they decide')
                && ! str_contains($m, '1 risks'));
    }
}
