<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaRegisterRisk;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

/**
 * The RCSA Universe index (plan §6.1) — what it renders and what it filters.
 */
class UniversePageTest extends UniverseTestCase
{
    #[Test]
    public function the_index_renders_with_the_props_the_page_reads(): void
    {
        $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        $this->actingAs($this->actor)
            ->get(route('rcsa.universe.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('RcsaUniverse/Index')
                ->has('risks.data', 1)
                ->has('options.businessUnits')
                ->has('options.processes')
                ->has('options.categories', 13)
                ->has('options.statuses', 3)
                ->where('can.create', true)
                ->where('can.publish', true)
            );
    }

    /**
     * Every field the React page reads must be in the payload.
     *
     * This is the check that catches the defect family this product keeps
     * finding: a list row built from guessed property names renders a dash for
     * every record on every tenant and nothing fails. The edit panel is the
     * sharp end of it — it edits THIS row object, so a payload carrying only
     * the display names would open every edit with an empty Business Unit and
     * saving it would blank the placement.
     */
    #[Test]
    public function a_list_row_carries_both_the_names_the_table_shows_and_the_ids_the_edit_panel_needs(): void
    {
        $risk = $this->makeRisk(['risk_no' => 'RETAIL-R1', 'sub_process_id' => $this->kyc->id]);
        $risk->controls()->create([
            'organization_id' => $this->organization->id,
            'description' => 'Dual review before activation.',
            'control_type' => 'detective',
            'frequency' => 'daily',
            'is_key' => true,
        ]);

        $this->actingAs($this->actor)
            ->get(route('rcsa.universe.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('risks.data.0', fn (AssertableJson $row) => $row
                    ->where('risk_no', 'RETAIL-R1')
                    // Names, for the table.
                    ->where('business_unit', 'Retail Banking')
                    ->where('process', 'Customer Onboarding')
                    ->where('sub_process', 'KYC Verification')
                    // Ids, for the edit panel.
                    ->where('business_unit_id', $this->retail->id)
                    ->where('process_id', $this->onboarding->id)
                    ->where('sub_process_id', $this->kyc->id)
                    ->where('controls_count', 1)
                    ->has('controls.0', fn (AssertableJson $control) => $control
                        ->where('description', 'Dual review before activation.')
                        ->where('control_type', 'detective')
                        ->where('frequency', 'daily')
                        ->where('is_key', true)
                        ->etc()
                    )
                    ->etc()
                )
            );
    }

    #[Test]
    public function the_filters_narrow_the_list(): void
    {
        $this->makeRisk(['risk_no' => 'RETAIL-R1', 'risk_category' => 'Operational']);
        $this->makeRisk([
            'risk_no' => 'TREAS-R1',
            'business_unit_id' => $this->treasury->id,
            'process_id' => null,
            'risk_category' => 'Market',
            'status' => RcsaRegisterRisk::PUBLISHED,
            'potential_risk' => 'Adverse rate movements erode the value of the trading book.',
        ]);

        $assertCount = function (array $query, int $expected) {
            $this->actingAs($this->actor)
                ->get(route('rcsa.universe.index', $query))
                ->assertInertia(fn (AssertableInertia $page) => $page->has('risks.data', $expected));
        };

        $assertCount([], 2);
        $assertCount(['business_unit' => $this->treasury->id], 1);
        $assertCount(['category' => 'Market'], 1);
        $assertCount(['status' => 'published'], 1);
        $assertCount(['search' => 'trading book'], 1);
        // The number, not only the wording — a champion chasing a row from a
        // board paper has the number.
        $assertCount(['search' => 'TREAS-R1'], 1);
        $assertCount(['search' => 'nothing matches this'], 0);
    }

    #[Test]
    public function another_tenants_risks_are_not_listed(): void
    {
        $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        \ThirdLine\Platform\Tenancy\TenantContext::bypass(function () {
            RcsaRegisterRisk::create([
                'organization_id' => $this->otherOrg->id,
                'business_unit_id' => $this->foreignUnit->id,
                'risk_no' => 'FOREIGN-R1',
                'potential_risk' => 'A risk belonging to an entirely different bank.',
                'risk_category' => 'Operational',
                'status' => RcsaRegisterRisk::PUBLISHED,
            ]);
        });

        $this->actingAs($this->actor)
            ->get(route('rcsa.universe.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('risks.data', 1)
                ->where('risks.data.0.risk_no', 'RETAIL-R1')
            );
    }

    #[Test]
    public function a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs($this->userWith([]))
            ->get(route('rcsa.universe.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_action_flags_follow_the_callers_permissions(): void
    {
        $this->actingAs($this->userWith(['rcsa_universe.view']))
            ->get(route('rcsa.universe.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.create', false)
                ->where('can.publish', false)
                ->where('can.delete', false)
                ->etc()
            );
    }
}
