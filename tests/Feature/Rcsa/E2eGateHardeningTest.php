<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaLineRevision;
use App\Models\User;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaReviewService;
use App\Services\Rcsa\RcsaSubmissionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate-1 hardening for the RCSA E2E fix batch (2026-10-05): the isolation and
 * "does it do only what it says" cases the batch's own tests do not pin.
 */
class E2eGateHardeningTest extends ReviewTestCase
{
    private function opened(int $risks = 1): RcsaAssessment
    {
        for ($i = 1; $i <= $risks; $i++) {
            $this->publishedRisk(['risk_no' => "RETAIL-R{$i}"]);
        }

        app(RcsaCycleService::class)->open($this->makeCycle(), $this->actor);

        return RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();
    }

    /** Put a line in the state the lost-update race used to leave behind. */
    private function breakLine(RcsaAssessmentLine $line, array $extra = []): void
    {
        DB::table('rcsa_assessment_lines')->where('id', $line->id)->update($extra + [
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'control_effectiveness' => 'Partially Achieved',
            'inherent_score' => null,
            'inherent_level' => null,
            'ce_modifier' => null,
            'residual_score' => null,
            'residual_level' => null,
            'risk_treatment' => null,
            'appetite_status' => null,
        ]);
    }

    /** Move a whole opened cycle (cycle, assessment, lines) into the other tenant, behind the app's back. */
    private function moveToOtherTenant(RcsaAssessment $assessment): void
    {
        foreach (['rcsa_assessment_lines' => 'assessment_id', 'rcsa_assessments' => 'id'] as $table => $key) {
            DB::table($table)->where($key, $assessment->id)->update(['organization_id' => $this->otherOrg->id]);
        }
        DB::table('rcsa_cycles')->where('id', $assessment->cycle_id)->update(['organization_id' => $this->otherOrg->id]);
    }

    /* ------------------------------------------------------------------ */
    /*  RCSA-05: the assignee list never crosses a tenant */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_assignee_list_never_names_a_user_of_another_organisation(): void
    {
        $assessment = $this->opened();

        $foreigner = TenantContext::bypass(function () {
            $user = User::create([
                'name' => 'Foreign Assessor',
                'email' => 'foreign-'.uniqid().'@example.test',
                'password' => 'password',
                'organization_id' => $this->otherOrg->id,
                'is_active' => true,
            ]);
            $this->grant(['rcsa_assessment.complete'], $user);

            return $user;
        });

        $this->actingAs($this->actor)
            ->get(route('rcsa.cycles.show', $assessment->cycle_id))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($foreigner) {
                $props = $page->toArray()['props'];

                $this->assertNotContains($foreigner->id, array_column($props['assignees'], 'id'));
                $this->assertNotContains('Foreign Assessor', array_column($props['assignees'], 'name'));
                // The actor (who holds `complete`) is on the list, so the list is not simply empty.
                $this->assertContains($this->actor->id, array_column($props['assignees'], 'id'));
            });
    }

    /* ------------------------------------------------------------------ */
    /*  RCSA-01: the repair path is tenant-bound and never reaches a locked line */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_recompute_command_for_one_organisation_does_not_touch_another(): void
    {
        $assessment = $this->opened();
        $line = $assessment->lines()->sole();
        $this->breakLine($line);
        $this->moveToOtherTenant($assessment);

        // Run for OUR organisation: the broken line now belongs to the other bank.
        $this->assertSame(0, Artisan::call('rcsa:recompute-scores', ['--org' => $this->organization->id]));
        $this->assertStringContainsString('0 line(s) repaired', Artisan::output());

        $this->assertNull(DB::table('rcsa_assessment_lines')->where('id', $line->id)->value('residual_level'));
        $this->assertSame(0, RcsaLineRevision::withoutGlobalScopes()->where('line_id', $line->id)->count());

        // And for the right organisation it does repair it, with that tenant's context
        // restored afterwards rather than cleared.
        TenantContext::set($this->organization->id);
        $this->assertSame(0, Artisan::call('rcsa:recompute-scores', ['--org' => $this->otherOrg->id]));
        $this->assertStringContainsString('1 line(s) repaired', Artisan::output());
        $this->assertSame($this->organization->id, TenantContext::organizationIdOrNull());

        $this->assertNotNull(DB::table('rcsa_assessment_lines')->where('id', $line->id)->value('residual_level'));
    }

    #[Test]
    public function the_service_repair_is_scoped_to_the_assessments_own_organisation(): void
    {
        $assessment = $this->opened();
        $line = $assessment->lines()->sole();
        $this->breakLine($line);

        // A copy of the assessment that claims another organisation, as a caller with
        // no tenant context could construct: its id is real, its organisation is not.
        $impostor = $assessment->replicate();
        $impostor->id = $assessment->id;
        $impostor->organization_id = $this->otherOrg->id;

        $this->assertSame(0, app(RcsaAssessmentService::class)->recomputeUnscored($impostor));
        $this->assertNull($line->fresh()->residual_level);
    }

    #[Test]
    public function submit_never_repairs_or_unlocks_a_locked_line(): void
    {
        $assessment = $this->opened(2);
        $this->scoreAll($assessment);
        [$locked] = $assessment->lines()->orderBy('id')->get()->all();

        // A returned assessment: one line stayed locked (the ORM did not flag it) with no scores.
        $this->breakLine($locked, ['locked_at' => now()]);
        $assessment->refresh()->forceFill(['status' => RcsaAssessment::RETURNED])->save();
        $revisions = RcsaLineRevision::where('line_id', $locked->id)->count();
        $version = $locked->fresh()->version;

        // A locked line is no longer a blocker (it was filed as it stands and its
        // assessor cannot touch it), so the resubmission goes through — and must
        // still not repair it. See a_returned_assessment_with_a_locked_unscored_line_can_still_be_resubmitted.
        $result = app(RcsaSubmissionService::class)->submit($assessment->refresh(), $this->actor);

        $this->assertSame(RcsaAssessment::SUBMITTED, $result['status']);

        $after = $locked->fresh();
        $this->assertNull($after->residual_level, 'a locked line is evidence and is not rewritten by a submit');
        $this->assertNotNull($after->locked_at);
        $this->assertSame($version, $after->version);
        $this->assertSame($revisions, RcsaLineRevision::where('line_id', $locked->id)->count());
    }

    #[Test]
    public function a_returned_assessment_with_a_locked_unscored_line_can_still_be_resubmitted(): void
    {
        $assessment = $this->opened(2);
        $this->scoreAll($assessment);
        [$locked, $reopened] = $assessment->lines()->orderBy('id')->get()->all();

        // The pre-fix broken state: a line the ORM did NOT flag (still locked)
        // holding its answers but no scores. Its assessor cannot re-save it (423)
        // and a submit never rewrites it — so it must not be a blocker either.
        $this->breakLine($locked, ['locked_at' => now()]);
        DB::table('rcsa_assessment_lines')->where('id', $reopened->id)->update(['locked_at' => null]);
        $assessment->refresh()->forceFill(['status' => RcsaAssessment::RETURNED])->save();
        $revisions = RcsaLineRevision::where('line_id', $locked->id)->count();

        $this->assertSame([], app(RcsaAssessmentService::class)->outstanding($assessment)['issues']);

        $this->actingAs($this->actor)
            ->patchJson(route('rcsa.assessments.lines.update', [$assessment, $locked]), ['version' => $locked->fresh()->version])
            ->assertStatus(423);

        $result = app(RcsaSubmissionService::class)->submit($assessment->refresh(), $this->actor);

        $this->assertSame(RcsaAssessment::SUBMITTED, $result['status']);
        $this->assertNull($locked->fresh()->residual_level, 'filed as it stands; repaired by the data fix, not by a submit');
        $this->assertSame($revisions, RcsaLineRevision::where('line_id', $locked->id)->count());
    }

    #[Test]
    public function an_unlocked_unscored_line_is_still_a_blocker(): void
    {
        $assessment = $this->opened(1);
        $line = $assessment->lines()->sole();
        $this->breakLine($line);

        $this->assertSame(
            ['unscored'],
            array_column(app(RcsaAssessmentService::class)->outstanding($assessment)['issues'], 'type'),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  RCSA-03: own filings are subject to the same scope as everything else */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_own_filing_outside_the_viewers_units_is_not_listed(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        // The filer is reassigned to Treasury only: Retail is no longer theirs to see.
        DB::table('business_unit_user')->where('user_id', $this->actor->id)->delete();
        $this->assign($this->actor, [$this->treasury]);
        $this->grant(self::REVIEW_PERMISSIONS);

        $ids = array_column(
            app(RcsaReviewService::class)->queue(viewerId: $this->actor->id, viewer: $this->actor->fresh(), includeOwn: true),
            'id',
        );

        $this->assertNotContains($assessment->id, $ids);

        $this->actingAs($this->actor->fresh())
            ->get(route('rcsa.review.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('queue', 0));
    }

    #[Test]
    public function a_reviewer_of_another_tenant_gets_a_plain_404_not_the_segregation_page(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);
        $this->grant(self::REVIEW_PERMISSIONS);

        $this->moveToOtherTenant($assessment);

        $response = $this->actingAs($this->actor)->get(route('rcsa.review.show', $assessment->id));

        $response->assertNotFound();
        $this->assertStringNotContainsString('SegregationOfDuties', (string) $response->getContent());
        $this->assertStringNotContainsString('Retail Banking', (string) $response->getContent());
    }

    #[Test]
    public function the_segregation_page_is_for_the_submitter_only_and_a_second_reviewer_is_not_shown_it(): void
    {
        $assessment = $this->submittedAssessment(risks: 1);

        // The reviewer did not file it: they get the real review page, not the explanation.
        $this->actingAs($this->reviewer)
            ->get(route('rcsa.review.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('RcsaReview/Show'));

        // And a reviewer of the same tenant who is outside the unit's scope is refused plainly.
        $outside = $this->userWith(self::REVIEW_PERMISSIONS, units: [$this->treasury]);
        $response = $this->actingAs($outside)->get(route('rcsa.review.show', $assessment));

        $response->assertForbidden();
        $this->assertStringNotContainsString('SegregationOfDuties', (string) $response->getContent());
    }
}
