<?php

namespace Tests\Feature;

use App\Models\ControlTest;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\RiskForecastService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The forecast produces user-facing numbers, so it is pinned by tests.
 *
 * The point of these is not that the arithmetic is exotic — it is that every
 * figure the Forecast screen shows must be reproducible from the rows below.
 * If any of these start failing because a number moved, the number moved for a
 * reason someone has to explain.
 */
class RiskForecastServiceTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures('Forecast Bank PLC');

        $this->org = $this->organization;
        $this->user = $this->actor;
    }

    #[Test]
    public function it_declines_to_fit_a_trend_when_too_few_months_carry_assessments(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-F-001']);

        // Two populated months only: a line through two points is perfect by
        // construction and would report a zero-width band.
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonths(2), 10);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonth(), 12);

        $result = (new RiskForecastService)->forecast($this->org->id);

        $this->assertFalse($result['fit']['available']);
        $this->assertSame(2, $result['fit']['n']);
        $this->assertStringContainsString('at least 3', $result['fit']['reason']);
        $this->assertSame([], $result['projection'], 'No projection may be emitted without a fit.');
    }

    #[Test]
    public function it_recovers_a_known_slope_and_intercept_from_a_perfectly_linear_history(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-F-002']);

        // Months at index 9, 10, 11 of a 12-month window carrying 8, 10, 12.
        // Slope is therefore exactly 2.0 per month.
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonths(2), 8);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonth(), 10);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth(), 12);

        $result = (new RiskForecastService)->forecast($this->org->id);

        $this->assertTrue($result['fit']['available']);
        $this->assertSame(3, $result['fit']['n']);
        $this->assertEqualsWithDelta(2.0, $result['fit']['slope_per_month'], 0.0001);
        $this->assertSame('rising', $result['fit']['direction']);

        // A perfect fit has no residual scatter, so the band collapses to the
        // line. That is the honest answer for three collinear points.
        $this->assertEqualsWithDelta(0.0, $result['fit']['residual_std_error'], 0.0001);

        $this->assertCount(3, $result['projection']);
        $this->assertEqualsWithDelta(14.0, $result['projection'][0]['projected_mean_residual_score'], 0.0001);
        $this->assertEqualsWithDelta(16.0, $result['projection'][1]['projected_mean_residual_score'], 0.0001);
        $this->assertEqualsWithDelta(18.0, $result['projection'][2]['projected_mean_residual_score'], 0.0001);
    }

    #[Test]
    public function the_projection_band_widens_with_distance_from_the_observed_months(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-F-003']);

        // Deliberately not collinear, so there is residual scatter to widen.
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonths(3), 8);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonths(2), 13);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth()->subMonth(), 11);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth(), 16);

        $result = (new RiskForecastService)->forecast($this->org->id);

        $this->assertTrue($result['fit']['available']);
        $this->assertGreaterThan(0, $result['fit']['residual_std_error']);

        $widths = array_map(
            fn ($p) => $p['range_high'] - $p['range_low'],
            $result['projection']
        );

        $this->assertGreaterThan($widths[0], $widths[1]);
        $this->assertGreaterThan($widths[1], $widths[2]);
    }

    #[Test]
    public function months_without_assessments_stay_null_rather_than_being_interpolated(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-F-004']);
        $this->makeAssessment($risk, CarbonImmutable::now()->startOfMonth(), 9);

        $result = (new RiskForecastService)->forecast($this->org->id);

        $populated = array_filter($result['history'], fn ($m) => $m['mean_residual_score'] !== null);

        $this->assertCount(1, $populated, 'Only the month carrying an assessment may hold a value.');
        $this->assertCount(12, $result['history']);
    }

    #[Test]
    public function it_never_reads_another_organizations_assessments(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $otherUser = User::create([
            'organization_id' => $other->id,
            'name' => 'Other Analyst',
            'email' => 'analyst@other.test',
            'password' => Hash::make('password'),
        ]);

        // Build a rich history for the other tenant only.
        TenantContext::set($other->id);

        $otherCategory = \App\Models\RiskCategory::create([
            'organization_id' => $other->id,
            'code' => 'OPS',
            'name' => 'Operational Risk',
        ]);

        $otherRisk = Risk::create([
            'category_id' => $otherCategory->id,
            'organization_id' => $other->id,
            'risk_code' => 'RK-O-001',
            'title' => 'Other tenant risk',
            'description' => 'Belongs to the other tenant.',
            'status' => 'active',
            'created_by' => $otherUser->id,
        ]);
        foreach ([0, 1, 2, 3] as $back) {
            RiskAssessment::create([
                'organization_id' => $other->id,
                'risk_id' => $otherRisk->id,
                'assessment_type' => 'periodic',
                'assessment_date' => CarbonImmutable::now()->startOfMonth()->subMonths($back)->toDateString(),
                'assessor_id' => $otherUser->id,
                'residual_score' => 20,
                'overall_score' => 22,
            ]);
        }

        TenantContext::set($this->org->id);

        $result = (new RiskForecastService)->forecast($this->org->id);

        $this->assertFalse($result['fit']['available']);
        $this->assertSame(0, $result['inputs']['assessments_in_window']);
    }

    #[Test]
    public function treatment_velocity_counts_missed_target_dates_net_of_closures(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-F-005']);
        $lastMonth = CarbonImmutable::now()->startOfMonth()->subMonth();

        // Missed its target date and still open -> counts as became overdue.
        TreatmentPlan::create([
            'organization_id' => $this->org->id,
            'risk_id' => $risk->id,
            'action_title' => 'Missed plan',
            'owner_id' => $this->user->id,
            'target_date' => $lastMonth->addDays(5)->toDateString(),
            'status' => 'in_progress',
            'created_by' => $this->user->id,
        ]);

        // Closed inside its target date -> counts as a closure, not overdue.
        TreatmentPlan::create([
            'organization_id' => $this->org->id,
            'risk_id' => $risk->id,
            'action_title' => 'Delivered plan',
            'owner_id' => $this->user->id,
            'target_date' => $lastMonth->addDays(10)->toDateString(),
            'completion_date' => $lastMonth->addDays(8)->toDateString(),
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        $velocity = (new RiskForecastService)->forecast($this->org->id)['signals']['treatment_velocity'];

        $month = collect($velocity['series'])->firstWhere('month', $lastMonth->format('Y-m'));

        $this->assertSame(1, $month['became_overdue']);
        $this->assertSame(1, $month['closed']);
        $this->assertSame(0, $month['net']);
        $this->assertSame(0, $velocity['net_over_window']);
    }

    #[Test]
    public function kri_breach_frequency_is_the_share_of_red_measurements(): void
    {
        $kri = KeyRiskIndicator::create([
            'organization_id' => $this->org->id,
            'kri_code' => 'KRI-F-001',
            'name' => 'Failed logins',
            'measurement_frequency' => 'monthly',
            'current_status' => 'red',
        ]);

        $date = CarbonImmutable::now()->startOfMonth()->addDays(3);
        foreach (['red', 'green', 'green', 'red'] as $status) {
            KriMeasurement::create([
                'kri_id' => $kri->id,
                'measurement_date' => $date->toDateString(),
                'value' => 10,
                'status' => $status,
                'entered_by' => $this->user->id,
            ]);
        }

        $breaches = (new RiskForecastService)->forecast($this->org->id)['signals']['kri_breach_frequency'];

        $this->assertSame(4, $breaches['measurements_in_window']);
        $this->assertSame(2, $breaches['breaches_in_window']);
        $this->assertEqualsWithDelta(50.0, $breaches['breach_rate_pct'], 0.001);
        $this->assertSame(1, $breaches['currently_red']);
    }

    #[Test]
    public function kri_breach_rate_is_null_rather_than_zero_when_nothing_was_measured(): void
    {
        $breaches = (new RiskForecastService)->forecast($this->org->id)['signals']['kri_breach_frequency'];

        $this->assertNull(
            $breaches['breach_rate_pct'],
            'An unmeasured indicator must not read as a 0% breach rate.'
        );
    }

    #[Test]
    public function control_test_failure_rate_counts_partially_effective_as_a_failure(): void
    {
        $control = \App\Models\Control::create([
            'organization_id' => $this->org->id,
            'control_code' => 'CTL-F-001',
            'name' => 'Reconciliation',
            'created_by' => $this->user->id,
        ]);

        $completed = CarbonImmutable::now()->startOfMonth()->addDays(2);
        $results = ['effective', 'partially_effective', 'ineffective', 'effective'];

        foreach ($results as $i => $result) {
            ControlTest::create([
                'organization_id' => $this->org->id,
                'control_id' => $control->id,
                'test_code' => 'CT-F-00'.$i,
                'title' => 'Test '.$i,
                'scheduled_date' => $completed->toDateString(),
                'completed_date' => $completed->toDateString(),
                'result' => $result,
                'status' => 'completed',
            ]);
        }

        $tests = (new RiskForecastService)->forecast($this->org->id)['signals']['control_test_failure_rate'];

        $this->assertSame(4, $tests['tests_in_window']);
        $this->assertSame(2, $tests['failures_in_window']);
        $this->assertEqualsWithDelta(50.0, $tests['failure_rate_pct'], 0.001);
    }

    #[Test]
    public function the_watchlist_reports_observed_movement_between_two_dated_assessments(): void
    {
        $worse = $this->makeRisk(['risk_code' => 'RK-F-010']);
        $better = $this->makeRisk(['risk_code' => 'RK-F-011']);
        $flat = $this->makeRisk(['risk_code' => 'RK-F-012']);

        $this->makeAssessment($worse, CarbonImmutable::now()->subMonths(2), 8);
        $this->makeAssessment($worse, CarbonImmutable::now()->subMonth(), 15);

        $this->makeAssessment($better, CarbonImmutable::now()->subMonths(2), 16);
        $this->makeAssessment($better, CarbonImmutable::now()->subMonth(), 9);

        $this->makeAssessment($flat, CarbonImmutable::now()->subMonths(2), 12);
        $this->makeAssessment($flat, CarbonImmutable::now()->subMonth(), 12);

        $watchlist = (new RiskForecastService)->forecast($this->org->id)['watchlist'];

        $codes = array_column($watchlist, 'risk_code');
        $this->assertContains('RK-F-010', $codes);
        $this->assertContains('RK-F-011', $codes);
        $this->assertNotContains('RK-F-012', $codes, 'An unchanged score is not a movement.');

        $deteriorating = collect($watchlist)->firstWhere('risk_code', 'RK-F-010');
        $this->assertSame(7, $deteriorating['delta']);
        $this->assertSame(8, $deteriorating['previous_score']);
        $this->assertSame(15, $deteriorating['current_score']);
        $this->assertSame('deteriorating', $deteriorating['direction']);

        // Worst deterioration first.
        $this->assertSame('RK-F-010', $watchlist[0]['risk_code']);
    }

    #[Test]
    public function a_risk_with_only_one_assessment_cannot_appear_on_the_watchlist(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-F-020']);
        $this->makeAssessment($risk, CarbonImmutable::now()->subMonth(), 14);

        $watchlist = (new RiskForecastService)->forecast($this->org->id)['watchlist'];

        $this->assertSame([], $watchlist, 'Movement needs two points to be observed.');
    }

    /* ------------------------------------------------------------------ */

    private function makeAssessment(Risk $risk, CarbonImmutable $date, int $residual): RiskAssessment
    {
        return RiskAssessment::create([
            'organization_id' => $this->org->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => $date->toDateString(),
            'assessor_id' => $this->user->id,
            'residual_score' => $residual,
            'overall_score' => $residual + 2,
        ]);
    }
}
