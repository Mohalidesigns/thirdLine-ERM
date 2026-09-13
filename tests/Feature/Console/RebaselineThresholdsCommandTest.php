<?php

namespace Tests\Feature\Console;

use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\Period;
use App\Services\ThresholdRebaselineService;
use App\Support\Measures\MeasureCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `measures:rebaseline-thresholds` — the scheduled entry point (Phase 4).
 *
 * Phase 4's acceptance criterion 5 asks for four scheduled commands to run
 * green in tests/Feature/Console. Three did; THIS ONE HAD NO TEST ANYWHERE.
 * ThresholdRebaselineService is well covered by
 * Measures/FormulaThresholdRebaselineTest, which calls review() directly — but
 * nothing had ever run the command that calls it on a schedule, so its option
 * handling, its choice of period and its failure path were unexercised. A
 * service with a green test and an untested command around it is a scheduled
 * job that can be broken without anything going red.
 *
 * The command runs at midnight, unattended, against every organisation. What it
 * decides is which naira limits a bank's board will be asked to move.
 */
class RebaselineThresholdsCommandTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    private Period $quarter;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-05 00:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-04-05 00:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');

        $this->quarter = $this->quarter('2026-02-01');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */

    /** Nothing to examine is a successful run, not a failure. */
    #[Test]
    public function the_command_runs_green_with_nothing_to_do(): void
    {
        $this->artisan('measures:rebaseline-thresholds')
            ->expectsOutputToContain('Examined 0 formula threshold(s)')
            ->assertSuccessful();

        $this->assertSame(0, ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->count());
    }

    /**
     * The whole point of the schedule: a period closed by a job at midnight has
     * no request to run the review inside, so the command has to find the
     * drifted limit on its own and raise the approval.
     */
    #[Test]
    public function the_command_raises_an_approval_for_a_drifted_limit(): void
    {
        $this->driftedCapitalLimit();
        $this->closeQuarter();

        $this->artisan('measures:rebaseline-thresholds')
            ->expectsOutputToContain('1 approval(s) raised')
            ->assertSuccessful();

        $approval = ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->firstOrFail();

        $this->assertSame('pending', $approval->status);
        $this->assertSame('measure_threshold', $approval->entity_type);

        // Raising a task is not moving a limit — the band in force is untouched
        // until somebody with threshold.rebaseline_approve accepts it.
        $this->assertEqualsWithDelta(
            130_000_000_000.0,
            collect($approval->payload['proposed_bands'])->firstWhere('code', 'red')['min'],
            0.01,
        );
    }

    /** Re-running the nightly job must not raise the same approval twice. */
    #[Test]
    public function a_second_run_does_not_raise_the_approval_again(): void
    {
        $this->driftedCapitalLimit();
        $this->closeQuarter();

        $this->artisan('measures:rebaseline-thresholds')->assertSuccessful();
        $this->artisan('measures:rebaseline-thresholds')->assertSuccessful();

        $this->assertSame(
            1,
            ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->count(),
            'A nightly job that raises a duplicate approval every night teaches people to ignore the queue.',
        );
    }

    /** `--organization` narrows the run to one tenant. */
    #[Test]
    public function the_organization_option_limits_the_run(): void
    {
        $this->driftedCapitalLimit();
        $this->closeQuarter();

        $other = Organization::create([
            'name' => 'Rival Bank PLC', 'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $this->artisan('measures:rebaseline-thresholds', ['--organization' => $other->id])
            ->assertFailed();

        $this->assertSame(
            0,
            ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->count(),
            'A run scoped to another organisation must not touch this one.',
        );

        $this->artisan('measures:rebaseline-thresholds', ['--organization' => $this->organization->id])
            ->assertSuccessful();

        $this->assertSame(1, ApprovalRequest::where('action', ThresholdRebaselineService::ACTION)->count());
    }

    /**
     * An organisation with no closed period is reported, not silently treated
     * as "nothing drifted".
     */
    #[Test]
    public function an_organisation_with_no_closed_period_fails_loudly(): void
    {
        $this->driftedCapitalLimit();
        // Deliberately NOT closing the quarter.

        $this->artisan('measures:rebaseline-thresholds', ['--organization' => $this->organization->id])
            ->expectsOutputToContain('No closed period found')
            ->assertFailed();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Capital has grown from the NGN 200bn the band was set against to NGN
     * 260bn, so 0.5% of it is 30% above the limit in force — well past
     * tolerance.
     */
    private function driftedCapitalLimit(): void
    {
        $anchor = $this->makeRisk(['risk_code' => 'RK-ANCHOR', 'title' => 'Reporting entity anchor']);

        $this->measures()->record(
            MeasureCatalog::CAPITAL_TOTAL_QUALIFYING,
            $anchor,
            $this->quarter,
            26_000_000_000_000,
            ['detect_breach' => false, 'currency_code' => 'NGN'],
        );

        $exposure = $this->measures()->requireMeasure(MeasureCatalog::RISK_FINANCIAL_EXPOSURE);

        $this->attachThreshold($exposure, [
            ['code' => 'green', 'label' => 'Within limit', 'color' => '#16A34A',
                'min' => null, 'max' => 100_000_000_000, 'max_formula' => '0.005 * @capital()'],
            ['code' => 'red', 'label' => 'Over limit', 'color' => '#DC2626',
                'min' => 100_000_000_000, 'max' => null, 'min_formula' => '0.005 * @capital()'],
        ]);
    }

    private function closeQuarter(): void
    {
        TenantContext::bypass(
            fn () => Period::withoutGlobalScopes()
                ->whereKey($this->quarter->id)
                ->update(['is_closed' => true]),
            'test fixture: close the period the nightly job reads',
        );
    }
}
