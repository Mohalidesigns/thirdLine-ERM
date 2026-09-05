<?php

namespace Tests\Feature\Characterisation;

use App\Models\IcaapAssessment;
use App\Models\QuantificationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The ICAAP screen's capital figures (migration Phase 5.2).
 *
 * Written against the RUNNING BLADE SCREEN before `IcaapService` existed, and
 * kept permanently. These are the numbers a bank's capital adequacy is reported
 * from and the ones a CBN submission is prepared against, so nothing here may
 * move by accident.
 *
 * WHAT THIS SCREEN ALREADY GETS RIGHT, and what these assertions therefore
 * defend. An audit in August 2026 found QuantificationController slicing one
 * ICAAP column by 0.3/0.25/0.25/0.2 and presenting the slices as four distinct
 * risk types, and shipping five stress scenarios whose CAR drops were hardcoded
 * independently of the bank's balance sheet. Both were removed. The rules that
 * replaced them are the ones under test:
 *
 *   - an absent input stays ABSENT. An unrecorded balance sheet is not a
 *     balance sheet of zeroes, so every ratio is null rather than 0.
 *   - Pillar 2A is reported exactly as stored, with no decomposition.
 *   - available capital is null until EVERY deduction is known, because a
 *     waterfall with a missing bar is not a smaller waterfall.
 *   - the preparer's own CAR is kept separate from the computed one and the
 *     two are reconciled, rather than one quietly winning.
 */
class IcaapCharacterisationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        Permission::findOrCreate('quantification.view');
        $this->actor->givePermissionTo('quantification.view');
    }

    /* ------------------------------------------------------------------ */

    /**
     * A complete balance sheet: NGN 260bn of qualifying capital against
     * NGN 2trn of RWA is a CAR of 13.00%.
     */
    #[Test]
    public function the_ratios_are_capital_over_rwa_as_percentages(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'cet1_capital_kobo' => 18_000_000_000_000,
            'tier1_capital_kobo' => 20_000_000_000_000,
            'tier2_capital_kobo' => 6_000_000_000_000,
        ]);

        $data = $this->icaapData();

        $this->assertSame(13.0, $data['carComputed']);
        $this->assertSame(9.0, $data['cet1Ratio'], 'CET1 180bn / RWA 2trn.');
        $this->assertSame(10.0, $data['tier1Ratio']);

        // Kobo in, naira out.
        $this->assertSame(260_000_000_000.0, $data['totalCapital']);
        $this->assertSame(2_000_000_000_000.0, $data['totalRwa']);
    }

    /** An unrecorded balance sheet is not a balance sheet of zeroes. */
    #[Test]
    public function absent_inputs_stay_absent(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => null,
            'total_rwa_kobo' => null,
            'cet1_capital_kobo' => null,
            'tier1_capital_kobo' => null,
            'tier2_capital_kobo' => null,
        ]);

        $data = $this->icaapData();

        foreach (['carComputed', 'cet1Ratio', 'tier1Ratio', 'totalCapital', 'totalRwa', 'pillar1Requirement'] as $key) {
            $this->assertNull($data[$key], "{$key} must be null, not zero, when nothing was recorded.");
        }
    }

    /** No assessment at all is a legitimate state, and says so. */
    #[Test]
    public function an_organisation_with_no_assessment_reports_no_assessment(): void
    {
        $data = $this->icaapData();

        $this->assertFalse($data['hasAssessment']);
        $this->assertNull($data['carComputed']);
        $this->assertNull($data['availableCapital']);
    }

    /**
     * Pillar 1 is the regulatory minimum charge against RWA, computed — not
     * borrowed from the Pillar 2A columns as it once was.
     */
    #[Test]
    public function pillar_one_is_the_minimum_car_applied_to_rwa(): void
    {
        $this->assessment(['total_rwa_kobo' => 200_000_000_000_000, 'cbn_minimum_car' => 10.0]);

        $this->assertSame(200_000_000_000.0, $this->icaapData()['pillar1Requirement'], '10% of NGN 2trn.');
    }

    /** Pillar 2A is reported exactly as stored. Nothing is decomposed. */
    #[Test]
    public function pillar_two_a_is_the_sum_of_the_stored_components(): void
    {
        $this->assessment([
            'pillar2a_credit_kobo' => 4_000_000_000_000,
            'pillar2a_market_kobo' => 1_000_000_000_000,
            'pillar2a_operational_kobo' => 2_000_000_000_000,
            'pillar2a_other_kobo' => null,
        ]);

        $data = $this->icaapData();

        $this->assertSame(40_000_000_000.0, $data['pillar2aCredit']);
        $this->assertSame(10_000_000_000.0, $data['pillar2aMarket']);
        $this->assertSame(20_000_000_000.0, $data['pillar2aOperational']);
        $this->assertNull($data['pillar2aOther'], 'An unrecorded component is absent, not zero.');
        $this->assertSame(70_000_000_000.0, $data['totalPillar2a'], 'The recorded three, summed.');
    }

    /**
     * A waterfall with a missing bar is not a smaller waterfall.
     */
    #[Test]
    public function available_capital_is_null_until_every_deduction_is_known(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'pillar2a_credit_kobo' => 4_000_000_000_000,
            'pillar2b_stress_buffer_kobo' => null,
        ]);

        $data = $this->icaapData();

        $this->assertNull($data['availableCapital']);
        $this->assertContains('Pillar 2B Stress Buffer', $data['waterfallMissing']);

        // The chart still gets its labels, with a null where the bar is unknown
        // so it leaves a gap rather than drawing a zero.
        $this->assertSame(
            ['Total Qualifying Capital', 'Pillar 1 Requirement', 'Pillar 2A Add-on', 'Pillar 2B Stress Buffer', 'Conservation Buffer', 'Available Capital'],
            $data['waterfallData']['labels'],
        );
        $this->assertNull($data['waterfallData']['values'][3]);
    }

    #[Test]
    public function a_complete_waterfall_sums_to_available_capital(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,   // 260bn
            'total_rwa_kobo' => 200_000_000_000_000,                 // 2trn
            'cbn_minimum_car' => 10.0,                               // P1 = 200bn
            'conservation_buffer' => 1.0,                            // CCB = 20bn
            'pillar2a_credit_kobo' => 1_000_000_000_000,             // 10bn
            'pillar2a_market_kobo' => 0,
            'pillar2a_operational_kobo' => 0,
            'pillar2a_other_kobo' => 0,
            'pillar2b_stress_buffer_kobo' => 500_000_000_000,        // 5bn
        ]);

        $data = $this->icaapData();

        $this->assertSame([], $data['waterfallMissing']);
        // 260 − 200 − 10 − 5 − 20 = 25bn
        $this->assertSame(25_000_000_000.0, $data['availableCapital']);
    }

    /**
     * The preparer's CAR and the computed one are reconciled, not merged.
     *
     * `icaap_assessments.cbn_minimum_car` is NOT NULL with a database default
     * of 10.0, so an assessment saved without an explicit minimum resolves to
     * 10.0 and an organisation-wide 15.0 is never reached. That precedence is
     * right — an assessment is reconciled against the minimum it was prepared
     * under — but it is a trap, so the screen surfaces both figures.
     */
    #[Test]
    public function the_reported_car_is_reconciled_against_the_computed_one(): void
    {
        QuantificationSetting::create([
            'organization_id' => $this->organization->id,
            'cbn_minimum_car' => 15.0,
        ]);

        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'car_actual' => 12.40,
            'cbn_minimum_car' => 10.0,
        ]);

        $data = $this->icaapData();

        $this->assertSame(13.0, $data['carComputed']);
        $this->assertSame(12.4, $data['carReported']);
        $this->assertSame(0.6, $data['carVariance']);
        $this->assertTrue($data['carVarianceMaterial'], 'Above the 0.05 reconciliation tolerance.');

        // Both minimums are on the screen, because they disagree.
        $this->assertSame(10.0, $data['minimumCar'], 'The assessment was prepared under 10.');
        $this->assertSame(15.0, $data['organizationMinimumCar'], 'The organisation now stands at 15.');
    }

    #[Test]
    public function a_variance_inside_tolerance_is_not_material(): void
    {
        $this->assessment([
            'total_qualifying_capital_kobo' => 26_000_000_000_000,
            'total_rwa_kobo' => 200_000_000_000_000,
            'car_actual' => 13.00,
        ]);

        $data = $this->icaapData();

        $this->assertSame(0.0, $data['carVariance']);
        $this->assertFalse($data['carVarianceMaterial']);
    }

    /** Stress rows come only from a bound run, and only from real arithmetic. */
    #[Test]
    public function no_bound_stress_run_means_no_stress_rows(): void
    {
        $this->assessment(['stress_simulation_id' => null]);

        $data = $this->icaapData();

        $this->assertNull($data['stressSimulation']);
        $this->assertCount(0, $data['stressRows']);
        $this->assertFalse($data['stressAggregateMissing'], 'Nothing bound is not the same as a bound run with no aggregates.');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function icaapData(): array
    {
        return $this->actingAs($this->actor)
            ->get(route('risk.quantification.icaap'))
            ->assertOk()
            ->original->getData();
    }

    private function assessment(array $attributes = []): IcaapAssessment
    {
        return IcaapAssessment::create(array_merge([
            'organization_id' => $this->organization->id,
            'period' => '2026-Q2',
            'status' => 'draft',
            'prepared_by' => $this->actor->id,
        ], $attributes));
    }
}
