<?php

namespace Tests\Feature\Quantification;

use App\Models\QuantificationScenario;
use App\Services\Quantification\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The scenario register's screens (migration Phase 5.2).
 *
 * TWO DEFECTS THIS EXISTS TO STOP COMING BACK.
 *
 * 1. THE REGISTER WAS BLIND ON FOUR SCREENS. `risk_category`,
 *    `distribution_type`, `mean`, `std_dev`, `frequency_per_year`, `min_loss`,
 *    `max_loss` and `last_run_at` are the CREATE FORM's field names, not
 *    columns on `quantification_scenarios`. The list, the show page, the
 *    simulate picker and the edit form all read them straight off the model,
 *    behind `?? 0` and `?? '-'`, so every scenario on every tenant listed as
 *    category "-", distribution "-", mean ₦0, std dev ₦0 and "-/year" — the
 *    parameters a Monte Carlo run draws to produce a capital add-on.
 *
 * 2. THE EDIT SCREEN COULD NOT EDIT. It rendered the create form, whose every
 *    field was `old(...)` with no fallback to the record, so it opened BLANK;
 *    and that form's action was hardcoded to the create route, so saving it
 *    filed a SECOND scenario instead of amending the first. `updateScenario()`,
 *    its PUT route and its validation were unreachable from the interface —
 *    nothing in the codebase referenced them.
 *
 * The fix for both is one thing: ScenarioService owns the mapping between the
 * form's vocabulary and the table's columns on the write side, and now owns its
 * exact inverse on the read side. The round-trip test below is what keeps the
 * two halves honest.
 */
class ScenarioRegisterTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['quantification.view', 'quantification.create'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->actor->givePermissionTo(['quantification.view', 'quantification.create']);
    }

    /**
     * An amount, to within the precision the database keeps.
     *
     * The mean survives exactly — it is stored as its own kobo column. The
     * standard deviation does NOT: it is not stored at all, it is recovered
     * from `severity_sigma`, which is `decimal:6`. Rounding sigma to six
     * decimal places moves the recovered figure by about one part in a
     * million — four naira in three million — and that is the honest state of
     * affairs rather than something to paper over. The tolerance below is
     * relative for that reason; anything larger means the mapping itself has
     * drifted, which is what this file is watching for.
     */
    private function assertMoney(int|float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message !== '' ? $message : 'The amount is present, not absent.');
        $this->assertEqualsWithDelta($expected, $actual, max(1.0, abs($expected) * 1e-5), $message);
    }

    /**
     * What the user typed is what the edit form gives back.
     *
     * This is the assertion that would have caught both defects: it goes in
     * through the create form and comes out through the edit form's props, so
     * a read that names a column the write path never fills cannot survive it.
     */
    #[Test]
    public function the_edit_form_gives_back_exactly_what_was_created(): void
    {
        $submission = [
            'name' => 'Branch cash-in-transit loss',
            'description' => 'Losses in transit between branches and the vault.',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'frequency_per_year' => 2.5,
            'mean' => 40_000_000,
            'std_dev' => 15_000_000,
            'min_loss' => 1_000_000,
            'max_loss' => 90_000_000,
        ];

        $this->actingAs($this->actor)
            ->post(route('risk.quantification.store-scenario'), $submission)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $scenario = QuantificationScenario::firstOrFail();

        $initial = $this->actingAs($this->actor)
            ->get(route('risk.quantification.edit-scenario', $scenario))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Scenarios/Edit'))
            ->inertiaProps('initial');

        foreach ($submission as $field => $value) {
            if (is_numeric($value)) {
                $this->assertMoney($value, $initial[$field], "{$field} did not survive the round trip.");
            } else {
                $this->assertSame($value, $initial[$field], "{$field} did not survive the round trip.");
            }
        }
    }

    /**
     * A scenario stored without a standard deviation gets NULL back, not the
     * figure DEFAULT_SIGMA would imply.
     *
     * Inventing mean * sqrt(exp(1) - 1) would put a number the preparer never
     * chose into a field they are about to save.
     */
    #[Test]
    public function an_unparameterised_scenario_offers_no_standard_deviation(): void
    {
        $this->actingAs($this->actor)->post(route('risk.quantification.store-scenario'), [
            'name' => 'Draft',
            'description' => 'No standard deviation supplied.',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'frequency_per_year' => 1,
            'mean' => 10_000_000,
        ])->assertSessionHasNoErrors();

        $values = app(ScenarioService::class)->toFormValues(QuantificationScenario::firstOrFail());

        $this->assertNull($values['std_dev']);
        $this->assertMoney(10_000_000, $values['mean']);
    }

    /** Editing amends the scenario. It does not file a second one. */
    #[Test]
    public function editing_amends_the_scenario_rather_than_creating_another(): void
    {
        $this->actingAs($this->actor)->post(route('risk.quantification.store-scenario'), [
            'name' => 'Original name',
            'description' => 'Original description.',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'frequency_per_year' => 2,
            'mean' => 20_000_000,
            'std_dev' => 5_000_000,
        ])->assertSessionHasNoErrors();

        $scenario = QuantificationScenario::firstOrFail();
        $reference = $scenario->scenario_reference;

        $this->actingAs($this->actor)
            ->put(route('risk.quantification.update-scenario', $scenario), [
                'name' => 'Amended name',
                'description' => 'Original description.',
                'risk_category' => 'Operational Risk',
                'distribution_type' => 'lognormal',
                'frequency_per_year' => 3,
                'mean' => 20_000_000,
                'std_dev' => 5_000_000,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, QuantificationScenario::count(), 'Editing must not file a duplicate.');

        $fresh = QuantificationScenario::firstOrFail();
        $this->assertSame('Amended name', $fresh->name);
        $this->assertSame($reference, $fresh->scenario_reference, 'The reference is not reissued on an edit.');
        $this->assertEqualsWithDelta(3.0, (float) $fresh->expected_annual_frequency, 0.001);
    }

    /**
     * The list and the simulate picker state each scenario's real calibration.
     *
     * Both used to read the form's vocabulary off the model and print ₦0.
     */
    #[Test]
    public function the_register_and_the_picker_state_the_real_calibration(): void
    {
        $this->actingAs($this->actor)->post(route('risk.quantification.store-scenario'), [
            'name' => 'Internal fraud',
            'description' => 'Unauthorised trading.',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'frequency_per_year' => 1.5,
            'mean' => 850_000_000,
            'std_dev' => 425_000_000,
        ])->assertSessionHasNoErrors();

        $listed = $this->actingAs($this->actor)
            ->get(route('risk.quantification.scenarios'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Scenarios/Index'))
            ->inertiaProps('scenarios')['data'][0];

        $picked = $this->actingAs($this->actor)
            ->get(route('risk.quantification.simulate'))
            ->assertOk()
            ->inertiaProps('scenarios')[0];

        foreach ([$listed, $picked] as $row) {
            $this->assertSame('Operational Risk', $row['risk_category']);
            $this->assertSame('lognormal', $row['distribution_type']);
            $this->assertMoney(850_000_000, $row['mean']);
            $this->assertMoney(425_000_000, $row['std_dev']);
            $this->assertEqualsWithDelta(1.5, $row['frequency_per_year'], 0.001);

            // 850m x 1.5 — the annual figure, not the per-event one.
            $this->assertMoney(1_275_000_000, $row['expected_annual_loss']);
        }
    }

    /** The show page's headline figures are the scenario's, not zeroes. */
    #[Test]
    public function the_show_page_reports_the_scenarios_own_parameters(): void
    {
        $this->actingAs($this->actor)->post(route('risk.quantification.store-scenario'), [
            'name' => 'System outage',
            'description' => 'Core banking unavailability.',
            'risk_category' => 'Operational Risk',
            'distribution_type' => 'lognormal',
            'frequency_per_year' => 4,
            'mean' => 12_000_000,
            'std_dev' => 3_000_000,
        ])->assertSessionHasNoErrors();

        $scenario = QuantificationScenario::firstOrFail();

        $props = $this->actingAs($this->actor)
            ->get(route('risk.quantification.show-scenario', $scenario))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Scenarios/Show'))
            ->inertiaProps();

        $this->assertMoney(12_000_000, $props['values']['mean']);
        $this->assertMoney(3_000_000, $props['values']['std_dev']);
        $this->assertEqualsWithDelta(4.0, $props['values']['frequency_per_year'], 0.001);

        // The curve is drawn from the same parameters the panel reports, which
        // is what the Blade page could not say: it plotted a correct log-normal
        // beside a panel claiming its mean was zero.
        $this->assertNotEmpty($props['distributionVisualization']['labels']);
        $this->assertSameSize(
            $props['distributionVisualization']['labels'],
            $props['distributionVisualization']['values'],
        );
    }
}
