<?php

namespace Tests\Feature\Characterisation;

use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\NearMiss;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The loss-event dashboard's figures (migration Phase 4.3).
 *
 * THIS TEST COULD NOT HAVE BEEN WRITTEN BEFORE THE PORT. The monthly trend used
 * `MONTH(date_of_loss)` in a raw select, and MONTH() is MySQL-only — so the
 * whole screen threw on the SQLite the suite runs on, and had never been
 * covered. The expression is portable now, which is what makes the rest of this
 * file possible. Every other figure was read off the Blade controller's
 * compact() keys and is unchanged.
 *
 * Money is stored in KOBO and rendered in naira; the fixtures use amounts whose
 * kobo and naira forms cannot be confused for each other.
 */
class LossEventDashboardFiguresTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('loss_event.view');
        $this->actor->givePermissionTo('loss_event.view');

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
            'is_active' => true,
        ]);

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function event(array $attributes = []): LossEvent
    {
        $n = ++$this->sequence;

        return LossEvent::create(array_merge([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'event_reference' => sprintf('LE-%04d', $n),
            'title' => "Event {$n}",
            'description' => "Fixture event {$n}",
            'basel_l1_category' => 'INTERNAL_FRAUD',
            'basel_l2_category' => 'INTERNAL_FRAUD',
            'cbn_risk_category' => 'OTHER',
            'loss_category' => 'actual_loss',
            'event_severity' => 'MAJOR',
            'date_of_loss' => now()->startOfYear()->addMonths(2),
            'date_discovered' => now()->startOfYear()->addMonths(2),
            'gross_loss_amount_kobo' => 0,
            'insurance_recovery_kobo' => 0,
            'other_recovery_kobo' => 0,
            'current_status' => 'REPORTED',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function seedEvents(): void
    {
        // NGN 400,000 gross; NGN 100,000 insurance and NGN 25,000 other back.
        $this->event([
            'gross_loss_amount_kobo' => 40_000_000,
            'insurance_recovery_kobo' => 10_000_000,
            'other_recovery_kobo' => 2_500_000,
            'is_regulatory_reportable' => true,
        ]);

        // NGN 150,000 gross, nothing recovered, a different month and category.
        $this->event([
            'gross_loss_amount_kobo' => 15_000_000,
            'basel_l1_category' => 'EXTERNAL_FRAUD',
            'basel_l2_category' => 'EXTERNAL_FRAUD',
            'date_of_loss' => now()->startOfYear()->addMonths(5),
            'current_status' => 'CLOSED',
        ]);

        // Reportable to the NFIU and not yet filed.
        $this->event([
            'gross_loss_amount_kobo' => 5_000_000,
            'nfiu_reportable' => true,
            'nfiu_report_filed' => false,
            'current_status' => 'UNDER_INVESTIGATION',
        ]);

        NearMiss::create([
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id,
            'reference' => 'NM-0001',
            'title' => 'Caught at checker',
            'description' => 'Stopped before it happened.',
            'date_occurred' => now()->startOfYear()->addMonth(),
            'date_reported' => now()->startOfYear()->addMonth(),
            'severity' => 'medium',
            'status' => 'open',
            'reported_by' => $this->actor->id,
        ]);
    }

    #[Test]
    public function the_money_tiles_convert_kobo_to_naira(): void
    {
        $this->seedEvents();

        $this->get(route('risk.loss-events.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LossEvents/Dashboard')
                ->where('kpis.totalEvents', 3)
                ->where('kpis.totalGrossLoss', 600000)      // 400k + 150k + 50k
                ->where('kpis.recoveredAmount', 125000)     // 100k + 25k
                ->where('kpis.netLossYtd', 475000));        // gross less recoveries
    }

    /**
     * The four outstanding-work tiles are NOT year-bounded, and were not
     * before: a notification owed from last December is still owed.
     */
    #[Test]
    public function the_outstanding_work_tiles(): void
    {
        $this->seedEvents();

        $this->get(route('risk.loss-events.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.pendingCbnNotifications', 1)
                ->where('kpis.pendingNfiuFilings', 1)
                // REPORTED and UNDER_INVESTIGATION are open; CLOSED is not.
                ->where('kpis.openInvestigations', 2)
                ->where('kpis.nearMisses', 1));
    }

    /** Twelve months always, zero-filled, so the axis does not move. */
    #[Test]
    public function the_monthly_trend_is_twelve_months(): void
    {
        $this->seedEvents();

        $this->get(route('risk.loss-events.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('monthlyTrend', 12)
                ->where('monthlyTrend.0.month', 'Jan')
                ->where('monthlyTrend.0.count', 0)
                // Two events in March, one in June.
                ->where('monthlyTrend.2.count', 2)
                ->where('monthlyTrend.2.loss', 450000)
                ->where('monthlyTrend.5.count', 1)
                ->where('monthlyTrend.5.loss', 150000));
    }

    #[Test]
    public function the_basel_breakdown_is_worst_first(): void
    {
        $this->seedEvents();

        $this->get(route('risk.loss-events.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('baselCategories', 2)
                ->where('baselCategories.0.category', 'INTERNAL_FRAUD')
                ->where('baselCategories.0.count', 2)
                ->where('baselCategories.0.loss', 450000)
                ->where('baselCategories.1.category', 'EXTERNAL_FRAUD')
                ->where('baselCategories.1.loss', 150000));
    }

    /**
     * The alert panel shows the REAL reference. The Blade version read
     * `$e->reference`, which is neither a column nor an accessor, so its
     * `'LE-'.$e->id` fallback fired every time and printed a string that
     * matches nothing a user can search for.
     */
    #[Test]
    public function the_regulatory_alerts_use_the_real_event_reference(): void
    {
        $this->seedEvents();

        $this->get(route('risk.loss-events.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('regulatoryAlerts', 1)
                ->where('regulatoryAlerts.0.reference', 'LE-0001')
                ->where('regulatoryAlerts.0.type', 'CBN ORMS notification')
                ->where('regulatoryAlerts.0.isOverdue', true));
    }

    #[Test]
    public function an_empty_organisation_reports_zeros_not_nulls(): void
    {
        $this->get(route('risk.loss-events.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.totalEvents', 0)
                ->where('kpis.totalGrossLoss', 0)
                ->where('kpis.netLossYtd', 0)
                ->has('monthlyTrend', 12)
                ->has('baselCategories', 0)
                ->has('regulatoryAlerts', 0));
    }
}
