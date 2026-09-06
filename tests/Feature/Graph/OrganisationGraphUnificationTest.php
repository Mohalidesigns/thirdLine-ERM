<?php

namespace Tests\Feature\Graph;

use App\Models\GraphObject;
use App\Models\ObjectMergeCandidate;
use App\Support\Graph\OrganisationGraphUnifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-03 TASK 4 — the two organisational models become one graph.
 *
 * Rows are inserted through the query builder rather than the models on
 * purpose. HasObjectIdentity mirrors a model the moment it is saved, so going
 * through Eloquent would hand the unifier a graph that already exists and the
 * merge path would never run. The migration meets a database full of rows that
 * predate the trait, and so does this test.
 */
class OrganisationGraphUnificationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private int $entityTypeDivision;

    private int $entityTypeGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();

        $this->entityTypeGroup = $this->makeEntityType('GROUP', 'Group', 0);
        $this->entityTypeDivision = $this->makeEntityType('DIVISION', 'Division', 2);
    }

    /* ------------------------------------------------------------------ */
    /*  Structure */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_merged_tree_has_no_cycles(): void
    {
        $root = $this->makeEntityRow('ENT-1', 'FirstBank Holdings', $this->entityTypeGroup);
        $mid = $this->makeEntityRow('ENT-2', 'Retail Banking Division', $this->entityTypeDivision, $root);
        $leaf = $this->makeEntityRow('ENT-3', 'Lagos Region', $this->entityTypeDivision, $mid);

        // A cycle the two source trees could not each produce alone.
        DB::table('entities')->where('id', $root)->update(['parent_id' => $leaf]);

        $counts = (new OrganisationGraphUnifier)->run();

        $this->assertGreaterThan(0, $counts['cycles_broken'], 'The cycle was not detected');
        $this->assertNoCycles();

        // The nodes survive; only the offending parent link is cut.
        foreach ([$root, $mid, $leaf] as $entityId) {
            $this->assertNotNull($this->objectFor('entity', $entityId));
        }
    }

    #[Test]
    public function every_node_has_a_hierarchy_path_that_matches_its_parent_chain(): void
    {
        $root = $this->makeEntityRow('ENT-1', 'Holdings', $this->entityTypeGroup);
        $division = $this->makeEntityRow('ENT-2', 'Retail Banking Division', $this->entityTypeDivision, $root);
        $this->makeEntityRow('ENT-3', 'Lagos Region', $this->entityTypeDivision, $division);
        $unit = $this->makeBusinessUnitRow('BU-OPS', 'Operations');
        $this->makeBusinessProcessRow('PRC-1', 'Cash handling', $unit);

        (new OrganisationGraphUnifier)->run();

        foreach (GraphObject::query()->get() as $object) {
            $expected = $this->expectedPath($object);

            $this->assertSame(
                $expected,
                $object->hierarchy_path,
                "Wrong path for object #{$object->id} ({$object->name})"
            );

            $this->assertSame(
                max(0, substr_count($expected, '/') - 2),
                (int) $object->hierarchy_depth,
                "Wrong depth for object #{$object->id}"
            );
        }
    }

    #[Test]
    public function a_business_process_hangs_off_its_business_unit(): void
    {
        $unit = $this->makeBusinessUnitRow('BU-OPS', 'Operations');
        $process = $this->makeBusinessProcessRow('PRC-1', 'Cash handling', $unit);

        (new OrganisationGraphUnifier)->run();

        $unitObject = $this->objectFor('business_unit', $unit);
        $processObject = $this->objectFor('business_process', $process);

        $this->assertSame($unitObject->id, $processObject->parent_id);
        $this->assertStringStartsWith($unitObject->hierarchy_path, $processObject->hierarchy_path);
    }

    /* ------------------------------------------------------------------ */
    /*  The merge rule */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_exact_name_match_on_both_sides_is_merged_and_recorded(): void
    {
        $entity = $this->makeEntityRow('ENT-1', 'Internal Audit', $this->entityTypeDivision);
        $unit = $this->makeBusinessUnitRow('BU-IA', 'internal  audit!');

        (new OrganisationGraphUnifier)->run();

        $this->assertNull(
            DB::table('objects')->where('source_model_type', 'business_unit')->where('source_model_id', $unit)->first(),
            'A merged business unit must not also get a node of its own'
        );

        $merged = ObjectMergeCandidate::where('decision', 'auto_merged')->first();

        $this->assertNotNull($merged, 'The merge was not recorded for review');
        $this->assertSame('exact_normalised_name', $merged->match_basis);
        $this->assertSame($this->objectFor('entity', $entity)->id, $merged->resolved_object_id);
    }

    #[Test]
    public function names_that_merely_look_similar_are_not_merged(): void
    {
        $this->makeEntityRow('ENT-1', 'Operations & Technology', $this->entityTypeDivision);
        $unit = $this->makeBusinessUnitRow('BU-OP', 'Operations');

        (new OrganisationGraphUnifier)->run();

        // Two nodes, not one. Nobody but the bank knows whether these are the
        // same department.
        $this->assertNotNull($this->objectFor('business_unit', $unit));

        $pending = ObjectMergeCandidate::pending()
            ->where('match_basis', 'name_similarity')
            ->first();

        $this->assertNotNull($pending, 'The near-match was not queued for review');
        $this->assertGreaterThan(0.6, (float) $pending->similarity);
        $this->assertLessThan(1.0, (float) $pending->similarity);
    }

    #[Test]
    public function an_ambiguous_name_is_never_merged_automatically(): void
    {
        // Two entities with the same normalised name: no rule can pick one.
        $this->makeEntityRow('ENT-1', 'Treasury', $this->entityTypeDivision);
        $this->makeEntityRow('ENT-2', 'treasury', $this->entityTypeDivision);
        $unit = $this->makeBusinessUnitRow('BU-TR', 'Treasury');

        (new OrganisationGraphUnifier)->run();

        $this->assertNotNull(
            $this->objectFor('business_unit', $unit),
            'An ambiguous match must stay a separate node'
        );
        $this->assertSame(0, ObjectMergeCandidate::where('decision', 'auto_merged')->count());
    }

    #[Test]
    public function parentless_units_are_attached_to_a_single_root_and_the_attachment_is_recorded(): void
    {
        $root = $this->makeEntityRow('ENT-1', 'Holdings', $this->entityTypeGroup);
        $unit = $this->makeBusinessUnitRow('BU-OPS', 'Operations');

        (new OrganisationGraphUnifier)->run();

        $rootObject = $this->objectFor('entity', $root);
        $unitObject = $this->objectFor('business_unit', $unit);

        $this->assertSame($rootObject->id, $unitObject->parent_id);

        $attachment = ObjectMergeCandidate::where('match_basis', 'attached_to_root')->first();
        $this->assertNotNull($attachment, 'Attaching an orphan to the root must be reviewable');
        $this->assertSame('auto_attached', $attachment->decision);
    }

    #[Test]
    public function nothing_is_attached_when_there_is_no_single_root(): void
    {
        $this->makeEntityRow('ENT-1', 'Holdings A', $this->entityTypeGroup);
        $this->makeEntityRow('ENT-2', 'Holdings B', $this->entityTypeGroup);
        $unit = $this->makeBusinessUnitRow('BU-OPS', 'Operations');

        (new OrganisationGraphUnifier)->run();

        $this->assertNull($this->objectFor('business_unit', $unit)->parent_id);
        $this->assertSame(0, ObjectMergeCandidate::where('match_basis', 'attached_to_root')->count());
    }

    #[Test]
    public function an_unmapped_entity_type_is_imported_and_flagged_rather_than_dropped(): void
    {
        $bespoke = $this->makeEntityType('COOPERATIVE', 'Cooperative Society', 3);
        $entity = $this->makeEntityRow('ENT-9', 'Staff Cooperative', $bespoke);

        (new OrganisationGraphUnifier)->run();

        $object = $this->objectFor('entity', $entity);
        $this->assertNotNull($object, 'An unmapped entity type must not make the node disappear');
        $this->assertSame('BusinessUnit', $object->objectType->code);

        $flagged = ObjectMergeCandidate::where('match_basis', 'unmapped_entity_type')->first();
        $this->assertNotNull($flagged);
        $this->assertSame('pending', $flagged->decision);
    }

    /* ------------------------------------------------------------------ */
    /*  node_id resolution */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_risk_resolves_to_exactly_one_node(): void
    {
        $entity = $this->makeEntityRow('ENT-1', 'Retail Banking Division', $this->entityTypeDivision);
        $unit = $this->makeBusinessUnitRow('BU-OPS', 'Operations');

        // Straight through the query builder so the trait does not pre-resolve.
        $viaEntity = $this->makeRiskRow('RK-1', ['entity_id' => $entity]);
        $viaUnit = $this->makeRiskRow('RK-2', ['business_unit_id' => $unit]);
        $viaBoth = $this->makeRiskRow('RK-3', ['entity_id' => $entity, 'business_unit_id' => $unit]);
        $viaNeither = $this->makeRiskRow('RK-4');

        (new OrganisationGraphUnifier)->run();

        $nodeOf = fn (int $id) => DB::table('risks')->where('id', $id)->value('node_id');

        $this->assertSame($this->objectFor('entity', $entity)->id, (int) $nodeOf($viaEntity));
        $this->assertSame($this->objectFor('business_unit', $unit)->id, (int) $nodeOf($viaUnit));

        // entity_id wins: it is the model the scoping UI writes.
        $this->assertSame(
            $this->objectFor('entity', $entity)->id,
            (int) $nodeOf($viaBoth),
            'A risk pointing at both models must resolve to one node, not two'
        );

        $this->assertNull($nodeOf($viaNeither), 'A risk with no org link must not be given one');
    }

    #[Test]
    public function a_risk_never_resolves_to_a_node_in_another_tenant(): void
    {
        $mine = $this->makeEntityRow('ENT-1', 'My Division', $this->entityTypeDivision);

        $otherOrg = DB::table('organizations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $risk = $this->makeRiskRow('RK-1', ['entity_id' => $mine]);

        (new OrganisationGraphUnifier)->run();

        $nodeId = DB::table('risks')->where('id', $risk)->value('node_id');

        $this->assertSame(
            $this->organization->id,
            (int) DB::table('objects')->where('id', $nodeId)->value('organization_id'),
            "A risk resolved to a node belonging to organization {$otherOrg}"
        );
    }

    #[Test]
    public function rerunning_the_unifier_does_not_duplicate_nodes(): void
    {
        $this->makeEntityRow('ENT-1', 'Holdings', $this->entityTypeGroup);
        $this->makeBusinessUnitRow('BU-OPS', 'Operations');

        (new OrganisationGraphUnifier)->run();
        $first = GraphObject::count();

        (new OrganisationGraphUnifier)->run();

        $this->assertSame($first, GraphObject::count());
        $this->assertNoCycles();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function assertNoCycles(): void
    {
        $parents = DB::table('objects')->pluck('parent_id', 'id');

        $this->assertNotEmpty($parents, 'No objects to check for cycles');

        foreach ($parents as $id => $parentId) {
            $seen = [(int) $id => true];
            $cursor = $parentId;
            $steps = 0;

            while ($cursor !== null && $steps++ < 1000) {
                $this->assertArrayNotHasKey(
                    (int) $cursor,
                    $seen,
                    "Cycle in the object graph reaching object #{$cursor}"
                );

                $seen[(int) $cursor] = true;
                $cursor = $parents[$cursor] ?? null;
            }
        }
    }

    private function expectedPath(GraphObject $object): string
    {
        $chain = [];
        $cursor = $object;
        $guard = 0;

        while ($cursor !== null && $guard++ < 100) {
            array_unshift($chain, $cursor->id);
            $cursor = $cursor->parent_id === null ? null : GraphObject::find($cursor->parent_id);
        }

        return '/'.implode('/', $chain).'/';
    }

    private function objectFor(string $alias, int $sourceId): ?GraphObject
    {
        return GraphObject::query()
            ->where('source_model_type', $alias)
            ->where('source_model_id', $sourceId)
            ->first();
    }

    private function makeEntityType(string $code, string $name, int $level): int
    {
        return (int) DB::table('entity_types')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEntityRow(string $code, string $name, int $typeId, ?int $parentId = null): int
    {
        return (int) DB::table('entities')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'entity_type_id' => $typeId,
            'parent_id' => $parentId,
            'entity_code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeBusinessUnitRow(string $code, string $name, ?int $parentId = null): int
    {
        return (int) DB::table('business_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeBusinessProcessRow(string $code, string $name, int $unitId): int
    {
        return (int) DB::table('business_processes')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $unitId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int|null>  $links
     */
    private function makeRiskRow(string $code, array $links = []): int
    {
        return (int) DB::table('risks')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'risk_code' => $code,
            'title' => 'Risk '.$code,
            'description' => 'Inserted without the model so the trait does not pre-resolve the node.',
            'category_id' => $this->category->id,
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $links));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }
}
