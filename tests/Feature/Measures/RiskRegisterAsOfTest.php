<?php

namespace Tests\Feature\Measures;

use App\Models\MeasureValue;
use App\Models\Risk;
use App\Repositories\RiskRepository;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-04 TASK 4 acceptance: the register as at Q1 2026 differs correctly from
 * the register as at Q2 2026.
 */
class RiskRegisterAsOfTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-10 09:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_risk_assessed_in_two_quarters_reads_differently_in_each(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);

        $this->approveAssessment($risk, '2026-02-15', [
            'likelihood_score' => 4,
            'impact_financial' => 5,
            'overall_score' => 20,
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'residual_score' => 12,
        ]);

        $this->approveAssessment($risk, '2026-05-20', [
            'likelihood_score' => 2,
            'impact_financial' => 3,
            'overall_score' => 6,
            'residual_likelihood' => 2,
            'residual_impact' => 2,
            'residual_score' => 4,
        ]);

        $repository = app(RiskRepository::class);

        $q1 = $this->quarter('2026-02-15');
        $q2 = $this->quarter('2026-05-20');

        $asOfQ1 = $repository->asOf($q1)->firstWhere('id', $risk->id);
        $asOfQ2 = $repository->asOf($q2)->firstWhere('id', $risk->id);

        $this->assertSame(20.0, (float) $asOfQ1->inherent_score);
        $this->assertSame(12.0, (float) $asOfQ1->residual_score);
        $this->assertSame('Critical', $asOfQ1->inherent_rating);

        $this->assertSame(6.0, (float) $asOfQ2->inherent_score);
        $this->assertSame(4.0, (float) $asOfQ2->residual_score);
        $this->assertSame('Medium', $asOfQ2->inherent_rating);
    }

    #[Test]
    public function a_score_is_carried_forward_into_a_quarter_with_no_new_assessment(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);

        $this->approveAssessment($risk, '2026-02-15', [
            'likelihood_score' => 4, 'impact_financial' => 4, 'overall_score' => 16,
        ]);

        $repository = app(RiskRepository::class);
        $q2 = $this->quarter('2026-05-20');

        $asOfQ2 = $repository->asOf($q2)->firstWhere('id', $risk->id);

        // The risk has not been reassessed; it has not therefore stopped
        // existing, and the register must still show its last approved score.
        $this->assertSame(16.0, (float) $asOfQ2->inherent_score);

        // The view is Q2, but the number was measured at the end of Q1 — which
        // is what as_of_measured_at exists to make visible.
        $this->assertSame($q2->id, $asOfQ2->as_of_period_id);
        $this->assertStringStartsWith(
            $this->quarter('2026-02-15')->end_date->toDateString(),
            (string) $asOfQ2->as_of_measured_at
        );
    }

    #[Test]
    public function a_risk_identified_after_the_period_is_absent_from_it(): void
    {
        $old = $this->makeRisk(['date_identified' => '2025-11-01']);
        $new = $this->makeRisk(['date_identified' => '2026-05-01']);

        $this->approveAssessment($old, '2026-02-15', ['likelihood_score' => 3, 'impact_financial' => 3, 'overall_score' => 9]);
        $this->approveAssessment($new, '2026-05-10', ['likelihood_score' => 5, 'impact_financial' => 5, 'overall_score' => 25]);

        $repository = app(RiskRepository::class);

        $q1 = $repository->asOf($this->quarter('2026-02-15'))->pluck('id')->all();
        $q2 = $repository->asOf($this->quarter('2026-05-20'))->pluck('id')->all();

        $this->assertContains($old->id, $q1);
        $this->assertNotContains($new->id, $q1, 'A risk identified in Q2 did not exist as at Q1.');
        $this->assertContains($new->id, $q2);
    }

    #[Test]
    public function a_risk_with_no_assessment_before_the_period_does_not_show_todays_number(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);

        $this->approveAssessment($risk, '2026-05-20', [
            'likelihood_score' => 5, 'impact_financial' => 5, 'overall_score' => 25,
        ]);

        // The risk's current score is 25. As at Q1 it had not been assessed.
        $this->assertSame(25, Risk::findOrFail($risk->id)->inherent_score);

        $repository = app(RiskRepository::class);
        $asOfQ1 = $repository->asOf($this->quarter('2026-02-15'))->firstWhere('id', $risk->id);

        $this->assertNotNull($asOfQ1);
        $this->assertNull($asOfQ1->getAttributes()['inherent_score']);
        $this->assertNull($asOfQ1->getAttributes()['residual_score']);
        $this->assertNull(
            $asOfQ1->as_of_measured_at,
            'Nothing was measured on or before Q1, and the view must say so.'
        );
        $this->assertNotEquals(25, $asOfQ1->inherent_score, 'The current column must not leak into a historic view.');
    }

    #[Test]
    public function the_as_of_overlay_never_writes_back_to_the_risks_table(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);

        $this->approveAssessment($risk, '2026-02-15', ['likelihood_score' => 4, 'impact_financial' => 4, 'overall_score' => 16]);
        $this->approveAssessment($risk, '2026-05-20', ['likelihood_score' => 1, 'impact_financial' => 1, 'overall_score' => 1]);

        $current = Risk::findOrFail($risk->id)->inherent_score;

        $overlaid = app(RiskRepository::class)->asOf($this->quarter('2026-02-15'))->firstWhere('id', $risk->id);
        $overlaid->save();

        $this->assertSame($current, Risk::findOrFail($risk->id)->inherent_score);
    }

    #[Test]
    public function approving_an_assessment_period_stamps_the_scores(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01', 'control_effectiveness_pct' => 62.5]);

        $this->approveAssessment($risk->fresh(), '2026-02-15', [
            'likelihood_score' => 4,
            'impact_financial' => 4,
            'overall_score' => 16,
            'residual_likelihood' => 2,
            'residual_impact' => 3,
            'residual_score' => 6,
        ]);

        $q1 = $this->quarter('2026-02-15');

        $recorded = MeasureValue::where('period_id', $q1->id)
            ->join('measures', 'measures.id', '=', 'measure_values.measure_id')
            ->pluck('measure_values.value', 'measures.code');

        $this->assertEquals(16, $recorded[MeasureCatalog::RISK_INHERENT_SCORE]);
        $this->assertEquals(6, $recorded[MeasureCatalog::RISK_RESIDUAL_SCORE]);
        $this->assertEquals(4, $recorded[MeasureCatalog::RISK_INHERENT_LIKELIHOOD]);
        $this->assertEquals(62.5, $recorded[MeasureCatalog::RISK_CONTROL_EFFECTIVENESS]);
    }

    #[Test]
    public function a_second_assessment_in_the_same_period_corrects_rather_than_duplicates(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);

        $this->approveAssessment($risk, '2026-02-10', ['likelihood_score' => 3, 'impact_financial' => 3, 'overall_score' => 9]);
        $this->approveAssessment($risk, '2026-03-25', ['likelihood_score' => 5, 'impact_financial' => 4, 'overall_score' => 20]);

        $q1 = $this->quarter('2026-02-10');

        $rows = MeasureValue::where('period_id', $q1->id)
            ->forMeasureCode(MeasureCatalog::RISK_INHERENT_SCORE)
            ->get();

        $this->assertCount(1, $rows, 'The natural key permits one value per measure, object, period and scenario.');
        $this->assertEquals(20, $rows->first()->value);
    }
}
