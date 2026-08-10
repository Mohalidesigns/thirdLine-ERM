<?php

namespace Tests\Feature\Graph;

use App\Models\AssessmentCampaign;
use App\Models\BusinessProcess;
use App\Models\BusinessUnit;
use App\Models\Concerns\HasObjectIdentity;
use App\Models\Control;
use App\Models\ControlTest;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\GraphObject;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\NearMiss;
use App\Models\ObjectType;
use App\Models\QuantificationScenario;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Models\TreatmentPlan;
use App\Support\Graph\ObjectSourceMap;
use App\Support\MorphTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-03 TASK 3 — every model carrying HasObjectIdentity gets a graph node.
 */
class ObjectIdentityTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
    }

    /**
     * The acceptance criterion, stated as a list. If a model is added to
     * ObjectSourceMap it must appear here too, or the coverage test below
     * fails.
     *
     * @return list<class-string>
     */
    public static function requiredModels(): array
    {
        return [
            Risk::class, Control::class, KeyRiskIndicator::class, Issue::class,
            LossEvent::class, NearMiss::class, TreatmentPlan::class, RiskAppetite::class,
            AssessmentCampaign::class, ControlTest::class, Entity::class, BusinessUnit::class,
            BusinessProcess::class, RiskCategory::class, QuantificationScenario::class,
        ];
    }

    #[Test]
    public function every_model_named_by_the_work_package_carries_the_trait(): void
    {
        $missing = array_values(array_filter(
            self::requiredModels(),
            fn (string $class) => ! in_array(HasObjectIdentity::class, class_uses_recursive($class), true)
        ));

        $this->assertSame([], $missing, 'Models missing HasObjectIdentity: '.implode(', ', $missing));
    }

    #[Test]
    public function every_model_in_the_source_map_is_covered_by_this_test(): void
    {
        $mapped = array_keys(ObjectSourceMap::all());
        $tested = self::requiredModels();

        $this->assertSame(
            [],
            array_values(array_diff($mapped, $tested)),
            'ObjectSourceMap knows about models this test does not assert on.'
        );
    }

    #[Test]
    public function saving_a_risk_creates_a_graph_node_mirroring_it(): void
    {
        $risk = $this->makeRisk(['title' => 'Cash handling failure at branch']);

        $object = GraphObject::where('source_model_type', 'risk')
            ->where('source_model_id', $risk->id)
            ->first();

        $this->assertNotNull($object, 'No objects row was created for the risk');
        $this->assertSame($risk->risk_code, $object->code);
        $this->assertSame('Cash handling failure at branch', $object->name);
        $this->assertSame('active', $object->lifecycle_state);
        $this->assertSame($this->organization->id, $object->organization_id);
        $this->assertSame('Risk', $object->objectType->code);
        $this->assertSame($risk->risk_owner_id, $object->owner_id);
    }

    #[Test]
    public function updating_the_record_updates_the_node_and_records_a_version(): void
    {
        $risk = $this->makeRisk(['title' => 'Original title']);
        $object = $risk->graphObject();

        $this->assertSame(1, $object->version);

        $risk->update(['title' => 'Renamed after review']);
        $object->refresh();

        $this->assertSame('Renamed after review', $object->name);
        $this->assertSame(2, $object->version);
        $this->assertDatabaseHas('object_versions', ['object_id' => $object->id, 'version' => 2]);
    }

    #[Test]
    public function an_unrelated_save_does_not_churn_the_version(): void
    {
        $risk = $this->makeRisk();
        $object = $risk->graphObject();

        // inherent_likelihood is not mirrored into the graph.
        $risk->update(['inherent_likelihood' => 4]);

        $this->assertSame(1, $object->fresh()->version);
    }

    #[Test]
    public function soft_deleting_the_record_soft_deletes_the_node(): void
    {
        $risk = $this->makeRisk();
        $objectId = $risk->graphObject()->id;

        $risk->delete();

        $this->assertSoftDeleted('objects', ['id' => $objectId]);
        $this->assertNull(GraphObject::find($objectId));
    }

    #[Test]
    public function the_node_resolves_the_org_graph_node_from_the_business_unit(): void
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-OPS',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $risk = $this->makeRisk(['business_unit_id' => $unit->id]);

        $this->assertSame($unit->graphObject()->id, $risk->fresh()->node_id);
        $this->assertSame('Operations', $risk->fresh()->node->name);
    }

    #[Test]
    public function an_entity_takes_its_object_type_from_its_entity_type(): void
    {
        $branchType = EntityType::create([
            'organization_id' => $this->organization->id,
            'code' => 'BRANCH',
            'name' => 'Branch',
            'level' => 4,
        ]);

        $entity = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $branchType->id,
            'entity_code' => 'ENT-9001',
            'name' => 'Ikeja Branch',
            'status' => 'active',
        ]);

        $this->assertSame('Branch', $entity->graphObject()->objectType->code);
    }

    #[Test]
    public function an_org_node_owns_itself(): void
    {
        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-TR',
            'name' => 'Treasury',
            'is_active' => true,
        ]);

        $object = $unit->graphObject()->refresh();

        $this->assertSame($object->id, $object->node_id, 'An org node must be its own node');
        $this->assertTrue($object->objectType->is_node_type);
    }

    #[Test]
    public function relate_creates_a_typed_edge_and_related_reads_it_back(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $edge = $control->relate('mitigates', $risk, ['is_key_control' => true], weight: 0.4);

        $this->assertNotNull($edge);
        $this->assertSame(0.4, (float) $edge->weight);
        $this->assertTrue($edge->attributes['is_key_control']);

        $this->assertTrue($control->related('mitigates')->contains('id', $risk->graphObject()->id));
        $this->assertTrue($risk->related('mitigates', 'in')->contains('id', $control->graphObject()->id));
    }

    #[Test]
    public function an_edge_the_relationship_type_forbids_is_refused(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        // 'mitigates' runs Control -> Risk. Backwards is a data error.
        $this->assertNull($risk->relate('mitigates', $control));
        $this->assertDatabaseCount('object_relationships', 0);
    }

    #[Test]
    public function asserting_the_same_edge_twice_updates_rather_than_duplicating(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $control->relate('mitigates', $risk, weight: 0.4);
        $control->relate('mitigates', $risk, weight: 0.9);

        $this->assertDatabaseCount('object_relationships', 1);
        $this->assertSame(0.9, (float) $control->graphObject()->outgoingRelationships()->first()->weight);
    }

    #[Test]
    public function the_object_code_is_unique_per_type_within_a_tenant(): void
    {
        $risk = $this->makeRisk();

        $this->assertDatabaseCount('objects', GraphObject::withTrashed()->count());

        $duplicate = GraphObject::query()
            ->where('organization_id', $risk->organization_id)
            ->where('object_type_id', $risk->graphObject()->object_type_id)
            ->where('code', $risk->risk_code)
            ->count();

        $this->assertSame(1, $duplicate);
    }

    #[Test]
    public function the_morph_alias_written_to_the_graph_is_the_canonical_one(): void
    {
        foreach (self::requiredModels() as $class) {
            $this->assertNotNull(
                MorphTypes::aliasFor($class),
                "{$class} is mirrored into the graph but is not in the enforced morph map."
            );
        }
    }

    #[Test]
    public function the_system_type_registry_is_seeded_by_migration(): void
    {
        // No seeder is run in this test; the registry must arrive with the
        // schema, because the unification migration depends on it.
        $this->assertGreaterThanOrEqual(48, ObjectType::whereNull('organization_id')->count());

        foreach (['Risk', 'Control', 'BusinessUnit', 'Process', 'Requirement', 'Objective'] as $code) {
            $this->assertNotNull(ObjectType::resolve($code), "System type [{$code}] was not seeded");
        }
    }
}
