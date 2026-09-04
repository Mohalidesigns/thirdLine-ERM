<?php

namespace Tests\Feature\Characterisation;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Migration Phase 3.5 — the treatment dashboard's figures, pinned BEFORE the
 * controller method that computed them was extracted into
 * TreatmentPlanService and the page moved to Inertia.
 *
 * Every number below was read off the RUNNING BLADE SCREEN first (the
 * controller's compact() keys, via assertViewHas) and only then re-pointed at
 * the Inertia props. The four plans were chosen so that every figure has a
 * different answer from every other: a wrong join or a dropped status alias
 * shows up as the wrong number, not as a coincidentally right one.
 *
 * WHY avgEffectiveness IS 38 AND NOT 50. The Blade controller wrote
 * `whereNotNull('progress_pct')->avg('progress_pct')`, which reads as "average
 * the plans that have recorded progress". It never did that: progress_pct is
 * `smallInteger()->default(0)` and NOT NULL (2026_02_22_200010), so the filter
 * could not exclude a row and the not-started plan's 0 has always been in the
 * mean — (40 + 100 + 0 + 10) / 4 = 37.5, rounded to 38. The service drops the
 * no-op filter and keeps the figure. This is the pinned BEHAVIOUR, not the
 * behaviour the old code appeared to intend.
 */
class TreatmentDashboardStatsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /** Per-test, not static: the codes asserted below must start at 1 each time. */
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('risk-manager');
        $this->actingAs($this->actor);
    }

    private function plan(array $attributes): TreatmentPlan
    {
        $sequence = ++$this->sequence;

        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->makeRisk()->id,
            'treatment_code' => sprintf('TP-CHR-%04d', $sequence),
            'strategy' => 'mitigate',
            'action_title' => "Plan {$sequence}",
            'action_description' => "Fixture plan {$sequence}",
            'owner_id' => $this->actor->id,
            'priority' => 'high',
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function seedPlans(): void
    {
        // Open and past its target: active AND overdue.
        $this->plan(['strategy' => 'mitigate', 'status' => 'in_progress', 'target_date' => now()->subDays(10), 'cost_estimate_ngn' => 1_000_000, 'actual_cost_ngn' => 250_000, 'progress_pct' => 40]);
        // Done, over budget.
        $this->plan(['strategy' => 'transfer', 'status' => 'completed', 'target_date' => now()->subDays(30), 'completion_date' => now()->subDays(5), 'cost_estimate_ngn' => 500_000, 'actual_cost_ngn' => 600_000, 'progress_pct' => 100]);
        // Not started: active, not overdue, and a MEASURED zero in the mean —
        // progress_pct cannot be null, so this row is not skippable.
        $this->plan(['strategy' => 'accept', 'status' => 'not_started', 'target_date' => now()->addDays(40)]);
        // Parked: neither active nor overdue, whatever its date says.
        $this->plan(['strategy' => 'mitigate', 'status' => 'on_hold', 'target_date' => now()->subDays(3), 'cost_estimate_ngn' => 200_000, 'progress_pct' => 10]);
    }

    #[Test]
    public function the_dashboard_figures(): void
    {
        $this->seedPlans();

        $this->get(route('risk.treatments.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Treatments/Dashboard')
                ->where('stats.totalPlans', 4)
                ->where('stats.activePlans', 2)
                ->where('stats.completedPlans', 1)
                ->where('stats.overduePlans', 1)
                ->where('stats.totalBudget', 1_700_000)
                ->where('stats.avgEffectiveness', 38)
                ->where('budgetByStrategy.0.strategy', 'mitigate')
                ->where('budgetByStrategy.0.budget', 1_200_000)
                ->where('budgetByStrategy.0.actual', 250_000)
                ->where('budgetByStrategy.1.strategy', 'transfer')
                ->where('budgetByStrategy.1.budget', 500_000)
                ->where('budgetByStrategy.1.actual', 600_000)
                ->where('budgetByStrategy.2.strategy', 'avoid')
                ->where('budgetByStrategy.2.budget', 0)
                ->where('budgetByStrategy.3.strategy', 'accept')
                ->where('budgetByStrategy.3.budget', 0));
    }

    /**
     * The strategy mix and the status bands, which drove the two donut/bar
     * canvases. The status bands OVERLAP by design — the overdue plan is
     * counted under In Progress as well — and they sum to 5 across 4 plans
     * because of it.
     */
    #[Test]
    public function the_chart_series(): void
    {
        $this->seedPlans();

        $this->get(route('risk.treatments.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('strategyMix.0', ['strategy' => 'mitigate', 'label' => 'Mitigate', 'value' => 2])
                ->where('strategyMix.1', ['strategy' => 'transfer', 'label' => 'Transfer', 'value' => 1])
                ->where('strategyMix.2', ['strategy' => 'avoid', 'label' => 'Avoid', 'value' => 0])
                ->where('strategyMix.3', ['strategy' => 'accept', 'label' => 'Accept', 'value' => 1])
                ->where('statusMix.0.value', 1)   // Not Started
                ->where('statusMix.1.value', 1)   // In Progress
                ->where('statusMix.2.value', 1)   // Completed
                ->where('statusMix.3.value', 1)   // Overdue
                ->where('statusMix.4.value', 1)); // On Hold
    }

    /**
     * The two tables under the charts. Active plans are ordered by target
     * date, so the overdue one leads; the deadline list only takes RUNNING
     * plans, which excludes the not-started one the active list includes.
     */
    #[Test]
    public function the_tables(): void
    {
        $this->seedPlans();

        $this->get(route('risk.treatments.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('activeTreatments', 2)
                ->where('activeTreatments.0.code', 'TP-CHR-0001')
                ->where('activeTreatments.1.code', 'TP-CHR-0003')
                ->has('upcomingDeadlines', 1)
                ->where('upcomingDeadlines.0.code', 'TP-CHR-0001')
                ->has('recentActivity', 4)
                ->where('recentActivity.0.description', 'Treatment plan "Plan 1" was updated'));
    }

    /**
     * The completion trend is always twelve months, so the axis does not
     * rescale between tenants. All four fixtures are created now.
     */
    #[Test]
    public function the_completion_trend_is_twelve_months(): void
    {
        $this->seedPlans();

        $month = now()->month;

        $this->get(route('risk.treatments.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('completionTrend', 12)
                ->where('completionTrend.'.($month - 1).'.created', 4)
                ->where('completionTrend.'.($month - 1).'.completed', 1));
    }

    #[Test]
    public function an_empty_organisation_reports_zeros_not_nulls(): void
    {
        $this->get(route('risk.treatments.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalPlans', 0)
                ->where('stats.activePlans', 0)
                ->where('stats.overduePlans', 0)
                ->where('stats.totalBudget', 0)
                ->where('stats.avgEffectiveness', 0)
                ->where('budgetByStrategy.0.budget', 0)
                ->has('completionTrend', 12));
    }
}
