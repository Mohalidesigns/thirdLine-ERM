<?php

namespace Tests\Feature\Assessments;

use App\Models\BusinessUnit;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Support\RiskCalculationSettings;
use App\Support\Scoring\ScoringProfileTemplates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Phase 3.3, Decision 5b — the live preview on the assessment form.
 *
 * The Alpine `assessmentChain()` component this replaces was a second
 * implementation of impact aggregation, effectiveness weighting and the axis
 * split, written in JavaScript and free to drift from the server's. It also
 * could not evaluate a tenant's configured residual formula — it said so in a
 * comment and silently fell back to the platform default, so an organisation
 * on a custom formula watched one number while it typed and got a different
 * one on save.
 *
 * These tests are the contract that replaced it: for the same chain, what the
 * preview endpoint returns is what saving stores. Not "close to", not "for the
 * default profile" — the same numbers, including for a tenant whose residual
 * formula is their own.
 */
class AssessmentPreviewTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();

        foreach (['assessment.view', 'assessment.create', 'risk.view'] as $permission) {
            Permission::findOrCreate($permission);
            $this->actor->givePermissionTo($permission);
        }

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'RETAIL',
            'name' => 'Retail Banking',
        ]);
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The contract: preview == save */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{0: string, 1: string, 2: array<string, int>}> */
    public static function scenarios(): array
    {
        return [
            // Preventive controls push the reduction onto the likelihood axis.
            'preventive controls, default profile' => [
                'default', 'preventive', ['likelihood' => 4, 'financial' => 5, 'operational' => 3],
            ],
            // Detective controls push it onto the impact axis instead, which is
            // the whole reason controls.control_type is collected.
            'detective controls, default profile' => [
                'default', 'detective', ['likelihood' => 5, 'financial' => 5, 'operational' => 4],
            ],
            // The case the JavaScript mirror could not do at all.
            'custom residual formula' => [
                'custom', 'preventive', ['likelihood' => 4, 'financial' => 5, 'operational' => 2],
            ],
        ];
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function the_preview_is_the_number_that_gets_stored(string $profileKind, string $controlType, array $scores): void
    {
        if ($profileKind === 'custom') {
            $this->customFormulaProfile();
        }

        $risk = $this->assessableRisk($controlType);
        $chain = $this->chainInput($risk, $scores);

        $preview = $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), $chain)
            ->assertOk()
            ->json();

        // The preview has to be a real derivation, not an empty envelope, or
        // this test would pass on two matching nulls.
        $this->assertNotNull($preview['residual'], 'the preview derived no residual risk');
        $this->assertGreaterThan(0, $preview['inherent']['score']);

        $this->actingAs($this->actor)
            ->post(route('risk.assessments.store'), $chain + [
                'assessment_type' => 'periodic',
                'assessment_date' => '2026-06-30',
                'rationale' => 'Quarterly reassessment.',
                'action' => 'draft',
            ])
            ->assertRedirect();

        $saved = RiskAssessment::where('risk_id', $risk->id)->latest('id')->firstOrFail();

        $this->assertSame($preview['inherent']['score'], (int) $saved->overall_score, 'inherent score');
        $this->assertSame($preview['inherent']['rating'], $saved->overall_rating, 'inherent rating');
        $this->assertSame($preview['residual']['likelihood'], (int) $saved->residual_likelihood, 'residual likelihood');
        $this->assertSame($preview['residual']['impact'], (int) $saved->residual_impact, 'residual impact');
        $this->assertSame($preview['residual']['score'], (int) $saved->residual_score, 'residual score');
        $this->assertSame($preview['residual']['rating'], $saved->residual_rating, 'residual rating');
    }

    #[Test]
    public function a_custom_residual_formula_moves_the_preview_off_the_platform_default(): void
    {
        // Guards the test above against passing vacuously: if the custom
        // formula produced the same answer as the default, "the preview
        // honours the tenant's formula" would be untestable through it.
        $risk = $this->assessableRisk('preventive');
        $chain = $this->chainInput($risk, ['likelihood' => 4, 'financial' => 5, 'operational' => 2]);

        $onDefault = $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), $chain)->assertOk()->json('residual');

        $this->customFormulaProfile();

        $onCustom = $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), $chain)->assertOk()->json('residual');

        $this->assertNotSame(
            $onDefault['score'],
            $onCustom['score'],
            'the tenant formula produced the platform default score, so this fixture proves nothing',
        );
    }

    #[Test]
    public function the_preview_reports_an_override_only_when_it_differs_from_the_derivation(): void
    {
        $risk = $this->assessableRisk('preventive');
        $chain = $this->chainInput($risk, ['likelihood' => 4, 'financial' => 5, 'operational' => 3]);

        $derived = $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), $chain)->assertOk()->json();

        // Posting the derived pair back — which is exactly what a form
        // pre-filled from the preview does — is not an override.
        $echoed = $this->actingAs($this->actor)->postJson(route('risk.assessments.preview'), $chain + [
            'residual_likelihood' => $derived['residual']['likelihood'],
            'residual_impact' => $derived['residual']['impact'],
        ])->assertOk()->json();

        $this->assertFalse($echoed['isOverride']);
        $this->assertSame($derived['residual']['score'], $echoed['residual']['score']);

        // Changing it is.
        $changed = $this->actingAs($this->actor)->postJson(route('risk.assessments.preview'), $chain + [
            'residual_likelihood' => 1,
            'residual_impact' => 1,
        ])->assertOk()->json();

        $this->assertTrue($changed['isOverride']);
        $this->assertSame(1, $changed['residual']['score']);
    }

    #[Test]
    public function the_preview_ignores_a_control_that_is_not_mapped_to_the_risk(): void
    {
        $risk = $this->assessableRisk('preventive');
        $strangerControl = $this->makeControl(['effectiveness_rating' => 'not_operating']);

        $chain = $this->chainInput($risk, ['likelihood' => 4, 'financial' => 5]);
        $chain['controls'][$strangerControl->id] = [
            'design_effectiveness' => 'not_operating',
            'operating_effectiveness' => 'not_operating',
        ];

        $preview = $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), $chain)->assertOk()->json();

        // Only the risk's own two mapped controls count; a forged id in the
        // body must not drag the aggregate down (or up).
        $this->assertCount(2, $preview['controls']);
        $this->assertEqualsCanonicalizing(
            $risk->controls()->pluck('controls.id')->all(),
            collect($preview['controls'])->pluck('id')->all(),
        );
    }

    #[Test]
    public function the_preview_refuses_a_risk_from_another_organisation(): void
    {
        $foreign = TenantContext::bypass(function () {
            $other = \App\Models\Organization::create([
                'name' => 'Other Bank PLC',
                'short_name' => 'OTHB',
                'institution_type' => 'commercial_bank',
                'sector' => 'banking',
                'is_active' => true,
            ]);

            $category = \App\Models\RiskCategory::create([
                'organization_id' => $other->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);

            return Risk::create([
                'organization_id' => $other->id,
                'category_id' => $category->id,
                'risk_code' => 'RK-FOREIGN',
                'title' => 'Theirs',
                'description' => 'Another bank.',
                'status' => 'active',
            ]);
        }, 'test fixture');

        $this->actingAs($this->actor)
            ->postJson(route('risk.assessments.preview'), ['risk_id' => $foreign->id, 'likelihood' => 3])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function assessableRisk(string $controlType): Risk
    {
        $risk = $this->makeRisk(['business_unit_id' => $this->unit->id, 'risk_owner_id' => $this->actor->id]);

        $this->attachControl(
            $risk,
            $this->makeControl(['control_type' => $controlType, 'effectiveness_rating' => 'effective']),
            weight: 2.0,
            isKey: true,
        );
        $this->attachControl(
            $risk,
            $this->makeControl(['control_type' => $controlType, 'effectiveness_rating' => 'partially_effective']),
            weight: 1.0,
        );

        return $risk;
    }

    /**
     * @param  array<string, int>  $scores
     * @return array<string, mixed>
     */
    private function chainInput(Risk $risk, array $scores): array
    {
        $controls = [];

        foreach ($risk->controls()->get() as $index => $control) {
            $controls[$control->id] = [
                'design_effectiveness' => $index === 0 ? 'effective' : 'partially_effective',
                'operating_effectiveness' => $index === 0 ? 'mostly_effective' : 'partially_effective',
            ];
        }

        $impacts = collect($scores)->except('likelihood')->all();

        return [
            'risk_id' => $risk->id,
            'likelihood' => $scores['likelihood'],
            // The preview takes the dimensions nested; the store path takes
            // them as `impact_<dimension>` columns. Both are sent, so one
            // payload drives both endpoints and the comparison is honest.
            'impacts' => $impacts,
            ...collect($impacts)->mapWithKeys(fn ($value, $dimension) => ["impact_{$dimension}" => $value])->all(),
            'controls' => $controls,
        ];
    }

    private function customFormulaProfile(): ScoringProfile
    {
        $profile = ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'house-formula',
            'is_system' => false,
            // Half the credit for controls the platform default would give:
            // this bank does not believe a control set can take a risk below
            // half its inherent score.
            'residual_formula' => 'inherent * (1 - effectiveness / 200)',
        ]));

        ScoringProfile::flushResolutionCache();

        return $profile;
    }
}
