<?php

namespace Tests\Feature\Console;

use App\Events\Tprm\ConcentrationThresholdBreached;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\ConcentrationAnalysis;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Graph\ConcentrationAnalyzer;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `tprm:run-concentration` — FR-NTH-03 through FR-NTH-05, the weekly
 * portfolio snapshot.
 *
 * `ConcentrationService` already has direct coverage (GraphAndAccessTest),
 * including that the alert "fires once and not every run" even though the
 * SNAPSHOT itself is written every run by design (the docblock: "every run
 * writes a row even when nothing changed"). This file covers only what the
 * wrapper adds: the per-tenant loop, `--dimension`, and that the wrapper's
 * own "no-op" case (nothing breaching) is silent rather than alarming.
 */
class RunTprmConcentrationCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private ThirdParty $vendor;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->organization = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->manager = User::create([
            'name' => 'Manager', 'email' => 'tprm@lub.test',
            'password' => bcrypt('secret'), 'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $this->vendor = ThirdParty::create([
            'legal_name' => 'Cloudspan Nigeria Limited', 'slug' => Str::random(12),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_snapshots_every_dimension_and_reports_a_standing_breach(): void
    {
        config()->set('tprm.scoring.concentration.max_critical_functions_per_group', 3);
        $this->concentrateFiveCriticalFunctionsOn();

        $this->artisan('tprm:run-concentration')
            ->expectsOutputToContain('threshold breach(es) standing')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $dimensions = ConcentrationAnalysis::query()->where('organization_id', $this->organization->id)
            ->pluck('dimension')->unique()->sort()->values()->all();
        TenantContext::clear();

        $this->assertEquals(
            collect(ConcentrationAnalyzer::DIMENSIONS)->keys()->sort()->values()->all(),
            $dimensions,
            'The wrapper did not run every dimension.',
        );
    }

    #[Test]
    public function it_dispatches_the_breach_alert_once_even_though_it_snapshots_every_run(): void
    {
        config()->set('tprm.scoring.concentration.max_critical_functions_per_group', 3);
        $this->concentrateFiveCriticalFunctionsOn();

        Event::fake([ConcentrationThresholdBreached::class]);

        $this->artisan('tprm:run-concentration', ['--dimension' => 'provider_group'])->assertSuccessful();

        TenantContext::set($this->organization->id);
        $countAfterFirst = ConcentrationAnalysis::query()->where('dimension', 'provider_group')->count();
        TenantContext::clear();

        $this->artisan('tprm:run-concentration', ['--dimension' => 'provider_group'])->assertSuccessful();

        TenantContext::set($this->organization->id);
        $countAfterSecond = ConcentrationAnalysis::query()->where('dimension', 'provider_group')->count();
        TenantContext::clear();

        // The snapshot is written every time by design...
        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(2, $countAfterSecond, 'Each run should write its own immutable snapshot row.');

        // ...but the alert, which exists to be read and acted on, must not
        // fire a second time for a breach set that has not changed.
        Event::assertDispatchedTimes(ConcentrationThresholdBreached::class, 1);
    }

    #[Test]
    public function it_no_ops_quietly_when_nothing_breaches(): void
    {
        // A single engagement is trivially "concentrated": with nobody else
        // in the portfolio the analyzer's own fallback (no critical
        // functions anywhere gives an engagement-count-weighted index
        // instead) puts one provider at 100% of it, an HHI of 10000. A
        // genuinely unconcentrated portfolio needs enough distinct,
        // no-critical-function providers to dilute that below the 2500 band
        // edge: four equal clusters give exactly 2500, which the breach
        // check (`> 2500`) does not trip.
        for ($i = 1; $i <= 3; $i++) {
            $vendor = ThirdParty::create([
                'legal_name' => "Diversified Vendor {$i}", 'slug' => Str::random(12),
                'entity_type' => 'company', 'status' => 'active',
            ]);
            $engagement = Engagement::create([
                'third_party_id' => $vendor->id,
                'reference' => "ENG-2026-DIV-{$i}",
                'name' => "Diversified service {$i}",
                'service_description' => 'Fixture diluting the portfolio.',
                'engagement_type' => 'ict_service',
                'relationship_owner_id' => $this->manager->id,
            ]);
            $engagement->forceFill([
                'status' => \App\Enums\Tprm\EngagementStatus::Active->value,
                'inherent_score' => 40, 'inherent_tier' => \App\Enums\Tprm\RiskTier::Low->value,
                'effective_tier' => \App\Enums\Tprm\RiskTier::Low->value,
            ])->save();
        }

        $this->artisan('tprm:run-concentration', ['--dimension' => 'provider_group'])
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $analysis = ConcentrationAnalysis::query()->where('dimension', 'provider_group')->latest('id')->first();
        TenantContext::clear();

        $this->assertLessThanOrEqual(2500, $analysis->hhi, 'Fixture is not actually diversified, so this test proves nothing.');
        $this->assertSame([], $analysis->threshold_breaches);
    }

    #[Test]
    public function it_no_ops_when_the_feature_is_switched_off(): void
    {
        config()->set('features.tprm', false);

        $this->artisan('tprm:run-concentration')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $count = ConcentrationAnalysis::query()->count();
        TenantContext::clear();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function the_dimension_option_runs_only_the_named_dimension(): void
    {
        $this->artisan('tprm:run-concentration', ['--dimension' => 'provider_group'])
            ->expectsOutputToContain('1 snapshot(s) taken across 1 tenant(s)')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $count = ConcentrationAnalysis::query()->count();
        TenantContext::clear();

        $this->assertSame(1, $count);
    }

    #[Test]
    public function each_organisation_is_snapshotted_under_its_own_tenant_context(): void
    {
        $orgB = Organization::create([
            'name' => 'Second Bank', 'short_name' => 'SB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        RiskCategory::create([
            'organization_id' => $orgB->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);
        TenantContext::set($orgB->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::clear();

        $this->artisan('tprm:run-concentration', ['--dimension' => 'provider_group'])
            ->expectsOutputToContain('2 tenant(s)')
            ->assertSuccessful();

        TenantContext::set($this->organization->id);
        $countA = ConcentrationAnalysis::query()->where('organization_id', $this->organization->id)->count();
        TenantContext::clear();

        TenantContext::set($orgB->id);
        $countB = ConcentrationAnalysis::query()->where('organization_id', $orgB->id)->count();
        TenantContext::clear();

        $this->assertSame(1, $countA);
        $this->assertSame(1, $countB);
    }

    private function concentrateFiveCriticalFunctionsOn(): void
    {
        $this->engagement->forceFill([
            'substitutability' => 'none',
            'time_to_replace_months' => 18,
            'annual_spend_minor' => 480_000_000,
        ])->save();

        $functions = collect(range(1, 5))->map(fn (int $index) => BusinessFunction::create([
            'organization_id' => $this->organization->id,
            'function_code' => 'BF-'.$index,
            'name' => 'Critical function '.$index,
            'criticality' => 'critical',
            'is_active' => true,
        ]));

        foreach ($functions as $function) {
            $this->engagement->businessFunctions()->attach($function->id, [
                'organization_id' => $this->engagement->organization_id,
                'dependency_level' => 'primary',
                'reliance_level' => 'high',
            ]);
        }

        $this->engagement->refresh();
    }

    private function makeEngagement(): Engagement
    {
        $engagement = Engagement::create([
            'third_party_id' => $this->vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => 'Core banking hosting',
            'service_description' => 'Recorded for the scheduled-command fixtures.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->manager->id,
        ]);

        $engagement->forceFill([
            'status' => \App\Enums\Tprm\EngagementStatus::Active->value,
            'inherent_score' => 70,
            'inherent_tier' => \App\Enums\Tprm\RiskTier::High->value,
            'effective_tier' => \App\Enums\Tprm\RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 70, 'resulting_tier' => \App\Enums\Tprm\RiskTier::High->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
    }
}
