<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Risk;
use App\Services\Rcsa\RcsaAssessmentService;
use App\Services\Rcsa\RcsaCycleService;
use App\Services\Rcsa\RcsaLegacyMigrator;
use App\Services\Rcsa\RcsaParallelRunComparer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §13 step 5's comparison — the last step of the cutover, and the only one no
 * command can complete on its own.
 *
 * THE CLASSIFICATION IS THE WHOLE VALUE. A parallel run produces two hundred
 * differences and almost all of them are people answering a question
 * differently, which is what a self-assessment is for. If the report treated
 * those as failures its verdict would be red every time and nobody would read
 * it. Exactly one shape is a defect — identical inputs reaching different
 * scores — and these tests pin that the harness tells them apart.
 */
class ParallelRunTest extends CycleTestCase
{
    /**
     * A migrated universe with a v2 assessment over it — the state a bank is in
     * when the parallel run happens.
     *
     * @return array{assessment: RcsaAssessment, risks: \Illuminate\Support\Collection<int, Risk>}
     */
    private function migratedAndAssessed(int $count = 3): array
    {
        $risks = collect(range(1, $count))->map(fn (int $i) => Risk::create([
            'organization_id' => $this->organization->id,
            'risk_code' => "RK-PR-{$i}",
            'title' => "Parallel risk {$i}",
            'description' => "Parallel run risk statement {$i}, of realistic length.",
            'category_id' => $this->category->id,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'business_unit_id' => $this->retail->id,
            'inherent_likelihood' => 3,
            'inherent_impact' => 4,
            'inherent_score' => 12,
            'residual_score' => 6,
            'residual_rating' => 'medium',
        ]));

        app(RcsaLegacyMigrator::class)->migrate($this->organization->id, commit: true);

        $cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $assessment = RcsaAssessment::query()
            ->where('cycle_id', $cycle->id)
            ->where('business_unit_id', $this->retail->id)
            ->sole();

        return ['assessment' => $assessment, 'risks' => $risks];
    }

    private function score(RcsaAssessment $assessment, int $likelihood, int $impact): void
    {
        $service = app(RcsaAssessmentService::class);

        foreach ($assessment->lines()->get() as $line) {
            $service->apply($line, [
                'inherent_likelihood' => $likelihood,
                'inherent_impact' => $impact,
                'control_effectiveness' => 'Mostly Achieved',
            ], $this->actor);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * The assessor agreeing with the register is a match on the inputs, even
     * though the two modules' residuals differ — the register's residual is a
     * standing figure, the v2 one is derived from this quarter's answers.
     */
    #[Test]
    public function agreeing_with_the_register_is_not_a_disagreement(): void
    {
        ['assessment' => $assessment] = $this->migratedAndAssessed();

        $this->score($assessment, 3, 4);

        $report = app(RcsaParallelRunComparer::class)->compare($assessment);

        $this->assertSame(0, $report['summary']['engine']);
        $this->assertSame(0, $report['summary']['no_baseline']);
        $this->assertTrue($report['verdict']['signable']);
    }

    #[Test]
    public function answering_differently_is_judgement_and_does_not_block(): void
    {
        ['assessment' => $assessment] = $this->migratedAndAssessed();

        // The register says 3 × 4; the assessor says 5 × 5.
        $this->score($assessment, 5, 5);

        $report = app(RcsaParallelRunComparer::class)->compare($assessment);

        $this->assertSame(3, $report['summary']['judgement']);
        $this->assertSame(0, $report['summary']['engine']);
        $this->assertTrue($report['verdict']['signable'], 'Assessor judgement must never block a sign-off.');

        $row = collect($report['rows'])->firstWhere('classification', RcsaParallelRunComparer::JUDGEMENT);
        $this->assertStringContainsString('answered differently', $row['note']);
    }

    /**
     * THE ONE THAT MATTERS. Same inputs, different derived score — no human
     * judgement explains it, so it blocks.
     */
    #[Test]
    public function identical_inputs_reaching_different_scores_blocks_the_sign_off(): void
    {
        ['assessment' => $assessment] = $this->migratedAndAssessed();

        $this->score($assessment, 3, 4);

        // Exactly what an engine bug looks like, and nothing else does.
        $line = $assessment->lines()->first();
        DB::table('rcsa_assessment_lines')->where('id', $line->id)->update(['inherent_score' => 99]);

        $report = app(RcsaParallelRunComparer::class)->compare($assessment->refresh());

        $this->assertSame(1, $report['summary']['engine']);
        $this->assertFalse($report['verdict']['signable']);
        $this->assertStringContainsString('one of the two engines is wrong', $report['verdict']['blockers'][0]);

        // And the command exits non-zero, so a pipeline notices.
        $this->artisan('rcsa:parallel-run', ['--assessment' => $assessment->id])->assertFailed();
    }

    /**
     * A risk born in v2 has nothing to compare against, and saying so is not
     * the same as saying it matched.
     */
    #[Test]
    public function a_risk_with_no_legacy_origin_is_reported_as_having_no_baseline(): void
    {
        ['assessment' => $assessment] = $this->migratedAndAssessed(1);

        // Typed into the Universe screen rather than migrated.
        RcsaRegisterRisk::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->retail->id,
            'risk_no' => 'RETAIL-NEW1',
            'potential_risk' => 'A risk somebody added after the migration.',
            'risk_category' => 'Operational',
            'status' => RcsaRegisterRisk::PUBLISHED,
            'published_at' => now(),
        ]);

        $cycle = $this->makeCycle(['name' => 'Second cycle', 'period_start' => '2026-07-01', 'period_end' => '2026-12-31']);
        app(RcsaCycleService::class)->open($cycle, $this->actor);

        $second = RcsaAssessment::query()
            ->where('cycle_id', $cycle->id)
            ->where('business_unit_id', $this->retail->id)
            ->sole();

        $this->score($second, 3, 4);

        $report = app(RcsaParallelRunComparer::class)->compare($second);

        $this->assertSame(1, $report['summary']['no_baseline']);

        $row = collect($report['rows'])->firstWhere('classification', RcsaParallelRunComparer::NO_BASELINE);
        $this->assertSame('RETAIL-NEW1', $row['risk_no']);
        $this->assertNull($row['legacy']);
        $this->assertStringContainsString('nothing to compare', $row['note']);

        // It does not block: a new risk is not a discrepancy.
        $this->assertTrue($report['verdict']['signable']);
    }

    #[Test]
    public function the_command_writes_its_report_to_storage(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        ['assessment' => $assessment] = $this->migratedAndAssessed(1);
        $this->score($assessment, 3, 4);

        $this->artisan('rcsa:parallel-run', ['--assessment' => $assessment->id])->assertSuccessful();

        $files = \Illuminate\Support\Facades\Storage::disk('local')
            ->allFiles("rcsa/parallel-run/{$this->organization->id}");

        $this->assertCount(1, $files, 'The comparison must be filed — it is what a sign-off refers back to.');
    }
}
