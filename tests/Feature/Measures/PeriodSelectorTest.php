<?php

namespace Tests\Feature\Measures;

use App\Http\Middleware\ResolvePeriod;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesMeasureFixtures;
use Tests\TestCase;

/**
 * WP-04 acceptance: the period selector in the top bar changes what the
 * register and the dashboard show.
 */
class PeriodSelectorTest extends TestCase
{
    use CreatesDomainFixtures, CreatesMeasureFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-10 09:00:00'));

        $this->bootDomainFixtures();
        $this->bootMeasureEngine();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
        $this->actor->forceFill(['mfa_enabled' => false])->save();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_top_bar_renders_the_selected_period(): void
    {
        $response = $this->actingAs($this->actor)->get('/risk/register');

        $response->assertOk();
        $response->assertSee($this->periods()->current('month')->name);
    }

    #[Test]
    public function selecting_a_period_persists_across_requests(): void
    {
        $q1 = $this->quarter('2026-02-15');

        $this->actingAs($this->actor)
            ->get(route('risk.periods.select', ['period' => $q1->code, 'redirect' => '/risk/register']))
            ->assertRedirect('/risk/register');

        $this->assertSame($q1->id, session(ResolvePeriod::SESSION_KEY));

        // A later, unrelated request keeps it.
        $this->actingAs($this->actor)->get('/risk/register')->assertSee($q1->name);
    }

    #[Test]
    public function the_arrows_step_the_period(): void
    {
        $q2 = $this->quarter('2026-05-15');

        $this->actingAs($this->actor)->get(route('risk.periods.select', ['period' => $q2->code]));
        $this->actingAs($this->actor)->get(route('risk.periods.select', ['direction' => 'previous']));

        $this->assertSame($this->quarter('2026-02-15')->id, session(ResolvePeriod::SESSION_KEY));

        $this->actingAs($this->actor)->get(route('risk.periods.select', ['direction' => 'next']));

        $this->assertSame($q2->id, session(ResolvePeriod::SESSION_KEY));
    }

    #[Test]
    public function switching_granularity_keeps_the_point_in_time(): void
    {
        $march = $this->month('2026-03-15');

        $this->actingAs($this->actor)->get(route('risk.periods.select', ['period' => $march->code]));
        $this->actingAs($this->actor)->get(route('risk.periods.select', ['type' => 'quarter']));

        $this->assertSame($this->quarter('2026-03-15')->id, session(ResolvePeriod::SESSION_KEY));
    }

    #[Test]
    public function a_period_belonging_to_another_tenant_is_ignored(): void
    {
        $other = \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignPeriodId = \ThirdLine\Platform\Tenancy\TenantContext::actingAs(
            $other->id,
            fn () => $this->periods()->current('quarter', $other->id)->id
        );

        $this->actingAs($this->actor)
            ->get(route('risk.periods.select', ['period' => $foreignPeriodId]))
            ->assertSessionHas('error');

        $this->assertNotSame($foreignPeriodId, session(ResolvePeriod::SESSION_KEY));
    }

    #[Test]
    public function an_off_site_redirect_target_is_refused(): void
    {
        $q1 = $this->quarter('2026-02-15');

        $this->actingAs($this->actor)
            ->from('/risk/register')
            ->get(route('risk.periods.select', ['period' => $q1->code, 'redirect' => 'https://evil.test/steal']))
            ->assertRedirect('/risk/register');
    }

    #[Test]
    public function the_register_shows_the_scores_that_were_approved_in_the_selected_period(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01', 'title' => 'Naira liquidity squeeze']);

        $this->approveAssessment($risk, '2026-02-15', [
            'likelihood_score' => 5, 'impact_financial' => 5, 'overall_score' => 25,
            'residual_likelihood' => 5, 'residual_impact' => 5, 'residual_score' => 25,
        ]);
        $this->approveAssessment($risk, '2026-05-20', [
            'likelihood_score' => 1, 'impact_financial' => 2, 'overall_score' => 2,
            'residual_likelihood' => 1, 'residual_impact' => 2, 'residual_score' => 2,
        ]);

        $q1 = $this->quarter('2026-02-15');
        $q2 = $this->quarter('2026-05-20');

        $this->actingAs($this->actor)->get(route('risk.periods.select', ['period' => $q1->code]));
        $inQ1 = $this->actingAs($this->actor)->get('/risk/register');

        $inQ1->assertOk();

        // Migration Phase 3.2 put the as-at register on Inertia
        // (Register/Historic), so the period banner and the rows are read from
        // the page's props rather than from view data. Asserted on the data
        // rather than on rendered text for the reason it always was: the filter
        // dropdown on this page contains the word "Critical" whatever the
        // register happens to hold.
        $this->assertSame($q1->name, $this->historicProps($inQ1)['asOfPeriod']['name']);

        $inQ1Risk = $this->historicRow($inQ1, $risk->id);
        $this->assertSame(25.0, (float) $inQ1Risk['residual_score']);
        $this->assertSame('Critical', $inQ1Risk['residual_rating']);

        $this->actingAs($this->actor)->get(route('risk.periods.select', ['period' => $q2->code]));
        $inQ2 = $this->actingAs($this->actor)->get('/risk/register');

        $inQ2->assertOk();
        $this->assertSame($q2->name, $this->historicProps($inQ2)['asOfPeriod']['name']);

        $inQ2Risk = $this->historicRow($inQ2, $risk->id);
        $this->assertSame(2.0, (float) $inQ2Risk['residual_score']);
        $this->assertSame('Low', $inQ2Risk['residual_rating']);
    }

    /**
     * @return array<string, mixed>
     */
    private function historicProps(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Register/Historic'));

        return $response->inertiaProps();
    }

    /**
     * @return array<string, mixed>
     */
    private function historicRow(\Illuminate\Testing\TestResponse $response, int $riskId): array
    {
        $row = collect($this->historicProps($response)['risks']['data'])->firstWhere('id', $riskId);

        $this->assertNotNull($row, "risk {$riskId} is absent from the as-at register");

        return $row;
    }

    #[Test]
    public function the_current_period_renders_the_live_register_without_the_historic_banner(): void
    {
        $this->makeRisk(['date_identified' => '2025-11-01']);

        $current = $this->periods()->current('month');
        $this->actingAs($this->actor)->get(route('risk.periods.select', ['period' => $current->code]));

        $this->actingAs($this->actor)->get('/risk/register')
            ->assertOk()
            ->assertDontSee('Showing the register as at');
    }

    #[Test]
    public function the_dashboard_reports_historic_scores_and_says_which_panels_are_not(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);

        $this->approveAssessment($risk, '2026-02-15', [
            'likelihood_score' => 5, 'impact_financial' => 5, 'overall_score' => 25,
            'residual_likelihood' => 5, 'residual_impact' => 5, 'residual_score' => 25,
        ]);

        $q1 = $this->quarter('2026-02-15');
        $this->actingAs($this->actor)->get(route('risk.periods.select', ['period' => $q1->code]));

        // The Command Centre is Inertia as of Phase 5's criterion 7, so the
        // banner's text lives in the page component and what the server sends
        // is the period behind it. Asserting the prop is the contract; the
        // banner is Dashboard.jsx's rendering of it.
        $props = $this->actingAs($this->actor)->get('/risk/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'))
            ->inertiaProps();

        $this->assertNotNull($props['asOfPeriod'], 'A closed period must be reported as historic.');
        $this->assertSame($q1->name, $props['asOfPeriod']['name']);
        $this->assertSame($q1->code, $props['asOfPeriod']['code']);
    }

    #[Test]
    public function the_calendar_screen_closes_and_reopens_a_period(): void
    {
        $risk = $this->makeRisk(['date_identified' => '2025-11-01']);
        $this->approveAssessment($risk, '2026-02-15', ['likelihood_score' => 3, 'impact_financial' => 3, 'overall_score' => 9]);

        $q1 = $this->quarter('2026-02-15');

        $this->actingAs($this->actor)
            ->post(route('risk.periods.close', $q1))
            ->assertRedirect();

        $this->assertTrue(Period::findOrFail($q1->id)->is_closed);
        $this->assertSame('locked', \App\Models\MeasureValue::where('period_id', $q1->id)->first()->status);

        $this->actingAs($this->actor)
            ->post(route('risk.periods.reopen', $q1), ['reason' => 'Late assessment submitted by the branch'])
            ->assertRedirect();

        $this->assertFalse(Period::findOrFail($q1->id)->is_closed);
    }

    #[Test]
    public function reopening_without_a_reason_is_rejected(): void
    {
        $q1 = $this->quarter('2026-02-15');
        $this->actingAs($this->actor)->post(route('risk.periods.close', $q1));

        $this->actingAs($this->actor)
            ->from(route('risk.periods.index'))
            ->post(route('risk.periods.reopen', $q1), ['reason' => 'oops'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue(Period::findOrFail($q1->id)->is_closed);
    }
}
