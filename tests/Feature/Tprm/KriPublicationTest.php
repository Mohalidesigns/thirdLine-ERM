<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\RiskTier;
use App\Models\KeyRiskIndicator;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\KriLink;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Reporting\KriCalculator;
use App\Services\Tprm\Reporting\KriPublisher;
use App\Support\Tprm\KriCatalogue;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * FR-RPT-10 — the nine third-party KRIs, and item 10's overview screen.
 *
 * ALMOST EVERY ASSERTION HERE IS ABOUT NULL NOT BEING ZERO. A KRI feeds breach
 * detection: a fabricated 0% on an empty denominator opens a red breach,
 * notifies an owner and reaches a board pack, all from a division by nothing.
 * "Percentage of Critical vendors assessed within cadence" over an estate with
 * no Critical vendors is not 0 and is not 100 — it is a statement about an
 * empty set, and the publisher must decline to make it.
 *
 * The second theme is that adoption is one-way. The first publish creates the
 * indicator with shipped defaults; every publish after that writes only a
 * measurement, because a republish that reset a threshold somebody tuned would
 * be the module overruling its own user once a day, silently.
 */
class KriPublicationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Calabar Heritage Bank', 'short_name' => 'CHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->admin = $this->user('Programme Admin', 'admin@chb.test', [
            'tprm.view', 'tprm.admin', 'tprm.report.view', 'tprm.monitoring.view',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  Null is not zero */
    /* ================================================================== */

    #[Test]
    public function a_percentage_over_an_empty_population_is_not_computable(): void
    {
        // No Critical engagements at all.
        $reading = app(KriCalculator::class)->compute('critical_assessed_within_cadence');

        // Not 0, which would say the programme has failed; not 100, which
        // would say it is perfect. Both are statements about an empty set.
        $this->assertNull($reading['value']);
        $this->assertStringContainsString('no population to measure', $reading['note']);
    }

    #[Test]
    public function an_average_over_no_findings_is_not_zero_days(): void
    {
        $reading = app(KriCalculator::class)->compute('average_finding_age');

        // Zero days would read as "every finding was closed the day it was
        // raised", which is a much better claim than "there are none open".
        $this->assertNull($reading['value']);
        $this->assertStringContainsString('No third-party finding is open', $reading['note']);
    }

    #[Test]
    public function a_count_over_an_empty_set_is_a_legitimate_zero(): void
    {
        $overdue = app(KriCalculator::class)->compute('overdue_findings');
        $evidence = app(KriCalculator::class)->compute('expired_mandatory_evidence');

        // A count IS computable over nothing: "zero overdue findings" is true
        // and useful, unlike a zero average.
        $this->assertSame(0.0, $overdue['value']);
        $this->assertSame(0.0, $evidence['value']);
    }

    #[Test]
    public function assurance_depth_declines_to_report_over_unscored_engagements(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical);
        $this->engagement('ENG-2', RiskTier::High);

        $reading = app(KriCalculator::class)->compute('assurance_depth');

        // The metric exists to detect comfort resting on assertion rather than
        // evidence. Reporting a number over an estate nobody has scored would
        // be that exact failure.
        $this->assertNull($reading['value']);
        $this->assertStringContainsString('no assurance to measure the depth of', $reading['note']);
    }

    #[Test]
    public function assurance_depth_excludes_unscored_engagements_rather_than_counting_them_as_zero(): void
    {
        $scored = $this->engagement('ENG-SCORED', RiskTier::Critical);
        $scored->forceFill(['evidence_confidence' => 0.85])->save();

        $this->engagement('ENG-UNSCORED', RiskTier::High);

        $reading = app(KriCalculator::class)->compute('assurance_depth');

        // 0.85, not 0.425. An estate half unassessed must not read as half as
        // well evidenced.
        $this->assertSame(0.85, $reading['value']);
        $this->assertStringContainsString('1 carry no evidence coefficient', $reading['note']);
    }

    #[Test]
    public function an_engagement_with_no_cadence_counts_as_out_of_cadence(): void
    {
        $current = $this->engagement('ENG-CURRENT', RiskTier::Critical);
        $current->forceFill(['next_assessment_due' => now()->addMonths(3)->toDateString()])->save();

        // Never assessed, so no due date. It is out of cadence, not excluded —
        // excluding it would let an unassessed estate score 100%.
        $this->engagement('ENG-NEVER', RiskTier::Critical);

        $reading = app(KriCalculator::class)->compute('critical_assessed_within_cadence');

        $this->assertSame(50.0, $reading['value']);
        $this->assertStringContainsString('1 have no assessment cadence set at all', $reading['note']);
    }

    #[Test]
    public function exit_plan_currency_measures_against_engagements_requiring_a_plan(): void
    {
        $this->engagement('ENG-A', RiskTier::Critical);
        $this->engagement('ENG-B', RiskTier::Critical);

        $reading = app(KriCalculator::class)->compute('exit_plan_test_currency');

        // 0 of 2, not "no plans so nothing to measure". A ratio over the plans
        // written flatters a programme that has written none.
        $this->assertSame(0.0, $reading['value']);
        $this->assertStringContainsString('2 have no plan at all', $reading['note']);
    }

    #[Test]
    public function an_outstanding_assessment_does_not_improve_the_response_time(): void
    {
        $reading = app(KriCalculator::class)->compute('mean_vendor_response_time');

        $this->assertNull($reading['value']);
        $this->assertStringContainsString('No assessment was submitted', $reading['note']);
    }

    /* ================================================================== */
    /*  Publication */
    /* ================================================================== */

    #[Test]
    public function adoption_creates_all_nine_indicators_and_links_them(): void
    {
        $result = app(KriPublisher::class)->adopt($this->admin->id);

        $this->assertSame(9, $result['created']);
        $this->assertCount(9, KriLink::query()->get());
        $this->assertSame(9, KeyRiskIndicator::query()->where('data_source', 'Third-Party Risk Management module')->count());

        $depth = KeyRiskIndicator::query()->where('kri_code', 'TPRM-09')->firstOrFail();
        $this->assertSame('Assurance depth (mean EC, Critical and High)', $depth->kri_name);
        // Lower is worse, so the green band is a floor rather than a ceiling.
        $this->assertSame('lower_worse', $depth->threshold_direction);
    }

    #[Test]
    public function adopting_twice_does_not_duplicate(): void
    {
        app(KriPublisher::class)->adopt($this->admin->id);
        $second = app(KriPublisher::class)->adopt($this->admin->id);

        $this->assertSame(0, $second['created']);
        $this->assertSame(9, $second['existing']);
        $this->assertCount(9, KriLink::query()->get());
    }

    #[Test]
    public function a_republish_never_overwrites_a_threshold_somebody_tuned(): void
    {
        app(KriPublisher::class)->adopt($this->admin->id);

        $kri = KeyRiskIndicator::query()->where('kri_code', 'TPRM-04')->firstOrFail();
        $kri->forceFill(['green_threshold_max' => 3, 'kri_name' => 'Overdue vendor findings (ours)'])->save();

        app(KriPublisher::class)->publish();

        $kri->refresh();

        // The module must not overrule its own user once a day, silently.
        // Compared numerically: decimal columns stringify differently on
        // SQLite and MariaDB, and this suite is due to move drivers.
        $this->assertSame(3.0, (float) $kri->green_threshold_max);
        $this->assertSame('Overdue vendor findings (ours)', $kri->kri_name);
    }

    #[Test]
    public function a_metric_with_no_computable_value_publishes_nothing_and_says_why(): void
    {
        app(KriPublisher::class)->adopt($this->admin->id);

        $result = app(KriPublisher::class)->publish();

        // Counts publish; percentages and averages over empty populations do
        // not.
        $this->assertGreaterThan(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);

        $skipped = collect($result['details'])->where('outcome', 'skipped')->pluck('metric');
        $this->assertContains('critical_assessed_within_cadence', $skipped);
        $this->assertContains('assurance_depth', $skipped);

        $link = KriLink::query()->where('metric_code', 'assurance_depth')->firstOrFail();
        $this->assertNull($link->last_published_at);
    }

    #[Test]
    public function a_computable_metric_records_a_measurement_through_the_bridge(): void
    {
        $engagement = $this->engagement('ENG-OVERDUE', RiskTier::High);

        Finding::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'third_party_id' => $engagement->third_party_id,
            'source' => 'assessment',
            'reference' => 'FND-1',
            'title' => 'Overdue gap',
            'severity' => FindingSeverity::High->value,
            'identified_at' => now()->subDays(100),
            'target_date' => now()->subDays(10)->toDateString(),
        ]);

        app(KriPublisher::class)->adopt($this->admin->id);
        $result = app(KriPublisher::class)->publish();

        $overdue = collect($result['details'])->firstWhere('metric', 'overdue_findings');

        $this->assertSame('published', $overdue['outcome']);
        $this->assertSame(1.0, $overdue['value']);

        $kri = KeyRiskIndicator::query()->where('kri_code', 'TPRM-04')->firstOrFail();

        // Written through KriMeasureBridge, so the RAG band moved with the
        // number rather than the column being set behind the module's back.
        $this->assertNotNull($kri->last_measurement_at);
        $this->assertSame(1.0, (float) $kri->current_value);

        $link = KriLink::query()->where('metric_code', 'overdue_findings')->firstOrFail();
        $this->assertNotNull($link->last_published_at);
    }

    #[Test]
    public function the_command_names_every_skip_with_its_reason(): void
    {
        app(KriPublisher::class)->adopt($this->admin->id);

        $this->artisan('tprm:publish-kris')
            ->expectsOutputToContain('Calabar Heritage Bank:')
            // A run reporting only its successes would hide exactly the
            // metrics whose absence somebody needs to know about.
            ->expectsOutputToContain('No engagement is tiered Critical')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_command_does_not_adopt_unless_asked(): void
    {
        $this->artisan('tprm:publish-kris')->assertExitCode(0);

        // Creating nine KRIs in a bank's register is a change to their risk
        // framework, not a reporting side effect.
        $this->assertSame(0, KriLink::query()->count());
    }

    /* ================================================================== */
    /*  The overview */
    /* ================================================================== */

    #[Test]
    public function the_overview_leads_with_what_needs_attention(): void
    {
        $engagement = $this->engagement('ENG-OVERDUE', RiskTier::Critical);
        $engagement->forceFill(['next_assessment_due' => now()->subMonth()->toDateString()])->save();

        $this->actingAs($this->admin)
            ->get(route('tprm.overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Overview')
                ->has('attention', 4)
                ->where('attention.0.label', 'Critical or High past their assessment date')
                ->where('attention.0.value', 1)
                ->where('attention.0.tone', 'critical')
                ->has('tiers')
                ->has('kris', 9)
                ->where('can.adopt_kris', true)
            );
    }

    #[Test]
    public function the_tier_distribution_keeps_untiered_separate_from_low(): void
    {
        $this->engagement('ENG-LOW', RiskTier::Low);
        $this->engagement('ENG-NONE', null);

        $response = $this->actingAs($this->admin)->get(route('tprm.overview'))->assertOk();

        $tiers = collect($response->viewData('page')['props']['tiers'])->keyBy('label');

        $this->assertSame(1, $tiers['Low']['count']);
        // Not found to be low risk — not looked at.
        $this->assertSame(1, $tiers['Not tiered']['count']);
    }

    #[Test]
    public function the_kri_panel_is_hidden_from_somebody_without_the_reporting_permission(): void
    {
        $basic = $this->user('Register Reader', 'reader@chb.test', ['tprm.view']);

        $this->actingAs($basic)
            ->get(route('tprm.overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('kris', 0)
                ->where('can.view_kris', false)
                ->where('can.adopt_kris', false)
            );
    }

    #[Test]
    public function adopting_the_kris_is_tprm_admin(): void
    {
        $reporter = $this->user('Reporter', 'reporter@chb.test', ['tprm.view', 'tprm.report.view']);

        $this->actingAs($reporter)->post(route('tprm.kris.adopt'))->assertForbidden();
        $this->assertSame(0, KriLink::query()->count());

        $this->actingAs($this->admin)->post(route('tprm.kris.adopt'))->assertRedirect();
        $this->assertSame(9, KriLink::query()->count());
    }

    /* ================================================================== */

    #[Test]
    public function the_catalogue_ships_exactly_nine_metrics_with_unique_codes(): void
    {
        $codes = KriCatalogue::codes();

        $this->assertCount(9, $codes);
        $this->assertSame($codes, array_unique($codes));
        $this->assertContains('assurance_depth', $codes);
    }

    /* ================================================================== */

    /** @param  list<string>  $permissions */
    private function user(string $name, string $email, array $permissions): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('role-'.Str::slug($email), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function engagement(string $reference, ?RiskTier $tier): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $reference.' provider',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => 'Service for '.$reference,
            'engagement_type' => 'ict_service',
        ]);

        $engagement->forceFill(array_filter([
            'status' => EngagementStatus::Active->value,
            'effective_tier' => $tier?->value,
        ], fn ($value) => $value !== null))->save();

        return $engagement->refresh();
    }
}
