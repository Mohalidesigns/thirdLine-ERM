<?php

namespace Tests\Feature;

use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskAuditTrail;
use App\Models\ScoringProfile;
use App\Services\AuditTrailService;
use App\Services\ControlEffectivenessService;
use App\Services\RiskAppetiteService;
use App\Services\RiskScoringService;
use App\Support\RiskCalculationSettings;
use App\Support\Scoring\ScoringProfileTemplates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-01 TASK 2 acceptance — the services that read columns which do not
 * exist, and the calculations that had more than one implementation.
 */
class BrokenServiceFixesTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The audit trail actually returns rows */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_risks_audit_trail_returns_the_rows_the_service_recorded(): void
    {
        // The headline bug: AuditTrailService wrote entity_type 'Risk' while
        // Risk::auditTrail() filtered on 'risk', so this relationship was
        // empty no matter how many changes had been recorded.
        $risk = $this->makeRisk(['title' => 'Original title']);

        $original = $risk->getAttributes();
        $risk->update(['title' => 'Revised title']);
        AuditTrailService::recordChanges($risk, $original);

        $this->assertCount(1, $risk->fresh()->auditTrail);
        $this->assertSame('title', $risk->fresh()->auditTrail->first()->field_changed);
        $this->assertSame('Revised title', $risk->fresh()->auditTrail->first()->new_value);
    }

    #[Test]
    public function the_audit_trail_alias_relationship_returns_the_same_rows(): void
    {
        $risk = $this->makeRisk();
        AuditTrailService::record($risk, 'create');

        $this->assertCount(1, $risk->fresh()->auditTrails);
    }

    #[Test]
    public function the_recorded_entity_type_is_the_morph_alias(): void
    {
        $risk = $this->makeRisk();
        AuditTrailService::record($risk, 'create');

        $this->assertSame('risk', RiskAuditTrail::latest('id')->first()->entity_type);
    }

    #[Test]
    public function an_audit_row_resolves_back_to_its_entity(): void
    {
        $risk = $this->makeRisk(['title' => 'Auditable risk']);
        AuditTrailService::record($risk, 'create');

        $auditable = RiskAuditTrail::latest('id')->first()->auditable;

        $this->assertInstanceOf(Risk::class, $auditable);
        $this->assertSame('Auditable risk', $auditable->title);
    }

    #[Test]
    public function a_non_risk_entity_records_its_own_alias(): void
    {
        // Guards against the morph alias being hard-coded to 'risk' anywhere.
        $control = $this->makeControl();
        AuditTrailService::record($control, 'create');

        $this->assertSame('control', RiskAuditTrail::latest('id')->first()->entity_type);
    }

    /* ------------------------------------------------------------------ */
    /*  One set of rating bands */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_model_accessor_and_the_service_agree_on_the_boundary_score(): void
    {
        // 5 is the score the two implementations disagreed about: the service
        // called it Medium (>= 5), the accessor called it Low (>= 6).
        $risk = $this->makeRisk([
            'inherent_likelihood' => 1,
            'inherent_impact' => 5,
            'inherent_rating' => null,
        ]);

        $this->assertSame('Medium', $risk->inherent_rating);
        $this->assertSame('Medium', app(RiskScoringService::class)->calculateRating(5));
    }

    #[Test]
    public function a_stored_rating_is_returned_as_stored(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 1,
            'inherent_impact' => 5,
            'inherent_rating' => 'Critical',
        ]);

        $this->assertSame('Critical', $risk->inherent_rating);
    }

    /* ------------------------------------------------------------------ */
    /*  One effectiveness map, configurable per organization */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_control_model_and_the_service_report_the_same_percentage(): void
    {
        // The model constant said 'effective' meant 100%, the service said
        // 95%. The five-band service map is now the only one.
        $risk = $this->makeRisk();
        $control = $this->makeControl(['effectiveness_rating' => 'effective']);
        $this->attachControl($risk, $control);

        $this->assertSame(95, $control->effectiveness_percent);
        $this->assertSame(95.0, (new ControlEffectivenessService)->calculateForRisk($risk));
    }

    #[Test]
    public function an_organization_can_override_an_effectiveness_band(): void
    {
        $this->organization->update(['settings' => ['risk' => ['control_effectiveness' => ['effective' => 88]]]]);
        RiskCalculationSettings::flush();

        $risk = $this->makeRisk();
        $control = $this->makeControl(['effectiveness_rating' => 'effective']);
        $this->attachControl($risk, $control);

        $this->assertSame(88, $control->fresh()->effectiveness_percent);
        $this->assertSame(88.0, (new ControlEffectivenessService)->calculateForRisk($risk));
    }

    #[Test]
    public function an_override_leaves_the_other_bands_at_their_defaults(): void
    {
        $this->organization->update(['settings' => ['risk' => ['control_effectiveness' => ['effective' => 88]]]]);
        RiskCalculationSettings::flush();

        $map = RiskCalculationSettings::effectivenessMap($this->organization->id);

        $this->assertSame(88, $map['effective']);
        $this->assertSame(80, $map['mostly_effective']);
        $this->assertSame(12, $map['not_operating']);
    }

    /* ------------------------------------------------------------------ */
    /*  Impact aggregation: strategic included, method configurable */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_strategic_dimension_counts_towards_the_impact_score(): void
    {
        // It was omitted here while RiskAssessment::impactScore() included it,
        // so the same assessment scored differently in the two places.
        $service = app(RiskScoringService::class);

        $this->assertSame(5, $service->calculateMaxImpact(1, 2, 1, 1, 5, $this->organization->id));
    }

    #[Test]
    #[DataProvider('aggregationMethods')]
    public function each_aggregation_method_collapses_the_dimensions_its_own_way(string $method, int $expected): void
    {
        // WP-05 TASK 3 moved the aggregation method out of
        // organizations.settings->risk and into the scoring profile, which is
        // now the single definition of how a score is arrived at. The four
        // methods and their arithmetic are unchanged; only the surface that
        // selects one has moved.
        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'aggregation-'.$method,
            'impact_aggregation' => $method,
            'is_system' => false,
        ]));
        ScoringProfile::flushResolutionCache();

        $impacts = ['financial' => 5, 'operational' => 4, 'reputational' => 2, 'regulatory' => 1, 'strategic' => 3];

        $this->assertSame(
            $expected,
            app(RiskScoringService::class)->calculateImpact($impacts, $this->organization->id)
        );
    }

    public static function aggregationMethods(): array
    {
        return [
            // 5,4,3,2,1
            'max' => ['max', 5],
            'average' => ['average', 3],       // 15 / 5
            'weighted' => ['weighted', 3],     // equal weights => the mean
            'worst two' => ['worst_two', 5],   // (5 + 4) / 2 = 4.5 -> 5
        ];
    }

    #[Test]
    public function unscored_dimensions_are_excluded_rather_than_counted_as_zero(): void
    {
        $this->organization->update(['settings' => ['risk' => ['impact_aggregation' => 'average']]]);
        RiskCalculationSettings::flush();

        // Two dimensions scored 4 and 5; a half-finished assessment must not
        // be dragged down by three implicit zeroes.
        $this->assertSame(5, app(RiskScoringService::class)->calculateImpact([
            'financial' => 4,
            'operational' => 5,
        ], $this->organization->id));
    }

    #[Test]
    public function an_unscored_assessment_has_an_impact_of_zero(): void
    {
        $this->assertSame(0, app(RiskScoringService::class)->calculateImpact([], $this->organization->id));
    }

    #[Test]
    public function an_unrecognised_aggregation_method_falls_back_to_the_default(): void
    {
        $this->organization->update(['settings' => ['risk' => ['impact_aggregation' => 'nonsense']]]);
        RiskCalculationSettings::flush();

        $this->assertSame('max', RiskCalculationSettings::impactAggregation($this->organization->id));
    }

    /* ------------------------------------------------------------------ */
    /*  RiskAppetiteService reads real columns */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_appetite_comparison_reports_the_stored_level_and_statement(): void
    {
        // It used to read appetite_type and notes — neither of which existed —
        // so every category came back 'Not Specified' with an empty statement.
        $this->makeAppetite([
            'appetite_level' => 'cautious',
            'appetite_type' => 'quantitative',
            'appetite_statement' => 'No single operational loss above NGN 50m.',
        ]);

        $row = (new RiskAppetiteService)->compareAgainstActual($this->organization->id)[0];

        $this->assertSame('cautious', $row['appetite_level']);
        $this->assertSame('quantitative', $row['appetite_type']);
        $this->assertSame('No single operational loss above NGN 50m.', $row['appetite_statement']);
    }

    #[Test]
    public function capacity_is_reported_as_stored_rather_than_as_a_constant(): void
    {
        // The old code returned the literal 25 for every organisation.
        $this->makeAppetite(['capacity' => 18]);

        $row = (new RiskAppetiteService)->compareAgainstActual($this->organization->id)[0];

        $this->assertSame(18.0, $row['capacity']);
    }

    #[Test]
    public function an_unrecorded_capacity_never_reports_a_capacity_breach(): void
    {
        // With the hard-coded 25, a category averaging 26 was reported as
        // exceeding a capacity nobody had ever defined.
        $this->makeAppetite(['capacity' => null, 'max_tolerance' => 10, 'target_max' => 6]);
        $this->makeRisk(['inherent_score' => 25, 'inherent_rating' => 'Critical', 'status' => 'active']);

        $row = (new RiskAppetiteService)->compareAgainstActual($this->organization->id)[0];

        $this->assertNull($row['capacity']);
        $this->assertSame('exceeds_tolerance', $row['status']);
    }

    #[Test]
    public function a_recorded_capacity_is_breached_when_it_is_exceeded(): void
    {
        $this->makeAppetite(['capacity' => 20, 'max_tolerance' => 10, 'target_max' => 6]);
        $this->makeRisk(['inherent_score' => 25, 'inherent_rating' => 'Critical', 'status' => 'active']);

        $row = (new RiskAppetiteService)->compareAgainstActual($this->organization->id)[0];

        $this->assertSame('exceeds_capacity', $row['status']);
    }

    #[Test]
    public function a_score_between_target_and_tolerance_is_approaching_tolerance(): void
    {
        $this->makeAppetite(['capacity' => 20, 'max_tolerance' => 10, 'target_max' => 6]);
        $this->makeRisk(['inherent_score' => 8, 'status' => 'active']);

        $row = (new RiskAppetiteService)->compareAgainstActual($this->organization->id)[0];

        $this->assertSame('approaching_tolerance', $row['status']);
        $this->assertSame(80.0, $row['utilization_pct']);
    }

    #[Test]
    public function a_score_within_target_is_within_appetite(): void
    {
        $this->makeAppetite(['capacity' => 20, 'max_tolerance' => 10, 'target_max' => 6]);
        $this->makeRisk(['inherent_score' => 4, 'status' => 'active']);

        $this->assertSame(
            'within_appetite',
            (new RiskAppetiteService)->compareAgainstActual($this->organization->id)[0]['status']
        );
    }

    /* ------------------------------------------------------------------ */

    private function makeAppetite(array $attributes = []): RiskAppetite
    {
        return RiskAppetite::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_category_id' => $this->category->id,
            'appetite_level' => 'cautious',
            'appetite_statement' => 'Statement',
            'tolerance_metric' => 'Average inherent score',
            'max_tolerance' => 12,
            'target_min' => 0,
            'target_max' => 8,
            'unit_of_measure' => 'score',
            'effective_date' => now()->subMonth()->toDateString(),
        ], $attributes));
    }
}
