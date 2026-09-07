<?php

namespace Tests\Feature\Graph;

use App\Models\BusinessUnit;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\GraphObject;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Services\Graph\ObjectSyncService;
use App\Support\Graph\ObjectSourceMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * A ring in a self-referential parent column must not take the process down.
 *
 * Found during RCSA v2 P7. Saving a business unit whose parent_id pointed at
 * its own child exhausted PHP's 512 MB and killed the request — not in RCSA
 * code, but in the graph projection HasObjectIdentity runs on every save:
 * ObjectSourceMap declares a `parent` edge for business units, and
 * ObjectSyncService walked it with no visited set, appending a segment to
 * hierarchy_path on each pass round the ring. The typed row had already
 * committed by then, so it survived to kill the next request too.
 *
 * Two things are asserted here, at the two layers that were changed:
 *
 *  1. the write is refused (RejectsParentCycles), with a message a user can
 *     act on rather than a 500; and
 *  2. a ring that got in anyway — a direct UPDATE, or a row written before the
 *     guard existed — makes the projection stop and log rather than recurse.
 *
 * The second is the one that matters. The first can be bypassed; the second is
 * what stands between a malformed row and the process.
 */
class ParentCycleTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
    }

    /* ------------------------------------------------------------------ */
    /*  1. The cycle cannot be created */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_business_unit_cannot_be_saved_under_its_own_child(): void
    {
        $parent = $this->makeUnit('BU-P', 'Retail Banking');
        $child = $this->makeUnit('BU-C', 'Branch Network', $parent);

        // The reported reproduction, verbatim: the normal model save path, no
        // query builder, no importer. Before the fix this line did not fail —
        // it exhausted 512 MB and killed the process.
        try {
            $parent->forceFill(['parent_id' => $child->id])->save();
            $this->fail('Saving a unit under its own child should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'A business unit cannot be moved under one of its own descendants.',
                $e->validator->errors()->first('parent_id')
            );
        }

        $this->assertNull($parent->fresh()->parent_id, 'The refused parent must not have been written.');
    }

    #[Test]
    public function a_business_unit_cannot_be_its_own_parent(): void
    {
        $unit = $this->makeUnit('BU-S', 'Treasury');

        try {
            $unit->forceFill(['parent_id' => $unit->id])->save();
            $this->fail('A unit pointing at itself should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'A business unit cannot be its own parent.',
                $e->validator->errors()->first('parent_id')
            );
        }
    }

    #[Test]
    public function the_guard_catches_a_grandchild_not_just_a_child(): void
    {
        $top = $this->makeUnit('BU-1', 'Group');
        $middle = $this->makeUnit('BU-2', 'Division', $top);
        $bottom = $this->makeUnit('BU-3', 'Branch', $middle);

        $this->expectException(ValidationException::class);

        $top->forceFill(['parent_id' => $bottom->id])->save();
    }

    #[Test]
    public function a_legitimate_re_parent_is_still_allowed(): void
    {
        $left = $this->makeUnit('BU-L', 'Left');
        $right = $this->makeUnit('BU-R', 'Right');
        $leaf = $this->makeUnit('BU-F', 'Leaf', $left);

        $leaf->forceFill(['parent_id' => $right->id])->save();

        $this->assertSame($right->id, $leaf->fresh()->parent_id);
    }

    #[Test]
    public function the_check_terminates_when_the_table_is_already_cyclic(): void
    {
        // Rows written before the guard existed. The walk up from the proposed
        // parent runs straight into the ring; without its own seen-set the
        // check meant to detect a cycle would itself never return.
        $a = $this->makeUnit('BU-A', 'A');
        $b = $this->makeUnit('BU-B', 'B', $a);
        DB::table('business_units')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $outsider = $this->makeUnit('BU-O', 'Outsider');

        $outsider->forceFill(['parent_id' => $b->id])->save();

        $this->assertSame($b->id, $outsider->fresh()->parent_id);
    }

    #[Test]
    public function every_model_with_a_parent_edge_in_the_source_map_carries_the_guard(): void
    {
        $unguarded = [];

        foreach (ObjectSourceMap::all() as $class => $spec) {
            if ($spec['parent'] === null) {
                continue;
            }

            if (! in_array(
                \App\Models\Concerns\RejectsParentCycles::class,
                class_uses_recursive($class),
                true
            )) {
                $unguarded[] = $class;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These models project a parent edge into the graph with nothing stopping a cycle: '
                .implode(', ', $unguarded)
        );
    }

    #[Test]
    public function the_guard_reads_the_column_each_model_actually_uses(): void
    {
        // Risk spells it parent_risk_id. A guard watching `parent_id` on that
        // table would watch a column that does not exist and pass everything.
        foreach (ObjectSourceMap::all() as $class => $spec) {
            if ($spec['parent'] === null) {
                continue;
            }

            $this->assertSame(
                $spec['parent'],
                (new $class)->parentCycleColumn(),
                $class.' guards a different column than the one it projects.'
            );
        }
    }

    #[Test]
    public function a_risk_cannot_be_filed_under_its_own_sub_risk(): void
    {
        $parent = $this->makeRisk();
        $child = $this->makeRisk(['parent_risk_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        $parent->forceFill(['parent_risk_id' => $child->id])->save();
    }

    #[Test]
    public function a_risk_category_cannot_be_filed_under_its_own_sub_category(): void
    {
        $parent = RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'CAT-P',
            'name' => 'Operational',
        ]);
        $child = RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'CAT-C',
            'name' => 'Process',
            'parent_id' => $parent->id,
        ]);

        $this->expectException(ValidationException::class);

        $parent->forceFill(['parent_id' => $child->id])->save();
    }

    #[Test]
    public function an_entity_cannot_be_filed_under_its_own_sub_entity(): void
    {
        $parent = $this->makeEntity('ENT-P', 'Head Office');
        $child = $this->makeEntity('ENT-C', 'Lagos Branch', $parent);

        $this->expectException(ValidationException::class);

        $parent->forceFill(['parent_id' => $child->id])->save();
    }

    #[Test]
    public function the_objects_table_refuses_a_ring_written_through_it_directly(): void
    {
        // `parent_id` is writable on the `objects` API resource, which is a
        // way to close a ring in the index without touching a typed table.
        $parent = $this->makeUnit('BU-G1', 'Group');
        $child = $this->makeUnit('BU-G2', 'Subsidiary', $parent);

        $parentObject = $this->objectFor($parent);
        $childObject = $this->objectFor($child);

        $this->expectException(ValidationException::class);

        $parentObject->update(['parent_id' => $childObject->id]);
    }

    #[Test]
    public function the_projection_can_still_mirror_a_typed_row_the_guard_would_refuse(): void
    {
        // ObjectSyncService writes the index with saveQuietly(), which skips
        // model events. That is load-bearing: the mirror must reflect whatever
        // the typed table holds, including a legacy ring, or a graph sync
        // failure would start failing domain saves.
        $unit = $this->makeUnit('BU-Q', 'Quiet');

        $object = $this->objectFor($unit);
        $object->parent_id = $object->id;
        $object->saveQuietly();

        $this->assertSame($object->id, (int) $object->fresh()->parent_id);
    }

    /* ------------------------------------------------------------------ */
    /*  2. A cycle that got in anyway does not take the process down */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_graph_projection_stops_on_a_ring_it_did_not_create(): void
    {
        $parent = $this->makeUnit('BU-X', 'Corporate');
        $child = $this->makeUnit('BU-Y', 'Trade Finance', $parent);

        // Straight past the model guard, the way a legacy row or a hand-run
        // UPDATE gets in. Both the typed row AND its graph node are made
        // cyclic, because the projection walks `objects.parent_id`.
        DB::table('business_units')->where('id', $parent->id)->update(['parent_id' => $child->id]);

        $childObject = $this->objectFor($child);
        $parentObject = $this->objectFor($parent);
        DB::table('objects')->where('id', $parentObject->id)->update(['parent_id' => $childObject->id]);

        $before = memory_get_usage(true);

        // The walk that used to run until the 512 MB limit killed it.
        app(ObjectSyncService::class)->applyHierarchy(GraphObject::query()->findOrFail($parentObject->id));

        $this->assertLessThan(
            64 * 1024 * 1024,
            memory_get_usage(true) - $before,
            'The hierarchy walk grew the heap; the visited set is not bounding it.'
        );

        // Bounded, so a path holds each id at most twice — once on the way
        // round the ring, once where the walk stopped. Unbounded it grew a
        // segment per pass without end.
        $path = (string) DB::table('objects')->where('id', $parentObject->id)->value('hierarchy_path');
        $this->assertLessThanOrEqual(4, substr_count($path, '/'), "Path grew unbounded: {$path}");
    }

    #[Test]
    public function a_save_on_a_row_that_is_already_cyclic_still_returns(): void
    {
        $parent = $this->makeUnit('BU-M', 'Markets');
        $child = $this->makeUnit('BU-N', 'Sales', $parent);

        DB::table('business_units')->where('id', $parent->id)->update(['parent_id' => $child->id]);
        DB::table('objects')
            ->where('id', $this->objectFor($parent)->id)
            ->update(['parent_id' => $this->objectFor($child)->id]);

        // A rename on a row whose tree is already a ring. The parent column is
        // untouched, so the model guard has nothing to say and the save has to
        // reach the projection — which is the layer being asserted on.
        $stale = BusinessUnit::query()->findOrFail($parent->id);
        $stale->name = 'Markets & Treasury';
        $stale->save();

        $this->assertSame('Markets & Treasury', $parent->fresh()->name);
    }

    /* ------------------------------------------------------------------ */

    private function makeUnit(string $code, string $name, ?BusinessUnit $parent = null): BusinessUnit
    {
        return BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'parent_id' => $parent?->id,
        ]);
    }

    private function makeEntity(string $code, string $name, ?Entity $parent = null): Entity
    {
        $type = EntityType::firstOrCreate(
            ['organization_id' => $this->organization->id, 'code' => 'BRANCH'],
            ['name' => 'Branch', 'level' => 1]
        );

        return Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $type->id,
            'entity_code' => $code,
            'name' => $name,
            'status' => 'active',
            'parent_id' => $parent?->id,
        ]);
    }

    private function objectFor(BusinessUnit $unit): GraphObject
    {
        return GraphObject::query()
            ->where('source_model_type', 'business_unit')
            ->where('source_model_id', $unit->id)
            ->firstOrFail();
    }
}
