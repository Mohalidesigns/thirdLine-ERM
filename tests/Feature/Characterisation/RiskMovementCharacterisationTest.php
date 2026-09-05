<?php

namespace Tests\Feature\Characterisation;

use App\Models\Risk;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * The analysis screens' movement and trend figures (migration Phase 5.1).
 *
 * Written against the RUNNING BLADE SCREENS before `RiskMovementService`
 * existed: a throwaway test hit risk.analysis.heatmap and risk.analysis.trends
 * and dumped `$response->original->getData()`, and the numbers asserted here
 * are the numbers that came back. 3.5 spent a day on a figure that "should"
 * have been 50 and had always been 38; this file is what stops that.
 *
 * THE FIXTURE IS A FIXED REGISTER ON A FROZEN CLOCK. Every one of these
 * builders counts risks per quarter or per month against `now()`, so without
 * `setTestNow` the labels move with the calendar and the assertions rot.
 *
 * WHAT THIS FILE DELIBERATELY PINS AS WRONG. `buildRiskMovementData()` and the
 * four trend builders select `where('created_at', '<=', $end)` and then read
 * the risk's CURRENT score. That is not a point-in-time read: it counts the
 * risks that EXISTED by that date and rates every one of them as it stands
 * TODAY. A risk rated Low in January and Critical in June is counted Critical
 * in January too, so a "movement" chart drawn from it can only ever slope
 * upward as the register grows, and can never show a risk moving. The
 * assertions below record that behaviour exactly for a register that has never
 * been assessed through the measure engine — which is the compatibility
 * property that makes the fix safe: with no measured history there is nothing
 * to read as-at, so every number is unchanged.
 *
 * `a_risk_that_moved_between_bands_now_shows_as_moved` is the one that proves
 * the fix does something. Before Phase 5.1 it was impossible for it to pass.
 */
class RiskMovementCharacterisationTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-quarter, so the four quarters the movement chart walks back are
        // whole and the labels are stable.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-15 09:00:00'));

        $this->bootDomainFixtures();

        Permission::findOrCreate('analysis.view');
        $this->actor->givePermissionTo('analysis.view');

        // A register whose risks were created in known quarters, scored across
        // all four default bands: Low 1-4, Medium 5-11, High 12-19,
        // Critical 20-25.
        $this->risk('2025-11-10', 5, 5);   // 25 critical, before the window
        $this->risk('2026-01-20', 4, 4);   // 16 high,     Q1
        $this->risk('2026-02-14', 3, 2);   // 6  medium,   Q1
        $this->risk('2026-05-06', 2, 2);   // 4  low,      Q2
        $this->risk('2026-07-01', 4, 5);   // 20 critical, Q3
        $this->risk('2026-08-01', 1, 3);   // 3  low,      Q3
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Four quarters back from 2026-08-15: Q3 2025, Q4 2025, Q1 2026, Q2 2026,
     * Q3 2026 — the loop runs `$q = 3 … 0`, so four labels ending at the
     * current quarter.
     */
    #[Test]
    public function the_movement_chart_counts_the_register_as_it_stood_each_quarter(): void
    {
        $data = $this->heatmapData()['movementData'];

        $this->assertSame(['Q4 2025', 'Q1 2026', 'Q2 2026', 'Q3 2026'], $data['labels']);

        // CUMULATIVE, because the filter is `created_at <= end of quarter` and
        // nothing is ever removed from the count.
        $this->assertSame([1, 1, 1, 2], $data['critical']);
        $this->assertSame([0, 1, 1, 1], $data['high']);
        $this->assertSame([0, 1, 1, 1], $data['medium']);
        $this->assertSame([0, 0, 1, 2], $data['low']);

        // Every risk lands in exactly one band in every quarter it existed.
        foreach (array_keys($data['labels']) as $index) {
            $this->assertSame(
                [1, 3, 4, 6][$index],
                $data['critical'][$index] + $data['high'][$index] + $data['medium'][$index] + $data['low'][$index],
                'The four bands must partition the register, not overlap or leak.',
            );
        }
    }

    /**
     * The band edges are the default 5×5 profile's: Low 1-4, Medium 5-11,
     * High 12-19, Critical 20-25. The controller hard-codes `>= 20 / >= 12 /
     * >= 5` rather than reading them, which agrees with the default profile
     * and with no other — pinned so the switch to the profile is provably a
     * no-op here.
     */
    #[Test]
    public function the_bands_are_the_default_five_by_five_edges(): void
    {
        $data = $this->heatmapData()['movementData'];
        $last = count($data['labels']) - 1;

        // Scores in the register: 25, 20 critical; 16 high; 6 medium; 4, 3 low.
        $this->assertSame(2, $data['critical'][$last], '20 and 25 are Critical; 19 would not be.');
        $this->assertSame(1, $data['high'][$last], '16 is High.');
        $this->assertSame(1, $data['medium'][$last], '6 is Medium; 4 would not be.');
        $this->assertSame(2, $data['low'][$last], '3 and 4 are Low.');
    }

    #[Test]
    public function the_trends_screen_reports_its_headline_figures(): void
    {
        $data = $this->trendsData();

        $this->assertSame(6, $data['totalActiveRisks']);
        $this->assertEqualsWithDelta(12.333, (float) $data['avgRiskScore'], 0.001, '(25+16+6+4+20+3)/6');

        // The window defaults to the last twelve months, so the 2025-11-10
        // risk is inside it and all six are counted as new.
        $this->assertSame('2025-08-15', $data['fromValue']);
        $this->assertSame('2026-08-15', $data['toValue']);
        $this->assertSame(6, $data['newRisks']);
        $this->assertSame(0, $data['closedRisks']);
    }

    /**
     * The monthly rating series is the same cumulative shape as the quarterly
     * one, for the same reason.
     */
    #[Test]
    public function the_rating_trend_is_cumulative_over_the_window(): void
    {
        $series = $this->trendsData()['ratingTrendData'];

        $this->assertSame('Aug 2025', $series['labels'][0]);
        $this->assertSame('Aug 2026', $series['labels'][count($series['labels']) - 1]);
        $this->assertCount(13, $series['labels'], 'Twelve months back, inclusive of both ends.');

        // Nothing existed in August 2025; everything does by August 2026.
        $this->assertSame(0, $series['critical'][0] + $series['high'][0] + $series['medium'][0] + $series['low'][0]);

        $last = count($series['labels']) - 1;
        $this->assertSame(
            6,
            $series['critical'][$last] + $series['high'][$last] + $series['medium'][$last] + $series['low'][$last],
        );

        // Monotonic, necessarily: a risk is never uncounted once created.
        $totals = [];
        foreach ($series['labels'] as $i => $_) {
            $totals[] = $series['critical'][$i] + $series['high'][$i] + $series['medium'][$i] + $series['low'][$i];
        }
        $sorted = $totals;
        sort($sorted);
        $this->assertSame($sorted, $totals, 'The count can only ever grow — which is the defect, pinned.');
    }

    /**
     * The movers list is inherent minus residual, NOT movement over time.
     *
     * Worth pinning because the screen calls them "increasers" and
     * "decreasers": what it actually reports is the gap controls close on each
     * risk today. A risk whose score rose last month appears nowhere.
     */
    #[Test]
    public function the_movers_list_is_the_control_gap_not_a_movement_over_time(): void
    {
        $risk = Risk::where('inherent_score', 16)->firstOrFail();
        $risk->forceFill(['residual_likelihood' => 2, 'residual_impact' => 2])->saveQuietly();

        $data = $this->trendsData();

        $this->assertCount(1, $data['riskIncreasers']);
        $this->assertSame($risk->risk_code, $data['riskIncreasers'][0]->risk_code);
        $this->assertSame(12, $data['riskIncreasers'][0]->score_change, '16 inherent − 4 residual.');

        // A risk with no residual assessment is not a gap of zero, so it is
        // absent from both lists.
        $this->assertSame([], $data['riskDecreasers']);
    }

    /**
     * The point of the whole exercise.
     *
     * A risk assessed Low in Q1 and Critical in Q3 must be counted LOW in Q1's
     * bucket. Under the old builders it was counted Critical in every quarter
     * back to its creation, because they read today's score, so a chart titled
     * "Risk Movement" could not show one.
     */
    #[Test]
    public function a_risk_that_moved_between_bands_now_shows_as_moved(): void
    {
        $this->bootMeasureEngine();

        $mover = $this->makeRisk([
            'title' => 'Escalating settlement exposure',
            'inherent_likelihood' => 1,
            'inherent_impact' => 2,
            'inherent_score' => 2,
            'inherent_rating' => 'Low',
        ]);
        $mover->forceFill(['created_at' => '2026-01-05', 'updated_at' => '2026-01-05'])->saveQuietly();

        // Assessed Low in Q1, then Critical in Q3 — a real history in
        // measure_values, which is what the as-at read follows.
        $this->approveAssessment($mover, '2026-01-10', ['inherent_likelihood' => 1, 'inherent_impact' => 2]);
        $this->approveAssessment($mover, '2026-07-10', ['inherent_likelihood' => 5, 'inherent_impact' => 5]);

        $data = $this->heatmapData()['movementData'];

        $q1 = array_search('Q1 2026', $data['labels'], true);
        $q3 = array_search('Q3 2026', $data['labels'], true);

        $this->assertNotFalse($q1);
        $this->assertNotFalse($q3);

        // Q1: the mover is Low, alongside the fixture's own Q1 risks.
        // Q3: it has become Critical. The old code reported Critical in both.
        $this->assertGreaterThan(
            $data['critical'][$q1],
            $data['critical'][$q3],
            'A risk that rose from Low to Critical must raise the Critical count only from the quarter it rose.',
        );

        $this->assertSame(
            1,
            $data['critical'][$q1],
            'In Q1 the only Critical risk is the 25-scored one from 2025 — the mover was Low then.',
        );
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function heatmapData(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.analysis.heatmap'))
            ->assertOk()
            ->original->getData();
    }

    /** @return array<string, mixed> */
    private function trendsData(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.analysis.trends'))
            ->assertOk()
            ->original->getData();
    }

    private function risk(string $createdAt, int $likelihood, int $impact): Risk
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'inherent_score' => $likelihood * $impact,
            'inherent_rating' => match (true) {
                $likelihood * $impact >= 20 => 'Critical',
                $likelihood * $impact >= 12 => 'High',
                $likelihood * $impact >= 5 => 'Medium',
                default => 'Low',
            },
        ]);

        $risk->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $risk;
    }
}
