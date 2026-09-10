<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BoardPack;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Reporting\BoardNarrativeWriter;
use App\Services\Tprm\Reporting\BoardPackBuilder;
use App\Services\Tprm\Reporting\BoardPackService;
use Carbon\CarbonImmutable;
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
 * FR-RPT-05 — the Board and Risk Committee pack.
 *
 * THE POINT OF THE TABLE IS THAT A SIGNED-OFF PACK STOPS MOVING. Every other
 * assertion here follows from that: the figures are frozen on the row, a
 * refresh of an approved period is refused rather than warned about, and the
 * trend plots only packs somebody stood behind. A pack rendered live would
 * pass a naive test and restate itself the first time a vendor was retiered,
 * leaving the committee's minutes citing numbers the system can no longer
 * produce.
 *
 * The second theme is that nothing in the pack invents a figure. An unscored
 * engagement is excluded from the portfolio average rather than counted as
 * zero — a portfolio average dragged down by vendors nobody has assessed reads
 * as a safe portfolio.
 */
class BoardPackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $preparer;

    private User $cro;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        // AI is off by default in this product, and the deterministic
        // narrative is the one every installation actually gets.
        config()->set('tprm.ai.enabled', false);

        $this->bank = Organization::create([
            'name' => 'Enugu Commerce Bank', 'short_name' => 'ECB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->preparer = $this->user('Preparer', 'preparer@ecb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.report.export',
        ]);

        $this->cro = $this->user('Chief Risk Officer', 'cro@ecb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.report.export', 'tprm.admin',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  The snapshot */
    /* ================================================================== */

    #[Test]
    public function a_signed_off_pack_keeps_the_figures_it_was_approved_with(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        $this->assertSame(1, $pack->figures['portfolio']['total']);

        app(BoardPackService::class)->signOff($pack, $this->cro->id);

        // The world moves on.
        $this->engagement('ENG-2', RiskTier::High, 60.0);

        // And the pack does not.
        $this->assertSame(1, $pack->refresh()->figures['portfolio']['total']);
    }

    #[Test]
    public function refreshing_an_approved_period_is_refused_rather_than_allowed_with_a_warning(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);
        app(BoardPackService::class)->signOff($pack, $this->cro->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        // Quietly recomputing under the same label is how minutes stop
        // matching the pack they cite.
        app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);
    }

    #[Test]
    public function an_edited_narrative_survives_a_refresh_of_the_figures(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $service = app(BoardPackService::class);
        $pack = $service->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        $service->editNarrative($pack, 'The committee should note the core banking dependency.', $this->cro->id);

        $refreshed = $service->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        // Somebody spent an hour on that paragraph. Regenerating it because a
        // number moved would teach them not to write it until the end.
        $this->assertStringContainsString('core banking dependency', $refreshed->narrative);
        $this->assertSame(BoardPack::SOURCE_EDITED, $refreshed->narrative_source);
        $this->assertSame($this->cro->id, $refreshed->narrative_edited_by);
    }

    #[Test]
    public function sign_off_requires_a_narrative(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);
        $pack->forceFill(['narrative' => null])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/narrative/');

        app(BoardPackService::class)->signOff($pack->refresh(), $this->cro->id);
    }

    #[Test]
    public function the_trend_plots_signed_off_packs_only(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $service = app(BoardPackService::class);

        $signed = $service->prepare('Q1 2027', CarbonImmutable::parse('2027-03-31'), $this->preparer->id);
        $service->signOff($signed, $this->cro->id);

        $service->prepare('Q2 2027', CarbonImmutable::parse('2027-06-30'), $this->preparer->id);

        $trend = $service->trend();

        // A line that moved because somebody re-ran a draft this morning is a
        // line nobody can discuss in a meeting.
        $this->assertCount(1, $trend);
        $this->assertSame('Q1 2027', $trend[0]['period_label']);
    }

    /* ================================================================== */
    /*  The figures */
    /* ================================================================== */

    #[Test]
    public function an_unscored_engagement_is_excluded_from_the_average_rather_than_counted_as_zero(): void
    {
        $this->engagement('ENG-SCORED', RiskTier::High, 60.0);
        $this->engagement('ENG-UNSCORED', RiskTier::High, null);

        $portfolio = app(BoardPackBuilder::class)->figures()['portfolio'];

        $this->assertSame(2, $portfolio['total']);
        $this->assertSame(1, $portfolio['scored']);
        $this->assertSame(1, $portfolio['unscored']);
        // 60, not 30. An average dragged down by vendors nobody assessed reads
        // as a safe portfolio.
        $this->assertSame(60.0, $portfolio['mean_residual']);
    }

    #[Test]
    public function an_untiered_engagement_is_counted_separately_from_low_risk(): void
    {
        $this->engagement('ENG-LOW', RiskTier::Low, 10.0);
        $this->engagement('ENG-NOTIER', null, null);

        $portfolio = app(BoardPackBuilder::class)->figures()['portfolio'];

        $byTier = collect($portfolio['by_tier'])->keyBy('label');

        $this->assertSame(1, $byTier['Low']['count']);
        // Not found to be low risk — not looked at.
        $this->assertSame(1, $portfolio['untiered']);
    }

    #[Test]
    public function finding_age_is_measured_from_identification_not_from_the_target_date(): void
    {
        $engagement = $this->engagement('ENG-F', RiskTier::High, 60.0);

        Finding::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'third_party_id' => $engagement->third_party_id,
            'source' => 'assessment',
            'reference' => 'FND-OLD',
            'title' => 'Long-running gap',
            'severity' => FindingSeverity::High->value,
            'identified_at' => now()->subDays(200),
            // Moved three times; the finding is still 200 days old.
            'target_date' => now()->addDays(30)->toDateString(),
        ]);

        $findings = app(BoardPackBuilder::class)->figures()['findings'];

        $this->assertSame(1, $findings['open']);
        $this->assertSame(1, $findings['by_age']['over_180']);
        $this->assertSame(200, $findings['mean_age_days']);
        // Its target date is in the future, so it is not overdue — which is a
        // separate fact from being old.
        $this->assertSame(0, $findings['overdue']);
    }

    #[Test]
    public function an_engagement_with_no_assessment_cadence_is_not_reported_as_compliant(): void
    {
        $overdue = $this->engagement('ENG-OVERDUE', RiskTier::Critical, 80.0);
        $overdue->forceFill(['next_assessment_due' => now()->subMonth()->toDateString()])->save();

        $this->engagement('ENG-NOCADENCE', RiskTier::Critical, 80.0);

        $assessments = app(BoardPackBuilder::class)->figures()['overdue_assessments'];

        $this->assertSame(1, $assessments['overdue']);
        $this->assertSame(1, $assessments['critical_or_high_overdue']);
        // Nothing is due, so it is not overdue. A different problem, and not a
        // smaller one.
        $this->assertSame(1, $assessments['no_cadence_set']);
    }

    /* ================================================================== */
    /*  The narrative */
    /* ================================================================== */

    #[Test]
    public function the_narrative_is_assembled_deterministically_when_ai_is_off(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        // Not a blank box on every installation that has not bought a model
        // subscription.
        $this->assertNotEmpty($pack->narrative);
        $this->assertSame(BoardPack::SOURCE_DETERMINISTIC, $pack->narrative_source);
        $this->assertStringContainsString('No model was used', $pack->narrativeProvenance());
    }

    #[Test]
    public function the_narrative_leads_with_what_needs_a_decision(): void
    {
        $figures = [
            'portfolio' => ['total' => 12, 'supports_critical_function' => 3, 'material_outsourcing' => 2,
                'scored' => 12, 'unscored' => 0, 'mean_residual' => 41.2, 'untiered' => 0],
            'exit_readiness' => ['require_a_plan' => 5, 'no_plan' => 4, 'never_tested' => 1],
            'findings' => ['open' => 6, 'overdue' => 2, 'mean_age_days' => 44, 'by_age' => ['over_180' => 1]],
            'overdue_assessments' => ['critical_or_high_overdue' => 3],
            'expiring_evidence' => ['already_expired' => 2],
            'incidents' => ['count' => 0],
            'overrides_and_waivers' => ['lapsed_but_not_withdrawn' => 0],
        ];

        $text = app(BoardNarrativeWriter::class)->assemble($figures);

        $firstParagraph = explode("\n\n", $text)[0];

        // A narrative that opened with the size of the portfolio would be
        // answering a question nobody asked.
        $this->assertStringContainsString('committee is asked to note', $firstParagraph);
        $this->assertStringContainsString('4 of the 5 engagements', $firstParagraph);
        $this->assertStringNotContainsString('12 live engagements', $firstParagraph);
    }

    #[Test]
    public function the_narrative_states_an_absent_figure_rather_than_estimating_it(): void
    {
        $figures = [
            'portfolio' => ['total' => 4, 'supports_critical_function' => 0, 'material_outsourcing' => 0,
                'scored' => 0, 'unscored' => 4, 'mean_residual' => null, 'untiered' => 4],
            'exit_readiness' => ['require_a_plan' => 0, 'no_plan' => 0, 'never_tested' => 0],
            'findings' => ['open' => 0, 'overdue' => 0, 'mean_age_days' => null, 'by_age' => ['over_180' => 0]],
            'overdue_assessments' => ['critical_or_high_overdue' => 0],
            'expiring_evidence' => ['already_expired' => 0],
            'incidents' => ['count' => 0],
            'overrides_and_waivers' => ['lapsed_but_not_withdrawn' => 0],
        ];

        $text = app(BoardNarrativeWriter::class)->assemble($figures);

        $this->assertStringContainsString('no figure', $text);
        $this->assertStringContainsString('have not been tiered at all', $text);
    }

    /* ================================================================== */
    /*  The routes */
    /* ================================================================== */

    #[Test]
    public function signing_off_is_tprm_admin_and_not_the_permission_that_runs_a_report(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        // The preparer can submit their own pack for review...
        $this->actingAs($this->preparer)
            ->post(route('tprm.reports.board-packs.transition', $pack), ['to' => BoardPack::STATUS_IN_REVIEW])
            ->assertRedirect();

        $this->assertSame(BoardPack::STATUS_IN_REVIEW, $pack->refresh()->status);

        // ...and cannot stand behind it in front of a board committee.
        $this->actingAs($this->preparer)
            ->post(route('tprm.reports.board-packs.transition', $pack), ['to' => BoardPack::STATUS_SIGNED_OFF])
            ->assertForbidden();

        $this->actingAs($this->cro)
            ->post(route('tprm.reports.board-packs.transition', $pack), ['to' => BoardPack::STATUS_SIGNED_OFF])
            ->assertRedirect();

        $this->assertTrue($pack->refresh()->isSignedOff());
        $this->assertSame($this->cro->id, $pack->signed_off_by);
    }

    #[Test]
    public function a_frozen_pack_reports_its_refusal_as_a_message_rather_than_a_stack_trace(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);
        app(BoardPackService::class)->signOff($pack, $this->cro->id);

        $this->actingAs($this->preparer)
            ->post(route('tprm.reports.board-packs.prepare'), [
                'period_label' => 'Q1 2027',
                'as_at' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    #[Test]
    public function the_pack_exports_a_branded_pdf_carrying_its_frozen_figures(): void
    {
        $this->engagement('ENG-PDF', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        $pdf = $this->actingAs($this->preparer)
            ->get(route('tprm.reports.board-packs.export', $pack))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(20_000, strlen($pdf));
    }

    #[Test]
    public function the_screens_hand_the_pages_the_props_they_read(): void
    {
        $this->engagement('ENG-1', RiskTier::Critical, 80.0);

        $pack = app(BoardPackService::class)->prepare('Q1 2027', CarbonImmutable::now(), $this->preparer->id);

        $this->actingAs($this->cro)
            ->get(route('tprm.reports.board-packs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/BoardPacks')
                ->has('packs', 1)
                ->where('packs.0.period_label', 'Q1 2027')
                ->where('can.sign_off', true)
            );

        $this->actingAs($this->preparer)
            ->get(route('tprm.reports.board-packs.show', $pack))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/BoardPack')
                ->has('pack.figures.portfolio')
                ->has('pack.narrative')
                ->where('can.sign_off', false)
            );
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

    private function engagement(string $reference, ?RiskTier $tier, ?float $residual): Engagement
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
            'residual_score' => $residual,
            'residual_band' => $residual === null ? null : 'high',
        ], fn ($value) => $value !== null))->save();

        return $engagement->refresh();
    }
}
