<?php

namespace Tests\Feature\Quantification;

use App\Models\QuantificationSetting;
use App\Services\Quantification\IcaapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The quantification settings screen (migration Phase 5.2).
 *
 * THE DEFECT THIS EXISTS TO STOP COMING BACK. The screen offered ten editable
 * fields and stored three. `default_confidence`, `default_time_horizon`,
 * `seed`, `target_car`, `countercyclical_buffer` and the green/amber/red band
 * had no column anywhere: a preparer typed them, the screen said "Quantification
 * settings have been updated", and every one was discarded. Two of them were
 * `required` in the validator, so they had to be filled in on every save to be
 * thrown away.
 *
 * The rule these tests enforce is 4.6's, generalised: A TEST THAT POSTS A ROUTE
 * IS NOT A TEST OF THE FORM IN FRONT OF IT. The first test below reads the
 * field names off the RENDERED PAGE, submits exactly those, and then asserts
 * every one of them came back — so a field that saves nothing cannot be added
 * without turning it red.
 */
class QuantificationSettingsTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        foreach (['quantification.view', 'quantification.create'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }
    }

    /**
     * Every input the form renders is a field the save round-trips.
     */
    #[Test]
    public function every_field_the_form_offers_is_a_field_the_save_stores(): void
    {
        // The page is React now, so the fields it offers are the keys of the
        // `settings` prop its useForm is seeded from — the same check one step
        // earlier in the pipeline, and a stricter one: a field the page renders
        // without a prop would have nothing to submit.
        $offered = array_keys($this->settingsProps()['settings']);

        $this->assertSame(
            ['default_iterations', 'default_confidence_levels', 'default_horizon_years', 'cbn_minimum_car', 'cbn_conservation_buffer'],
            $offered,
            'The form offers exactly the five fields that have somewhere to be stored.',
        );

        sort($offered);

        $submission = [
            'default_iterations' => 50_000,
            'default_horizon_years' => 3,
            'default_confidence_levels' => [95, 99.9],
            'cbn_minimum_car' => 15.0,
            'cbn_conservation_buffer' => 1.0,
        ];

        $submitted = array_keys($submission);
        sort($submitted);

        $this->assertSame(
            $offered,
            $submitted,
            'Every offered field is submitted below; add the assertion when a field is added.',
        );

        $this->actingAs($this->actor)
            ->put(route('risk.quantification.update-settings'), $submission)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('risk.quantification.settings'));

        $stored = QuantificationSetting::where('organization_id', $this->organization->id)->firstOrFail();

        $this->assertSame(50_000, $stored->default_iterations);
        $this->assertSame(3, $stored->default_horizon_years);
        $this->assertSame([95, 99.9], $stored->default_confidence_levels);
        $this->assertSame(15.0, (float) $stored->cbn_minimum_car);
        $this->assertSame(1.0, (float) $stored->cbn_conservation_buffer);
    }

    /**
     * The reason the screen exists: the simulate form opens on what was saved.
     * Until Phase 5.2 it hardcoded 10,000 / 1 year / 95-99-99.5, so the one
     * setting that did persist reached nothing.
     */
    #[Test]
    public function the_simulate_form_opens_on_the_configured_defaults(): void
    {
        QuantificationSetting::create([
            'organization_id' => $this->organization->id,
            'default_iterations' => 50_000,
            'default_horizon_years' => 5,
            'default_confidence_levels' => [90, 99.9],
        ]);

        $data = $this->actingAs($this->actor)
            ->get(route('risk.quantification.simulate'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Simulate'))
            ->inertiaProps();

        $this->assertSame(50_000, $data['defaults']['iterations']);
        $this->assertSame(5, $data['defaults']['horizon_years']);
        $this->assertSame([90, 99.9], $data['defaults']['confidence_levels']);
    }

    /**
     * With nothing configured the screen shows the CBN figures from
     * config/quantification.php — the same ones IcaapService resolves to.
     *
     * It used to show 2.5 for the conservation buffer, the Basel III figure,
     * while resolveConservationBuffer() fell back to the CBN's 1.0. A settings
     * screen that disagrees with the resolver is worse than no settings screen.
     */
    #[Test]
    public function an_unconfigured_organisation_is_shown_the_figures_the_resolver_will_use(): void
    {
        $settings = $this->settingsProps()['settings'];
        $icaap = app(IcaapService::class);

        $this->assertEqualsWithDelta($icaap->resolveMinimumCar(null, $this->organization->id), $settings['cbn_minimum_car'], 0.001);
        $this->assertEqualsWithDelta($icaap->resolveConservationBuffer(null, $this->organization->id), $settings['cbn_conservation_buffer'], 0.001);
        $this->assertEqualsWithDelta(1.0, $settings['cbn_conservation_buffer'], 0.001, 'The CBN figure, not Basel III’s 2.5.');
    }

    /** @return array<string, mixed> */
    private function settingsProps(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.quantification.settings'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Quantification/Settings'))
            ->inertiaProps();
    }

    /** A confidence level the engine does not compute is refused, not stored. */
    #[Test]
    public function a_confidence_level_the_engine_cannot_compute_is_refused(): void
    {
        $this->actingAs($this->actor)
            ->put(route('risk.quantification.update-settings'), [
                'default_iterations' => 10_000,
                'default_horizon_years' => 1,
                'default_confidence_levels' => [97.3],
                'cbn_minimum_car' => 10.0,
                'cbn_conservation_buffer' => 1.0,
            ])
            ->assertSessionHasErrors('default_confidence_levels.0');

        $this->assertSame(0, QuantificationSetting::count());
    }
}
