<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCategoryAppetite;
use App\Models\Rcsa\RcsaMethodology;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaDashboardService;
use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * §14 Q4 — appetite as a statement per risk category.
 *
 * The question the plan asked the bank and never got an answer to. The build's
 * answer is a switch, and these tests are mostly about the switch being OFF:
 * the first two pin that nothing moved for a tenant who never sets it, because
 * a follow-up that quietly re-rates a live portfolio is worse than one that
 * ships late.
 *
 * The arithmetic throughout is one line — 3 × 3 Mostly Achieved, inherent 9,
 * modifier 75, residual 2.25 — which lands in LOW (2.01-4.00). The seeded house
 * ceiling is also LOW, so that line is exactly ON the ceiling and inside
 * appetite. Every test below moves the CEILING rather than the score, because
 * the point of Q4 is that the same risk carries a different obligation
 * depending on what it is a risk of.
 */
class CategoryAppetiteTest extends CycleTestCase
{
    private RcsaAssessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedRisk(['risk_no' => 'RETAIL-R1', 'risk_category' => 'Operational']);
        $this->publishedRisk(['risk_no' => 'RETAIL-R2', 'risk_category' => 'Compliance/Regulatory']);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $this->assessment = RcsaAssessment::query()
            ->where('business_unit_id', $this->retail->id)
            ->sole();
    }

    /* ------------------------------------------------------------------ */
    /*  The switch is off */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_seeded_methodology_is_in_single_mode(): void
    {
        $this->assertSame('single', $this->methodology()->appetite_mode);
        $this->assertSame('low', $this->methodology()->appetite_ceiling_level);
    }

    /**
     * The rows exist but the mode does not consult them.
     *
     * A tenant configuring per-category appetite fills the table first and
     * flips the mode once. If the rows took effect the moment they were saved,
     * a half-finished configuration would re-rate the portfolio mid-edit.
     */
    #[Test]
    public function category_rows_are_inert_until_the_mode_is_switched(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');

        $line = $this->score('RETAIL-R1');

        $this->assertFalse($line->above_appetite);
        $this->assertFalse($this->methodology()->isAboveAppetite('low', 'Operational'));
    }

    /* ------------------------------------------------------------------ */
    /*  The switch is on */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_tighter_category_ceiling_puts_the_same_score_above_appetite(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');
        $this->usePerCategoryMode();

        $line = $this->score('RETAIL-R1');

        // Identical inputs to the test above; only the ceiling moved.
        $this->assertSame('low', $line->residual_level);
        $this->assertTrue($line->above_appetite);
    }

    /**
     * The two halves of the point: one cycle, one methodology, one residual
     * band, two obligations.
     */
    #[Test]
    public function two_categories_on_one_band_can_differ(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');
        $this->setCategoryAppetite('Compliance/Regulatory', 'high');
        $this->usePerCategoryMode();

        $operational = $this->score('RETAIL-R1');
        $compliance = $this->score('RETAIL-R2');

        $this->assertSame($operational->residual_level, $compliance->residual_level);
        $this->assertTrue($operational->above_appetite);
        $this->assertFalse($compliance->above_appetite);
    }

    /* ------------------------------------------------------------------ */
    /*  The fallback, which is the safety property */
    /* ------------------------------------------------------------------ */

    /**
     * A CATEGORY WITH NO ROW IS GOVERNED BY THE HOUSE CEILING, NOT BY NOTHING.
     *
     * This is the test that matters most in the file. The alternative reading —
     * "no row means no ceiling" — would take a category out of every obligation
     * during a half-finished configuration, with nothing on any screen to say
     * it had happened. Here `Compliance` has no row, the house ceiling is LOW,
     * and a MEDIUM residual must still be above appetite.
     */
    #[Test]
    public function a_category_with_no_row_falls_back_to_the_house_ceiling(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');
        $this->usePerCategoryMode();

        $methodology = $this->methodology();

        $this->assertSame('low', $methodology->appetiteCeilingFor('Compliance/Regulatory'));
        $this->assertTrue($methodology->isAboveAppetite('medium', 'Compliance/Regulatory'));
        $this->assertFalse($methodology->isAboveAppetite('low', 'Compliance/Regulatory'));
    }

    /** Column H is nullable, and an uncategorised line is not an unlimited one. */
    #[Test]
    public function a_null_category_falls_back_to_the_house_ceiling(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');
        $this->usePerCategoryMode();

        $methodology = $this->methodology();

        $this->assertSame('low', $methodology->appetiteCeilingFor(null));
        $this->assertTrue($methodology->isAboveAppetite('medium', null));
    }

    /**
     * The fallback is safe, but it is not silent — a screen can ask which
     * categories are relying on it.
     */
    #[Test]
    public function the_gaps_in_a_partial_configuration_are_reportable(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');
        $this->usePerCategoryMode();

        $missing = $this->methodology()->categoriesWithoutAppetite(Template::RISK_CATEGORIES);

        $this->assertNotContains('Operational', $missing);
        $this->assertContains('Compliance/Regulatory', $missing);
        $this->assertCount(count(Template::RISK_CATEGORIES) - 1, $missing);
    }

    /** In single mode nothing is "missing", because nothing is consulted. */
    #[Test]
    public function single_mode_reports_no_gaps(): void
    {
        $this->assertSame([], $this->methodology()->categoriesWithoutAppetite(Template::RISK_CATEGORIES));
    }

    /* ------------------------------------------------------------------ */
    /*  The stored column, and what reads it */
    /* ------------------------------------------------------------------ */

    /**
     * The dashboard counts the stored verdict, so it follows per-category
     * appetite without knowing that per-category appetite exists.
     *
     * This is the whole reason `above_appetite` became a column: the headline
     * used to be `whereIn('residual_level', $bandsAboveTheCeiling)`, which
     * cannot express two ceilings at once and would report 0 here.
     */
    #[Test]
    public function the_dashboard_headline_follows_the_category_ceiling(): void
    {
        $this->setCategoryAppetite('Operational', 'very_low');
        $this->setCategoryAppetite('Compliance/Regulatory', 'high');
        $this->usePerCategoryMode();

        $this->score('RETAIL-R1');
        $this->score('RETAIL-R2');

        $headline = app(RcsaDashboardService::class)->headline((int) $this->assessment->cycle_id);

        $this->assertSame(2, $headline['risks']);
        $this->assertSame(1, $headline['above_appetite']);
    }

    /** An unscored line is unanswered, not "within appetite". */
    #[Test]
    public function an_unscored_line_is_null_rather_than_false(): void
    {
        $line = $this->line('RETAIL-R1');

        $this->assertNull($line->above_appetite);

        $headline = app(RcsaDashboardService::class)->headline((int) $this->assessment->cycle_id);

        $this->assertSame(0, $headline['above_appetite']);
    }

    /* ------------------------------------------------------------------ */
    /*  The browser mirror */
    /* ------------------------------------------------------------------ */

    /**
     * resources/js/lib/rcsa-calc.js applies the same category ceilings.
     *
     * The workspace shows a live verdict as the assessor types and the server
     * writes one when the line saves. If only the server learned about Q4, an
     * assessor would watch a risk sit inside appetite while typing and find it
     * above appetite after the save — the exact drift the mirror exists to
     * prevent, and the reason P0 sends the methodology to the client instead of
     * letting the client hold its own copy.
     *
     * Expectations come from the PHP engine rather than being written out by
     * hand, because the claim under test is "these two agree", not "both match
     * a third list I typed".
     */
    #[Test]
    public function the_browser_mirror_applies_the_same_category_ceilings(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            $this->markTestSkipped('node is not available, so the browser mirror cannot be exercised.');
        }

        $this->setCategoryAppetite('Operational', 'very_low');
        $this->setCategoryAppetite('Compliance/Regulatory', 'high');
        $this->usePerCategoryMode();

        $methodology = RcsaMethodology::withoutGlobalScopes()
            ->with(['scaleItems', 'bands', 'categoryAppetites'])
            ->findOrFail($this->methodology()->id);

        $calculator = app(\App\Services\Rcsa\RcsaCalculationService::class);
        $cases = [];

        // Every category the configuration treats differently, including one
        // with no row and the null case, across the bands either side of each
        // ceiling.
        foreach (['Operational', 'Compliance/Regulatory', 'Legal', null] as $category) {
            foreach ([[3, 3], [5, 5], [1, 1]] as [$likelihood, $impact]) {
                foreach (['Mostly Achieved', 'Not Achieved'] as $control) {
                    $result = $calculator->calculate(
                        likelihood: $likelihood,
                        impact: $impact,
                        controlEffectiveness: $control,
                        methodology: $methodology,
                        riskCategory: $category,
                    );

                    $cases[] = [
                        'likelihood' => $likelihood,
                        'impact' => $impact,
                        'controlEffectiveness' => $control,
                        'riskCategory' => $category,
                        'expected' => [
                            'residualLevel' => $result->residualLevel,
                            'actionPlanRequired' => $result->actionPlanRequired,
                            'aboveAppetite' => $result->aboveAppetite,
                        ],
                    ];
                }
            }
        }

        $process = new Process(
            [$node, base_path('tests/Support/rcsa-calc-runner.mjs')],
            base_path(),
            null,
            json_encode([
                'methodology' => $methodology->toCalculatorPayload(),
                'cases' => $cases,
            ], JSON_THROW_ON_ERROR),
            60,
        );

        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "The browser mirror disagreed on per-category appetite:\n"
                .$process->getOutput().$process->getErrorOutput(),
        );

        $report = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(24, $report['checked']);
        $this->assertSame(72, $report['compared']);
        $this->assertSame([], $report['mismatches']);

        // The fixture has to contain both verdicts, or this passes by agreeing
        // that nothing is ever above appetite.
        $verdicts = array_column(array_column($cases, 'expected'), 'aboveAppetite');
        $this->assertContains(true, $verdicts);
        $this->assertContains(false, $verdicts);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function setCategoryAppetite(string $category, string $ceiling): void
    {
        RcsaCategoryAppetite::create([
            'methodology_id' => $this->methodology()->id,
            'risk_category' => $category,
            'ceiling_level' => $ceiling,
        ]);
    }

    private function usePerCategoryMode(): void
    {
        RcsaMethodology::withoutGlobalScopes()
            ->whereKey($this->methodology()->id)
            ->update(['appetite_mode' => 'per_category']);
    }

    private function line(string $riskNo): RcsaAssessmentLine
    {
        return $this->assessment->lines()->where('risk_no', $riskNo)->sole();
    }

    /** 3 × 3 Mostly Achieved: inherent 9, modifier 75, residual 2.25 → LOW. */
    private function score(string $riskNo): RcsaAssessmentLine
    {
        $line = $this->line($riskNo);

        app(RcsaAssessmentService::class)->apply($line, [
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'control_effectiveness' => 'Mostly Achieved',
        ], $this->actor);

        return $line->refresh();
    }
}
