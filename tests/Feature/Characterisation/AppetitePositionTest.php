<?php

namespace Tests\Feature\Characterisation;

use App\Models\RiskAppetite;
use App\Services\Appetite\AppetiteFrameworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Characterisation of the appetite position rules that lived inline in
 * RiskAppetiteController::index before Phase 3.6. The thresholds are the
 * screen's, unchanged: breach above the hard limit, near-limit above the
 * target or within 15% of the hard limit, within otherwise.
 */
class AppetitePositionTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /** @return array<string, array{float|null, float, float, string}> */
    public static function positions(): array
    {
        return [
            'no tolerance set → within' => [9.0, 5.0, 0.0, 'within'],
            'no position → within' => [null, 5.0, 10.0, 'within'],
            'comfortably inside' => [3.0, 5.0, 10.0, 'within'],
            'above target max' => [6.0, 5.0, 10.0, 'near_limit'],
            'inside 15% of the limit' => [8.6, 0.0, 10.0, 'near_limit'],
            'exactly at the limit' => [10.0, 5.0, 10.0, 'near_limit'],
            'over the limit' => [10.01, 5.0, 10.0, 'breach'],
        ];
    }

    #[Test]
    #[DataProvider('positions')]
    public function a_position_is_classified_by_the_screen_rules(?float $current, float $targetMax, float $max, string $expected): void
    {
        $this->assertSame($expected, (new AppetiteFrameworkService)->statusFor($current, $targetMax, $max));
    }

    #[Test]
    public function the_recorded_position_wins_and_the_residual_average_is_the_fallback(): void
    {
        $this->bootDomainFixtures();
        $service = new AppetiteFrameworkService;

        $recorded = $this->statement(['current_position' => 4.25]);
        $this->assertSame(4.25, $service->currentPosition($recorded));

        $fallback = $this->statement(['current_position' => null]);
        $this->makeRisk(['category_id' => $this->category->id, 'status' => 'active', 'residual_score' => 6.0]);
        $this->makeRisk(['category_id' => $this->category->id, 'status' => 'active', 'residual_score' => 8.0]);
        $this->assertSame(7.0, $service->currentPosition($fallback->fresh()));

        $metrics = $service->metrics(collect([$fallback->fresh()]));
        $this->assertSame('residual_average', $metrics[0]['position_source']);
    }

    #[Test]
    public function the_summary_reports_counts_and_no_invented_dates(): void
    {
        $this->bootDomainFixtures();
        $service = new AppetiteFrameworkService;

        $summary = $service->summary([], collect());

        $this->assertSame(['total' => 0, 'within' => 0, 'near_limit' => 0, 'breaches' => 0, 'overall' => 'Within Appetite'], array_intersect_key($summary, array_flip(['total', 'within', 'near_limit', 'breaches', 'overall'])));
        $this->assertNull($summary['approval_date'], 'no statement, no approval date — the old screen printed today minus three months');
        $this->assertNull($summary['next_review_date']);

        $breached = $this->statement(['current_position' => 99, 'max_tolerance' => 10, 'target_max' => 5]);
        $metrics = $service->metrics(collect([$breached]));
        $summary = $service->summary($metrics, collect([$breached]));

        $this->assertSame(1, $summary['breaches']);
        $this->assertSame('Breach', $summary['overall']);
        $this->assertSame('breach', $metrics[0]['status']);
        $this->assertSame('up', $metrics[0]['trend']);
    }

    private function statement(array $attributes = []): RiskAppetite
    {
        return RiskAppetite::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_category_id' => $this->category->id,
            'appetite_level' => 'cautious',
            'appetite_statement' => 'Statement',
            'tolerance_metric' => 'NPL ratio',
            'unit_of_measure' => 'percentage',
            'target_min' => 0,
            'target_max' => 5,
            'max_tolerance' => 10,
            'effective_date' => now()->toDateString(),
        ], $attributes));
    }
}
