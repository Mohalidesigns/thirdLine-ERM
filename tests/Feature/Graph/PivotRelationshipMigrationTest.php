<?php

namespace Tests\Feature\Graph;

use App\Models\GraphObject;
use App\Models\ObjectRelationship;
use App\Support\Graph\ObjectBackfiller;
use App\Support\Graph\PivotRelationshipMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-03 TASK 5 — every migrated pivot is queryable through
 * object_relationships, and the pivot tables themselves are untouched.
 */
class PivotRelationshipMigrationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
    }

    #[Test]
    public function a_risk_control_mapping_becomes_a_weighted_mitigates_edge(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        DB::table('risk_control_mapping')->insert([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Dual authorisation covers the approval step',
            'control_weight' => 0.65,
            'is_key_control' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new PivotRelationshipMigrator)->run();

        $edge = ObjectRelationship::ofType('mitigates')->first();

        $this->assertNotNull($edge, 'No mitigates edge was written');
        $this->assertSame($control->graphObject()->id, $edge->from_object_id, 'The control is the actor, so the FROM side');
        $this->assertSame($risk->graphObject()->id, $edge->to_object_id);
        $this->assertSame(0.65, (float) $edge->weight);
        $this->assertTrue($edge->attributes['is_key_control']);
        $this->assertSame('Dual authorisation covers the approval step', $edge->attributes['mapping_rationale']);
    }

    #[Test]
    public function the_pivot_tables_are_left_readable(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        $this->attachControl($risk, $control, 0.5, true);

        (new PivotRelationshipMigrator)->run();

        // Additive migrations rule: this release stops treating the pivot as
        // the only answer; the next one removes it.
        $this->assertDatabaseCount('risk_control_mapping', 1);
        $this->assertTrue($risk->controls()->where('controls.id', $control->id)->exists());
    }

    #[Test]
    public function a_loss_event_control_failure_becomes_a_failed_control_edge(): void
    {
        $lossEvent = $this->makeLossEvent();
        $control = $this->makeControl();

        DB::table('loss_event_controls')->insert([
            'loss_event_id' => $lossEvent->id,
            'control_id' => $control->id,
            'failure_type' => 'not_operating',
            'failure_description' => 'The reconciliation was not performed for three days',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new PivotRelationshipMigrator)->run();

        $edge = ObjectRelationship::ofType('failed_control')->first();

        $this->assertNotNull($edge);
        $this->assertSame($lossEvent->graphObject()->id, $edge->from_object_id);
        $this->assertSame('not_operating', $edge->attributes['failure_type']);
    }

    #[Test]
    public function a_kri_mapping_becomes_a_monitored_by_edge(): void
    {
        $risk = $this->makeRisk();
        $kri = $this->makeKri();

        DB::table('risk_kri_mapping')->insert([
            'risk_id' => $risk->id,
            'kri_id' => $kri,
            'correlation_type' => 'leading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ObjectBackfiller)->run();
        (new PivotRelationshipMigrator)->run();

        $edge = ObjectRelationship::ofType('monitored_by')->first();

        $this->assertNotNull($edge);
        $this->assertSame($risk->graphObject()->id, $edge->from_object_id);
        $this->assertSame('leading', $edge->attributes['correlation_type']);
    }

    #[Test]
    public function related_risks_become_derives_from_edges_carrying_the_correlation(): void
    {
        $parent = $this->makeRisk();
        $child = $this->makeRisk();

        DB::table('risk_related_risks')->insert([
            'risk_id' => $child->id,
            'related_risk_id' => $parent->id,
            'relationship_type' => 'contributes_to',
            'correlation_strength' => 'strong',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new PivotRelationshipMigrator)->run();

        $edge = ObjectRelationship::ofType('derives_from')->first();

        $this->assertNotNull($edge);
        $this->assertSame('strong', $edge->attributes['correlation_strength']);
    }

    #[Test]
    public function a_regulatory_mapping_materialises_the_requirement_it_points_at(): void
    {
        $riskA = $this->makeRisk();
        $riskB = $this->makeRisk();

        foreach ([$riskA, $riskB] as $risk) {
            DB::table('regulatory_risk_mapping')->insert([
                'risk_id' => $risk->id,
                'regulation_name' => 'CBN Risk-Based Supervision Framework',
                'requirement_ref' => 'BSD/DIR/GEN/LAB/07/052',
                'mapping_notes' => 'Operational risk capital',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        (new PivotRelationshipMigrator)->run();

        $requirements = GraphObject::ofType('Requirement')->get();

        // The same requirement referenced twice is ONE object and TWO edges —
        // that is what makes "what else does this circular touch" answerable.
        $this->assertCount(1, $requirements);
        $this->assertStringContainsString('BSD/DIR/GEN/LAB/07/052', $requirements->first()->name);
        $this->assertSame(2, ObjectRelationship::ofType('maps_to')->count());

        $edge = ObjectRelationship::ofType('maps_to')->first();
        $this->assertSame('CBN Risk-Based Supervision Framework', $edge->attributes['regulation_name']);
    }

    #[Test]
    public function a_converted_near_miss_becomes_a_converted_to_edge(): void
    {
        $lossEvent = $this->makeLossEvent();
        $nearMissId = $this->makeNearMiss(['converted_loss_event_id' => $lossEvent->id]);

        (new ObjectBackfiller)->run();
        (new PivotRelationshipMigrator)->run();

        $edge = ObjectRelationship::ofType('converted_to')->first();

        $this->assertNotNull($edge);
        $this->assertSame(
            GraphObject::where('source_model_type', 'near_miss')->where('source_model_id', $nearMissId)->value('id'),
            $edge->from_object_id
        );
    }

    #[Test]
    public function migrating_twice_does_not_duplicate_edges(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        $this->attachControl($risk, $control, 0.5);

        (new PivotRelationshipMigrator)->run();
        (new PivotRelationshipMigrator)->run();

        $this->assertSame(1, ObjectRelationship::ofType('mitigates')->count());
    }

    #[Test]
    public function an_edge_inherits_the_tenant_of_its_endpoints(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();
        $this->attachControl($risk, $control);

        (new PivotRelationshipMigrator)->run();

        $this->assertSame(
            $this->organization->id,
            ObjectRelationship::ofType('mitigates')->first()->organization_id
        );
    }

    /* ------------------------------------------------------------------ */

    private function makeKri(): int
    {
        return (int) DB::table('key_risk_indicators')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-'.Str::random(6),
            'name' => 'Failed reconciliations per week',
            'measurement_frequency' => 'weekly',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeNearMiss(array $attributes = []): int
    {
        return (int) DB::table('near_misses')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'reference' => 'NM-'.Str::random(6),
            'event_reference' => 'NM-'.Str::random(6),
            'title' => 'Unauthorised transfer stopped at review',
            'description' => 'Caught by the second-level approver before value date.',
            'date_occurred' => now()->subWeek()->toDateString(),
            'date_reported' => now()->toDateString(),
            'potential_loss_kobo' => 0,
            'severity' => 'MODERATE',
            'status' => 'open',
            'reported_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
