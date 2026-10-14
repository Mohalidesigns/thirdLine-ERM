<?php

namespace Tests\Feature\Rcsa;

use App\Presenters\NavPresenter;
use App\Support\Rcsa\RcsaCutover;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * RCSA-02 (decided 2026-10-05): "cut over + label legacy".
 *
 * Two RCSAs are live side by side (§13: "build alongside, not on top") and the
 * legacy one reads the enterprise register, not the cycle tables — so a user saw
 * two "Dashboard"s with different numbers. While a tenant is in the parallel run
 * the legacy entries are relabelled and every legacy screen says what it is;
 * once the tenant has cut over the entries leave the menu and the URLs stay
 * reachable (§13 keeps the module for an audit cycle).
 */
class LegacyLabellingTest extends CycleTestCase
{
    private const LEGACY_ROUTES = ['risk.rcsa.dashboard', 'risk.rcsa.worksheet', 'risk.rcsa.matrix'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->grant(['rcsa.view', 'rcsa.submit']);
    }

    /**
     * @return array<string, string> route => label, for the RCSA section
     */
    private function rcsaMenu(): array
    {
        $tree = app(NavPresenter::class)->for($this->actor->fresh());

        foreach ($tree['sections'] as $section) {
            if ($section['key'] === 'rcsa') {
                return collect($section['items'])->pluck('label', 'route')->all();
            }
        }

        return [];
    }

    #[Test]
    public function with_the_flag_off_the_legacy_labels_are_exactly_as_they_were(): void
    {
        config()->set('features.rcsa_v2', false);

        $menu = $this->rcsaMenu();

        $this->assertSame('Dashboard', $menu['risk.rcsa.dashboard']);
        $this->assertSame('Worksheet', $menu['risk.rcsa.worksheet']);
        $this->assertSame('Risk Matrix', $menu['risk.rcsa.matrix']);
        $this->assertArrayNotHasKey('rcsa.dashboard.index', $menu);
    }

    /**
     * Without v2 the legacy module is the install's ONLY RCSA. Hiding it on the
     * strength of a cutover date would leave the menu with no RCSA at all.
     */
    #[Test]
    public function with_the_flag_off_a_recorded_cutover_does_not_hide_anything(): void
    {
        app(RcsaCutover::class)->cutOver($this->organization);
        config()->set('features.rcsa_v2', false);

        $menu = $this->rcsaMenu();

        foreach (self::LEGACY_ROUTES as $route) {
            $this->assertArrayHasKey($route, $menu);
        }
        $this->assertSame('Dashboard', $menu['risk.rcsa.dashboard']);
    }

    #[Test]
    public function with_the_flag_on_and_no_cutover_the_legacy_entries_are_relabelled_and_stay(): void
    {
        $menu = $this->rcsaMenu();

        $this->assertSame('Register RCSA (legacy)', $menu['risk.rcsa.dashboard']);
        $this->assertSame('Legacy worksheet', $menu['risk.rcsa.worksheet']);
        $this->assertSame('Legacy risk matrix', $menu['risk.rcsa.matrix']);
        $this->assertSame('RCSA Dashboard', $menu['rcsa.dashboard.index']);

        // One menu entry called "Dashboard" at most.
        $this->assertLessThanOrEqual(1, count(array_keys($menu, 'Dashboard', true)));
        $this->assertCount(0, array_keys($menu, 'Dashboard', true));
    }

    #[Test]
    public function after_cutover_the_legacy_entries_leave_the_menu_and_the_v2_ones_remain(): void
    {
        app(RcsaCutover::class)->cutOver($this->organization);

        $menu = $this->rcsaMenu();

        foreach (self::LEGACY_ROUTES as $route) {
            $this->assertArrayNotHasKey($route, $menu);
        }
        $this->assertSame('RCSA Dashboard', $menu['rcsa.dashboard.index']);
        $this->assertArrayHasKey('rcsa.cycles.index', $menu);
        $this->assertArrayHasKey('rcsa.assessments.index', $menu);
    }

    /**
     * The tenant is read from the user, not from the install: a neighbour who
     * has not cut over keeps its (relabelled) entries.
     */
    #[Test]
    public function cutover_hides_the_entries_for_that_tenant_only(): void
    {
        app(RcsaCutover::class)->cutOver($this->organization);

        $this->assertArrayNotHasKey('risk.rcsa.dashboard', $this->rcsaMenu());

        $other = $this->userWith(['rcsa.view', 'rcsa_assessment.view', 'rcsa_cycle.view']);
        $other->forceFill(['organization_id' => $this->otherOrg->id])->save();

        $tree = app(NavPresenter::class)->for($other->fresh());
        $labels = collect($tree['sections'])->firstWhere('key', 'rcsa')['items'] ?? [];
        $labels = collect($labels)->pluck('label', 'route')->all();

        $this->assertSame('Register RCSA (legacy)', $labels['risk.rcsa.dashboard'] ?? null);
    }

    #[Test]
    public function the_static_definition_keeps_the_plain_labels_for_the_permission_gate_test(): void
    {
        $items = collect(NavPresenter::allItems())->keyBy('route');

        $this->assertSame('Dashboard', $items['risk.rcsa.dashboard']['label']);
        $this->assertSame('rcsa.view', $items['risk.rcsa.dashboard']['permission']);
    }

    /* ------------------------------------------------------------------ */
    /*  The notice on the legacy screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_legacy_screens_carry_the_notice_before_cutover(): void
    {
        foreach (['risk.rcsa.dashboard', 'risk.rcsa.worksheet', 'risk.rcsa.matrix', 'risk.rcsa.controls'] as $route) {
            $this->actingAs($this->actor)
                ->get(route($route))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('legacyNotice.v2', true)
                    ->where('legacyNotice.cutOver', false)
                    ->where('legacyNotice.cutOverAt', null)
                    ->where('legacyNotice.v2Url', route('rcsa.dashboard.index'))
                    ->etc());
        }
    }

    #[Test]
    public function the_legacy_screens_say_they_are_closed_after_cutover(): void
    {
        app(RcsaCutover::class)->cutOver($this->organization);

        foreach (['risk.rcsa.dashboard', 'risk.rcsa.worksheet', 'risk.rcsa.matrix', 'risk.rcsa.controls'] as $route) {
            $this->actingAs($this->actor)
                ->get(route($route))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('legacyNotice.v2', true)
                    ->where('legacyNotice.cutOver', true)
                    ->where('legacyNotice.cutOverAt', now()->toDateString())
                    ->etc());
        }
    }

    #[Test]
    public function with_the_flag_off_the_notice_is_inert(): void
    {
        config()->set('features.rcsa_v2', false);

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('legacyNotice.v2', false)
                ->where('legacyNotice.cutOver', false)
                ->where('legacyNotice.v2Url', null)
                ->etc());
    }

    /**
     * The notice links to the cycle dashboard only for somebody who may open it
     * — a link that ends in a 403 is the thing the menu is held to as well.
     */
    #[Test]
    public function the_link_is_withheld_from_somebody_who_cannot_open_the_cycle_dashboard(): void
    {
        $reader = $this->userWith(['rcsa.view']);

        $this->actingAs($reader)
            ->get(route('risk.rcsa.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('legacyNotice.v2', true)
                ->where('legacyNotice.v2Url', null)
                ->etc());
    }

    /* ------------------------------------------------------------------ */
    /*  The command says what it will do to the menu */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_cutover_command_states_the_menu_effect(): void
    {
        $this->artisan('rcsa:cutover', ['--organization' => $this->organization->id, '--force' => true])
            ->expectsOutputToContain('remove the legacy entries')
            ->expectsOutputToContain('reachable by URL')
            ->assertSuccessful();

        $this->assertFalse(app(RcsaCutover::class)->hasCutOver($this->organization->fresh()));
    }

    #[Test]
    public function the_committed_cutover_reports_that_the_entries_have_left_the_menu(): void
    {
        $this->artisan('rcsa:cutover', ['--organization' => $this->organization->id, '--force' => true, '--commit' => true])
            ->expectsOutputToContain('have left the RCSA menu')
            ->assertSuccessful();

        $this->assertArrayNotHasKey('risk.rcsa.dashboard', $this->rcsaMenu());
    }
}
