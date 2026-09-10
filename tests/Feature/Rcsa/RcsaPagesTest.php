<?php

namespace Tests\Feature\Rcsa;

use App\Support\Migration\Ported;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/** The four RCSA screens on Inertia (migration Phase 3.8). */
class RcsaPagesTest extends RcsaTestCase
{
    #[Test]
    public function every_ported_route_is_registered_as_ported(): void
    {
        foreach ([
            'risk.rcsa.dashboard',
            'risk.rcsa.worksheet',
            'risk.rcsa.controls',
            'risk.rcsa.matrix',
        ] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_blade_views_are_gone(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/rcsa'));
    }

    #[Test]
    public function the_four_screens_render(): void
    {
        foreach ([
            'risk.rcsa.dashboard' => 'Rcsa/Dashboard',
            'risk.rcsa.worksheet' => 'Rcsa/Worksheet',
            'risk.rcsa.controls' => 'Rcsa/Controls',
            'risk.rcsa.matrix' => 'Rcsa/Matrix',
        ] as $route => $component) {
            $this->actingAs($this->actor)
                ->get(route($route))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    #[Test]
    public function a_user_without_rcsa_view_is_refused(): void
    {
        $nobody = $this->userWith([], 'nobody@example.test');

        foreach (['risk.rcsa.dashboard', 'risk.rcsa.worksheet', 'risk.rcsa.controls', 'risk.rcsa.matrix'] as $route) {
            $this->actingAs($nobody)->get(route($route))->assertForbidden();
        }
    }

    /**
     * The worksheet now offers the register risks the Blade controller loaded
     * and the Blade view ignored, so `risks.*.risk_id` — which the validator
     * accepts and the service stores — is reachable from the screen at last.
     */
    #[Test]
    public function the_worksheet_offers_register_risks_and_the_tenants_own_categories(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-WS-1', 'title' => 'Unreviewed journals']);

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.worksheet'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Rcsa/Worksheet')
                ->where('canSubmit', true)
                ->where('assessableRisks.0.code', 'RK-WS-1')
                ->where('assessableRisks.0.title', 'Unreviewed journals')
                // The organisation's own taxonomy, not seven hardcoded English
                // names.
                ->where('categories.0.name', $this->category->name)
                ->where('businessUnits.0.name', 'Retail Banking')
                ->where('processes.0.name', 'Customer Onboarding'));
    }

    #[Test]
    public function a_reader_without_rcsa_submit_gets_a_read_only_worksheet(): void
    {
        $reader = $this->userWith(['rcsa.view'], 'reader@example.test');

        $this->actingAs($reader)
            ->get(route('risk.rcsa.worksheet'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSubmit', false));
    }

    /**
     * The controls screen reports the columns the Control model actually has.
     * The Blade table asked for seven it does not, and printed a dash for each
     * one on every row.
     */
    #[Test]
    public function the_controls_screen_reports_real_columns(): void
    {
        $control = $this->makeControl([
            'name' => 'Dual authorisation',
            'control_type' => 'preventive',
            'effectiveness_rating' => 'effective',
            'business_unit_id' => $this->unit->id,
            'owner_id' => $this->actor->id,
        ]);

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.controls'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('controls.data.0.name', 'Dual authorisation')
                ->where('controls.data.0.type', 'preventive')
                ->where('controls.data.0.effectiveness', 'effective')
                ->where('controls.data.0.businessUnit', 'Retail Banking')
                ->where('controls.data.0.owner', $this->actor->name)
                ->where('controls.data.0.linkedRisks', 0)
                ->where('summary.total', 1)
                ->where('summary.effective', 1));
    }

    #[Test]
    public function the_controls_screen_filters(): void
    {
        $this->makeControl(['control_code' => 'CTL-E', 'effectiveness_rating' => 'effective']);
        $this->makeControl(['control_code' => 'CTL-I', 'effectiveness_rating' => 'ineffective']);

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.controls', ['effectiveness' => 'ineffective']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('controls.data', 1)
                ->where('controls.data.0.code', 'CTL-I')
                // The KPI tiles stay organisation-wide, as they were.
                ->where('summary.total', 2));
    }

    /**
     * The matrix's business-unit filter is reachable now — the Blade page
     * rendered the select with no handler on it, so the controller's filter
     * branch could never run from the screen.
     */
    #[Test]
    public function the_matrix_filters_by_business_unit(): void
    {
        $mine = $this->makeRisk(['risk_code' => 'RK-M1', 'business_unit_id' => $this->unit->id]);
        $other = $this->makeRisk(['risk_code' => 'RK-M2', 'business_unit_id' => null]);

        $control = $this->makeControl(['control_code' => 'CTL-M', 'effectiveness_rating' => 'effective']);
        $this->attachControl($mine, $control);
        $this->attachControl($other, $control);

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.matrix'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('risks', 2));

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.matrix', ['business_unit_id' => $this->unit->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('risks', 1)
                ->where('risks.0.code', 'RK-M1'));
    }

    /**
     * The dashboard's top-risk table no longer claims a control effectiveness
     * and an action for risks nothing has assessed.
     */
    #[Test]
    public function a_risk_with_no_mapped_controls_reports_no_effectiveness(): void
    {
        $this->makeRisk(['risk_code' => 'RK-BARE', 'title' => 'Bare', 'residual_rating' => 'High', 'residual_score' => 12]);

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('topRisks.0.title', 'Bare')
                ->where('topRisks.0.controlEffectiveness', null)
                ->where('topRisks.0.controlCount', 0));
    }

    /** The weakest mapped control is what a risk's cover is worth. */
    #[Test]
    public function the_weakest_mapped_control_is_reported(): void
    {
        $risk = $this->makeRisk(['risk_code' => 'RK-MIX', 'title' => 'Mixed', 'residual_rating' => 'High', 'residual_score' => 12]);

        $this->attachControl($risk, $this->makeControl(['control_code' => 'CTL-1', 'effectiveness_rating' => 'effective']));
        $this->attachControl($risk, $this->makeControl(['control_code' => 'CTL-2', 'effectiveness_rating' => 'ineffective']));

        $this->actingAs($this->actor)
            ->get(route('risk.rcsa.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('topRisks.0.controlEffectiveness', 'ineffective')
                ->where('topRisks.0.controlCount', 2));
    }
}
