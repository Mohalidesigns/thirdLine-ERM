<?php

namespace Tests\Feature\Kri;

use App\Models\KeyRiskIndicator;
use App\Support\Migration\Ported;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/** The five ported KRI screens (migration Phase 4.1). */
class KriPagesTest extends KriTestCase
{
    #[Test]
    public function every_ported_route_is_registered_as_ported(): void
    {
        foreach ([
            'risk.kri.dashboard',
            'risk.kri.create',
            'risk.kri.show',
            'risk.kri.edit',
            'risk.kri.thresholds',
        ] as $name) {
            $this->assertTrue(Ported::isRoute($name), $name);
        }
    }

    #[Test]
    public function the_blade_views_are_gone(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/kri'));
    }

    #[Test]
    public function the_screens_render(): void
    {
        foreach ([
            ['risk.kri.dashboard', [], 'Kri/Dashboard'],
            ['risk.kri.create', [], 'Kri/Create'],
            ['risk.kri.thresholds', [], 'Kri/Thresholds'],
            ['risk.kri.show', [$this->kri], 'Kri/Show'],
            ['risk.kri.edit', [$this->kri], 'Kri/Edit'],
        ] as [$name, $params, $component]) {
            $this->actingAs($this->actor)
                ->get(route($name, $params))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    #[Test]
    public function a_user_without_kri_view_is_refused(): void
    {
        $nobody = $this->userWith([], 'nobody@example.test');

        $this->actingAs($nobody)->get(route('risk.kri.dashboard'))->assertForbidden();
        $this->actingAs($nobody)->get(route('risk.kri.thresholds'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Create and edit */
    /* ------------------------------------------------------------------ */

    /**
     * The two boundaries are fanned out across the six band columns according
     * to the indicator's direction.
     */
    #[Test]
    public function creating_a_kri_maps_two_boundaries_onto_the_band_columns(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.kri.store'), $this->validKri())
            ->assertRedirect();

        $kri = KeyRiskIndicator::where('kri_name', 'Failed settlement rate')->firstOrFail();

        $this->assertSame(2.0, (float) $kri->green_threshold_max);
        $this->assertSame(2.0, (float) $kri->amber_threshold_min);
        $this->assertSame(5.0, (float) $kri->amber_threshold_max);
        $this->assertSame(5.0, (float) $kri->red_threshold_min);

        // The original NOT NULL columns are kept in step with the alignment
        // columns, as they were.
        $this->assertSame('Failed settlement rate', $kri->name);
        $this->assertSame('%', $kri->unit_of_measure);
        $this->assertSame($this->actor->id, $kri->owner_id);
        $this->assertSame('higher_worse', $kri->threshold_direction);
        $this->assertNotNull($kri->kri_code);
    }

    #[Test]
    public function a_lower_is_worse_kri_maps_the_other_way(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.kri.store'), $this->validKri([
                'direction' => 'lower_is_worse',
                'green_threshold' => 95,
                'red_threshold' => 80,
            ]))
            ->assertRedirect();

        $kri = KeyRiskIndicator::where('kri_name', 'Failed settlement rate')->firstOrFail();

        $this->assertSame(95.0, (float) $kri->green_threshold_min);
        $this->assertSame(95.0, (float) $kri->amber_threshold_max);
        $this->assertSame(80.0, (float) $kri->amber_threshold_min);
        $this->assertSame(80.0, (float) $kri->red_threshold_max);
        $this->assertSame('lower_worse', $kri->threshold_direction);
    }

    #[Test]
    public function creating_rejects_another_tenants_risk_and_owner(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.kri.store'), $this->validKri([
                'risk_id' => $this->foreignRisk->id,
                'kri_owner_id' => $this->otherActor->id,
            ]))
            ->assertSessionHasErrors(['risk_id', 'kri_owner_id']);
    }

    #[Test]
    public function no_form_request_uses_the_untenanted_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Kri/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (["'exists:", '"exists:'] as $quoted) {
                $this->assertStringNotContainsString($quoted, $source, basename($file));
            }
        }
    }

    #[Test]
    public function the_edit_page_hands_back_the_two_boundaries_not_three(): void
    {
        $kri = $this->makeKri(['green_threshold_max' => 12, 'red_threshold_min' => 40]);

        $this->actingAs($this->actor)
            ->get(route('risk.kri.edit', $kri))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/Edit')
                // JSON writes a whole float without its fraction, so what
                // comes back through assertInertia is an int.
                ->where('kri.green_threshold', 12)
                ->where('kri.red_threshold', 40)
                ->where('kri.direction', 'higher_is_worse')
                ->missing('kri.amber_threshold'));
    }

    #[Test]
    public function updating_a_kri_rewrites_the_bands(): void
    {
        $this->actingAs($this->actor)
            ->put(route('risk.kri.update', $this->kri), $this->validKri([
                'kri_name' => 'Renamed indicator',
                'green_threshold' => 7,
                'red_threshold' => 9,
            ]))
            ->assertRedirect(route('risk.kri.show', $this->kri));

        $this->kri->refresh();

        $this->assertSame('Renamed indicator', $this->kri->name);
        $this->assertSame(7.0, (float) $this->kri->green_threshold_max);
        $this->assertSame(9.0, (float) $this->kri->red_threshold_min);
    }

    #[Test]
    public function deleting_a_kri_removes_it(): void
    {
        $kri = $this->makeKri();

        $this->actingAs($this->actor)
            ->delete(route('risk.kri.destroy', $kri))
            ->assertRedirect(route('risk.kri.index'));

        $this->assertSoftDeleted('key_risk_indicators', ['id' => $kri->id]);
    }

    /* ------------------------------------------------------------------ */
    /*  The legacy table is no longer read */
    /* ------------------------------------------------------------------ */

    /**
     * Phase 4's acceptance criterion 3: no controller reads
     * `kri_measurements`. Since WP-04 a reading lives in `measure_values` and
     * the KRI's own columns are a mirror; the Blade controller still counted
     * the legacy table in two places.
     */
    #[Test]
    public function no_controller_reads_the_legacy_measurement_table(): void
    {
        foreach (glob(app_path('Http/Controllers/**/*.php')) as $file) {
            $this->assertStringNotContainsString('KriMeasurement::', file_get_contents($file), basename($file));
        }
    }

    #[Test]
    public function the_show_page_reads_the_measure_engine(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.kri.show', $this->kri))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kri/Show')
                ->where('kri.code', $this->kri->kri_code)
                // Twelve periods of window, whether or not anything was read.
                ->has('history.points', 12)
                ->has('readings.data')
                ->has('breaches'));
    }
}
