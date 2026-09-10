<?php

namespace Tests\Feature\Quantification;

use App\Models\QuantificationScenario;
use App\Services\Quantification\ScenarioLibrary;
use App\Support\Quantification\Distributions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The operational-risk scenario library (migration Phase 5.2).
 *
 * Phase 5's acceptance criterion 4: every entry in
 * `config/quantification_library.php` carries a `source`, and a test asserts
 * it. This is that test, and the reason it exists is worth stating plainly.
 *
 * A library template's mean and standard deviation become a lognormal severity
 * distribution; MonteCarloService draws on it; the run produces an aggregate
 * VaR; that VaR becomes a Pillar 2B stress buffer in an ICAAP submission. A
 * parameter that entered that chain with no stated origin would reach a
 * regulator with none. The templates used to be a PHP literal inside a
 * 1,611-line controller, where nothing could assert anything about them.
 */
class QuantificationLibraryTest extends TestCase
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

    /* ------------------------------------------------------------------ */

    /** Criterion 4, stated as a test. */
    #[Test]
    public function every_library_entry_names_its_source(): void
    {
        $scenarios = config('quantification_library.scenarios');

        $this->assertNotEmpty($scenarios, 'The library must not be empty — the screen offers it.');

        foreach ($scenarios as $index => $entry) {
            $this->assertArrayHasKey('source', $entry, "Entry {$index} has no source.");
            $this->assertNotSame('', trim((string) $entry['source']), "Entry {$index} has an empty source.");
        }
    }

    /**
     * And every entry is complete enough to import, because a half-specified
     * template becomes a half-specified scenario in a capital calculation.
     */
    #[Test]
    public function every_library_entry_carries_the_parameters_an_import_needs(): void
    {
        foreach (config('quantification_library.scenarios') as $index => $entry) {
            foreach (['id', 'name', 'risk_category', 'distribution_type', 'description', 'mean', 'std_dev', 'frequency_per_year'] as $key) {
                $this->assertArrayHasKey($key, $entry, "Entry {$index} is missing {$key}.");
            }

            $this->assertGreaterThan(0, $entry['mean'], "Entry {$index} has a non-positive mean.");
            $this->assertGreaterThan(0, $entry['std_dev'], "Entry {$index} has a non-positive standard deviation.");
            $this->assertGreaterThan(0, $entry['frequency_per_year'], "Entry {$index} has a non-positive frequency.");
        }
    }

    #[Test]
    public function library_ids_are_unique(): void
    {
        $ids = array_column(config('quantification_library.scenarios'), 'id');

        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_library_screen_lists_every_template(): void
    {
        $templates = $this->actingAs($this->actor)
            ->get(route('risk.quantification.library'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Library'))
            ->inertiaProps('libraryScenarios');

        $this->assertCount(count(config('quantification_library.scenarios')), $templates);

        // Every card states where its parameters came from; a template whose
        // provenance cannot be named has no business feeding a capital model.
        foreach ($templates as $template) {
            $this->assertNotEmpty($template['source']);
            $this->assertNull($template['imported'], 'Nothing has been imported into this fixture organisation yet.');
        }
    }

    /**
     * Importing converts naira moments to kobo lognormal parameters, and
     * writes the template's provenance into the scenario's own description so
     * it travels with the record rather than living only in the config file.
     */
    #[Test]
    public function importing_a_template_records_where_its_parameters_came_from(): void
    {
        $template = app(ScenarioLibrary::class)->find('lib-1');

        $this->actingAs($this->actor)
            ->post(route('risk.quantification.library.import', 'lib-1'))
            ->assertRedirect();

        $scenario = QuantificationScenario::firstOrFail();

        $this->assertSame($template->name, $scenario->name);
        $this->assertStringContainsString("source: {$template->source}", $scenario->description);
        $this->assertMatchesRegularExpression('/^SCN-\d{4}-\d{3}$/', $scenario->scenario_reference);

        [$mu, $sigma, $meanKobo] = Distributions::lognormalFromMoments(
            (float) $template->mean,
            (float) $template->std_dev,
        );

        $this->assertEqualsWithDelta($mu, (float) $scenario->severity_mu, 0.000001);
        $this->assertEqualsWithDelta($sigma, (float) $scenario->severity_sigma, 0.000001);
        // Naira in the config, kobo in the column.
        $this->assertSame((float) $meanKobo, (float) $scenario->expected_loss_per_event_kobo);
        $this->assertSame($template->mean * 100.0, (float) $scenario->expected_loss_per_event_kobo);
    }

    #[Test]
    public function importing_the_same_template_twice_does_not_duplicate_it(): void
    {
        $this->actingAs($this->actor)->post(route('risk.quantification.library.import', 'lib-1'));
        $this->actingAs($this->actor)->post(route('risk.quantification.library.import', 'lib-1'));

        $this->assertSame(1, QuantificationScenario::count());
    }

    #[Test]
    public function an_unknown_template_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.quantification.library.import', 'lib-does-not-exist'))
            ->assertRedirect(route('risk.quantification.library'))
            ->assertSessionHas('error');

        $this->assertSame(0, QuantificationScenario::count());
    }

    /**
     * The OTHER route into the scenario register, broken the same way.
     *
     * `quantification_scenarios.scenario_type` is `string(50)` NOT NULL with no
     * default. Neither `storeScenario()` nor `importLibrary()` set it, and the
     * create form has no field for it, so EVERY attempt to create a scenario
     * through the interface — by either route — ended in a NOT NULL violation
     * and a 500. On the module whose scenarios feed the Monte Carlo runs that
     * feed the ICAAP capital add-on.
     */
    #[Test]
    public function a_scenario_can_be_created_through_the_form(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.quantification.store-scenario'), [
                'name' => 'Branch cash-in-transit loss',
                'description' => 'Losses in transit between branches and the vault.',
                'risk_category' => 'Operational Risk',
                'distribution_type' => 'lognormal',
                'frequency_per_year' => 2.5,
                'mean' => 40_000_000,
                'std_dev' => 15_000_000,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $scenario = QuantificationScenario::firstOrFail();

        $this->assertSame('Branch cash-in-transit loss', $scenario->name);
        $this->assertSame(QuantificationScenario::DEFAULT_TYPE, $scenario->scenario_type);
        $this->assertContains($scenario->scenario_type, QuantificationScenario::TYPES);
    }

    /**
     * The moment conversion itself, which is load-bearing: sigma from the
     * coefficient of variation, then mu from sigma. Getting mu wrong is the
     * defect migration 2026_08_19_120003 exists to correct.
     */
    #[Test]
    public function the_lognormal_conversion_derives_mu_from_sigma(): void
    {
        [$mu, $sigma, $meanKobo] = Distributions::lognormalFromMoments(850_000_000, 425_000_000);

        $this->assertSame(85_000_000_000.0, $meanKobo);
        $this->assertEqualsWithDelta(sqrt(log(1 + 0.5 ** 2)), $sigma, 1e-9, 'sigma = sqrt(ln(1 + (s/m)^2)).');
        $this->assertEqualsWithDelta(log($meanKobo) - ($sigma ** 2) / 2, $mu, 1e-9, 'mu = ln(m) − sigma^2/2.');

        // The mean of the resulting lognormal has to come back to the input.
        $this->assertEqualsWithDelta($meanKobo, exp($mu + ($sigma ** 2) / 2), 1.0);
    }

    /** Moments that cannot yield a sigma fall back to a stated placeholder. */
    #[Test]
    public function an_unparameterisable_scenario_gets_the_stated_default_sigma(): void
    {
        [, $sigma] = Distributions::lognormalFromMoments(0.0, 0.0);

        $this->assertSame(Distributions::DEFAULT_SIGMA, $sigma);
    }
}
