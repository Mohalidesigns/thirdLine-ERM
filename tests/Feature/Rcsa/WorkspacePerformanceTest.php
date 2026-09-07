<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * §12's P9 acceptance criterion: "p95 grid render under 1.5s at 500 lines",
 * with a 2,000-line assessment as the stress case.
 *
 * WHAT IS ASSERTED IS WHAT ACTUALLY REGRESSES. Wall-clock in CI is a
 * coin-flip — a shared runner under load will fail a 1.5s budget that a laptop
 * meets in 220ms, and a test that flakes is a test people re-run until it
 * passes. So the timing check here is deliberately generous, and the real
 * assertions are the two structural properties that make the page fast and
 * that a careless change would break silently:
 *
 *   1. THE QUERY COUNT DOES NOT GROW WITH THE NUMBER OF LINES. An eager load
 *      dropped from the controller turns one query into one per line, and on a
 *      500-line grid that is the difference between 220ms and a timeout. This
 *      is deterministic and machine-independent.
 *
 *   2. THE PAYLOAD IS BOUNDED WHERE IT CAN BE. P3 decided the whole assessment
 *      travels rather than a page of it — keyboard navigation cannot paginate —
 *      and that decision stands. What must not travel is a list the screen
 *      never renders: the blocking-issues panel shows forty and summarises the
 *      rest, so the server caps at fifty and sends the true total separately.
 *      Measured before that cap, `outstanding` was 297 KB of a 3.6 MB page.
 *
 * The wall-clock measurement that produced the number in the phase note was
 * taken against MySQL with a real dataset; these run on SQLite, which is why
 * the budget here is a smoke check rather than the acceptance figure.
 */
class WorkspacePerformanceTest extends CycleTestCase
{
    /**
     * A cycle with an assessment of `$lines` scored rows, inserted in bulk.
     *
     * Not built through RcsaCycleService: provisioning 2,000 lines through the
     * normal path is what the service is for, not what this measures, and it
     * would put a minute of fixture-building in front of every assertion.
     */
    private function assessmentWith(int $lines): RcsaAssessment
    {
        $cycle = RcsaCycle::create([
            'organization_id' => $this->organization->id,
            'name' => "Perf {$lines}",
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'methodology_id' => $this->methodology()->id,
            'status' => RcsaCycle::OPEN,
        ]);

        $assessment = RcsaAssessment::create([
            'organization_id' => $this->organization->id,
            'cycle_id' => $cycle->id,
            'business_unit_id' => $this->retail->id,
            'status' => RcsaAssessment::IN_PROGRESS,
        ]);

        $rows = [];

        for ($i = 1; $i <= $lines; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $this->organization->id,
                'assessment_id' => $assessment->id,
                'business_unit_id' => $this->retail->id,
                'risk_no' => "PERF-R{$i}",
                'business_unit_name' => $this->retail->name,
                'process_name' => 'Loan Origination & Disbursement',
                'potential_risk' => "Performance fixture risk {$i}, of realistic length so the payload measured "
                    .'here resembles the one a bank actually downloads.',
                'existing_control' => 'An existing control, described at the length a workbook cell holds.',
                'risk_category' => 'Operational',
                'inherent_likelihood' => ($i % 5) + 1,
                'inherent_impact' => ($i % 5) + 1,
                'control_effectiveness' => 'Partially Achieved',
                'inherent_score' => (($i % 5) + 1) ** 2,
                'inherent_level' => 'medium',
                'residual_score' => 6.25,
                'residual_level' => 'medium',
                'risk_treatment' => 'mitigate',
                'appetite_status' => 'Above risk appetite: Mitigate',
                'methodology_id' => $this->methodology()->id,
                'version' => 1,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('rcsa_assessment_lines')->insert($chunk);
        }

        $this->makeRelationsNonTrivial($assessment);

        return $assessment;
    }

    /**
     * Give every line a prior line and an action plan.
     *
     * WITHOUT THIS THE N+1 GUARD GUARDS NOTHING, which the first version of it
     * did not: with `prior_cycle_line_id` null on every row, Eloquent
     * short-circuits a `belongsTo` before issuing any query, and with no action
     * plans the relation is never touched. Removing either eager load from the
     * controller left the query count completely unchanged — a green test over
     * a page that had just become one query per line.
     *
     * So the fixture makes both relations real: each line points at its
     * predecessor, and each carries a plan. Now a dropped eager load is a
     * lazy load per row, which is what the assertion is for.
     */
    private function makeRelationsNonTrivial(RcsaAssessment $assessment): void
    {
        $ids = DB::table('rcsa_assessment_lines')
            ->where('assessment_id', $assessment->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $previous = null;

        foreach ($ids as $id) {
            if ($previous !== null) {
                DB::table('rcsa_assessment_lines')->where('id', $id)->update(['prior_cycle_line_id' => $previous]);
            }

            $previous = $id;
        }

        $plans = [];

        foreach ($ids as $i => $id) {
            $plans[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $this->organization->id,
                'line_id' => $id,
                'control_to_implement' => 'A control that will be put in place, described at realistic length.',
                'owner_id' => $this->actor->id,
                'target_date' => now()->addMonths(3)->toDateString(),
                'status' => \App\Models\Rcsa\RcsaActionPlan::OPEN,
                'progress_pct' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($plans, 250) as $chunk) {
            DB::table('rcsa_action_plans')->insert($chunk);
        }
    }

    /**
     * @return array{queries: int, ms: float, props: array<string, mixed>}
     */
    private function render(RcsaAssessment $assessment): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = microtime(true);

        $response = $this->actingAs($this->actor)->get(route('rcsa.assessments.show', $assessment));

        $ms = (microtime(true) - $started) * 1000;
        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        $response->assertOk();

        return [
            'queries' => $queries,
            'ms' => $ms,
            'props' => $response->viewData('page')['props'] ?? [],
        ];
    }

    /**
     * THE ONE THAT MATTERS. A dropped eager load turns one query into one per
     * line, and nothing else in the suite would notice.
     */
    #[Test]
    public function the_query_count_does_not_grow_with_the_number_of_lines(): void
    {
        $twenty = $this->assessmentWith(20);
        $fiveHundred = $this->assessmentWith(500);

        // THE FIRST RENDER OF THE PROCESS IS NOT THE ONE TO MEASURE. It pays
        // for the permission cache, the methodology and the container — 299
        // queries against 22 for the very next call, which reads as a
        // catastrophic N+1 and is nothing of the sort. Warm first, then compare
        // steady states.
        $this->render($twenty);

        $small = $this->render($twenty);
        $large = $this->render($fiveHundred);

        $this->assertSame(
            $small['queries'],
            $large['queries'],
            sprintf(
                'The workspace issued %d queries for 20 lines and %d for 500. That is an N+1 — almost '
                .'certainly an eager load dropped from AssessmentController::show().',
                $small['queries'],
                $large['queries'],
            ),
        );

        // A ceiling as well as a comparison: equal-but-enormous would pass the
        // assertion above and still be a bad page.
        $this->assertLessThan(60, $large['queries'], 'The workspace is issuing far more queries than it needs.');
    }

    #[Test]
    public function the_blocking_issue_list_is_capped_but_the_count_is_not(): void
    {
        // Every line is above appetite with no action plan, so every line
        // produces an issue — the worst case, and the one that measured 297 KB.
        $assessment = $this->assessmentWith(500);

        $outstanding = $this->render($assessment)['props']['outstanding'];

        // The exact total depends on which rules each fixture line trips, which
        // is not what this is about. What matters is that the count is the TRUE
        // one and the list is the capped one.
        $actual = count(app(\App\Services\Rcsa\RcsaAssessmentService::class)->outstanding($assessment)['issues']);

        $this->assertGreaterThan(50, $actual, 'The fixture must produce more issues than the cap, or this proves nothing.');
        $this->assertSame($actual, $outstanding['issue_count'], 'The true total must not be capped.');
        $this->assertCount(50, $outstanding['issues'], 'The list sent to the screen must be capped.');

        // And the cap must not weaken the gate: the server still sees all 500.
        $this->assertFalse(
            app(\App\Services\Rcsa\RcsaSubmissionService::class)->canSubmit($assessment, $this->actor->id),
        );
    }

    /**
     * The acceptance criterion, as a smoke check.
     *
     * Generous on purpose — see the class comment. A shared CI runner is not
     * the machine the 1.5s figure was measured on, and a flaky performance
     * test is one nobody believes.
     */
    #[Test]
    public function a_five_hundred_line_grid_renders_well_inside_the_budget(): void
    {
        $assessment = $this->assessmentWith(500);

        // Warm: the first render pays for the methodology and the container.
        $this->render($assessment);

        $result = $this->render($assessment);

        $this->assertLessThan(
            5000,
            $result['ms'],
            sprintf('A 500-line grid took %.0f ms to render, which is far outside any plausible budget.', $result['ms']),
        );

        $this->assertCount(500, $result['props']['lines']);
    }

    /**
     * The stress case §12 names. Not timed — only that it completes and stays
     * structurally sane at four times the acceptance size.
     */
    #[Test]
    public function a_two_thousand_line_assessment_renders(): void
    {
        $assessment = $this->assessmentWith(2000);

        // Warm, for the reason above.
        $this->render($assessment);

        $result = $this->render($assessment);

        $this->assertCount(2000, $result['props']['lines']);
        $this->assertGreaterThan(50, $result['props']['outstanding']['issue_count']);
        $this->assertCount(50, $result['props']['outstanding']['issues']);
        $this->assertLessThan(60, $result['queries']);
    }
}
