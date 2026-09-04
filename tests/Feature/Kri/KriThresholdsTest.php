<?php

namespace Tests\Feature\Kri;

use App\Models\KeyRiskIndicator;
use App\Models\MeasureThreshold;
use PHPUnit\Framework\Attributes\Test;

/**
 * The bulk threshold editor (migration Phase 4.1).
 *
 * REGRESSION TESTS FOR A SCREEN THAT SAVED NOTHING. The Blade form posted
 * `kris[{id}][green_threshold]`; KriController::updateThresholds() read
 * `$request->input('thresholds', [])` and expected `green_min` / `green_max` /
 * `amber_min` / … . The shapes never matched, so the loop ran zero times and
 * the action redirected with "Thresholds updated." Every bulk edit of a bank's
 * risk tolerances was discarded behind a success message. Nothing had a test.
 */
class KriThresholdsTest extends KriTestCase
{
    #[Test]
    public function saving_thresholds_actually_moves_the_columns(): void
    {
        $kri = $this->makeKri([
            'threshold_direction' => 'higher_worse',
            'green_threshold_max' => 10,
            'red_threshold_min' => 50,
        ]);

        $this->actingAs($this->actor)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [['id' => $kri->id, 'green_threshold' => 3, 'red_threshold' => 7]],
            ])
            ->assertRedirect(route('risk.kri.thresholds'))
            ->assertSessionHas('success');

        $kri->refresh();

        // Higher-is-worse: green is a ceiling, red is a floor, amber spans them.
        $this->assertSame((float) 3., $this->number($kri->green_threshold_max));
        $this->assertSame((float) 3., $this->number($kri->amber_threshold_min));
        $this->assertSame((float) 7., $this->number($kri->amber_threshold_max));
        $this->assertSame((float) 7., $this->number($kri->red_threshold_min));
    }

    /** A lower-is-worse indicator fans the same two numbers out the other way. */
    #[Test]
    public function direction_decides_which_columns_the_boundaries_land_in(): void
    {
        $kri = $this->makeKri(['threshold_direction' => 'lower_worse']);

        $this->actingAs($this->actor)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [['id' => $kri->id, 'green_threshold' => 90, 'red_threshold' => 60]],
            ])
            ->assertRedirect();

        $kri->refresh();

        $this->assertSame((float) 90., $this->number($kri->green_threshold_min));
        $this->assertSame((float) 90., $this->number($kri->amber_threshold_max));
        $this->assertSame((float) 60., $this->number($kri->amber_threshold_min));
        $this->assertSame((float) 60., $this->number($kri->red_threshold_max));
    }

    /**
     * The mirror columns are not the source of truth: the measure engine
     * decides what a breach is, and it reads `measure_thresholds`. The method
     * this replaces never called syncDefinition(), so even a payload that
     * matched would have left the engine on the old bands.
     */
    #[Test]
    public function saving_thresholds_reaches_the_measure_engine(): void
    {
        $kri = $this->makeKri();

        $this->actingAs($this->actor)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [['id' => $kri->id, 'green_threshold' => 4, 'red_threshold' => 9]],
            ])
            ->assertRedirect();

        $this->assertTrue(
            MeasureThreshold::withoutGlobalScopes()->whereNull('effective_to')->exists(),
            'an open effective-dated band set exists for the indicator',
        );
    }

    #[Test]
    public function several_indicators_are_saved_in_one_post(): void
    {
        $first = $this->makeKri();
        $second = $this->makeKri();

        $this->actingAs($this->actor)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [
                    ['id' => $first->id, 'green_threshold' => 1, 'red_threshold' => 2],
                    ['id' => $second->id, 'green_threshold' => 3, 'red_threshold' => 4],
                ],
            ])
            ->assertRedirect();

        $this->assertSame((float) 1., $this->number($first->fresh()->green_threshold_max));
        $this->assertSame((float) 3., $this->number($second->fresh()->green_threshold_max));
    }

    /** Clearing a boundary is a real edit, not a skipped one. */
    #[Test]
    public function a_boundary_can_be_cleared(): void
    {
        $kri = $this->makeKri(['green_threshold_max' => 10, 'red_threshold_min' => 50]);

        $this->actingAs($this->actor)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [['id' => $kri->id, 'green_threshold' => '', 'red_threshold' => 20]],
            ])
            ->assertRedirect();

        $this->assertNull($kri->fresh()->green_threshold_max);
    }

    #[Test]
    public function another_tenants_indicator_is_rejected_not_skipped(): void
    {
        $foreign = null;

        \App\Support\Tenancy\TenantContext::bypass(function () use (&$foreign) {
            $foreign = KeyRiskIndicator::create([
                'organization_id' => $this->otherOrg->id,
                'risk_id' => $this->foreignRisk->id,
                'kri_code' => 'KRI-FOREIGN',
                'name' => 'Theirs',
                'metric_formula' => '',
                'data_source' => '',
                'measurement_frequency' => 'monthly',
                'unit_of_measure' => '%',
                'threshold_direction' => 'higher_worse',
                'owner_id' => $this->otherActor->id,
                'current_status' => 'green',
                'is_active' => true,
                'created_by' => $this->otherActor->id,
            ]);
        }, 'test fixture');

        $this->actingAs($this->actor)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [['id' => $foreign->id, 'green_threshold' => 1, 'red_threshold' => 2]],
            ])
            ->assertSessionHasErrors('kris.0.id');

        $this->assertNull($foreign->fresh()->green_threshold_max);
    }

    #[Test]
    public function a_user_without_kri_edit_cannot_save_thresholds(): void
    {
        $reader = $this->userWith(['kri.view'], 'reader@example.test');

        $this->actingAs($reader)
            ->put(route('risk.kri.thresholds.update'), [
                'kris' => [['id' => $this->kri->id, 'green_threshold' => 1, 'red_threshold' => 2]],
            ])
            ->assertForbidden();
    }

    /**
     * These columns are plain decimals with no cast, so the driver decides
     * whether "3" comes back as '3' or '3.0000'. Compare the number.
     */
    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
