<?php

namespace Tests\Feature\Graph;

use App\Models\BusinessUnit;
use App\Models\GraphObject;
use App\Models\KeyRiskIndicator;
use App\Models\ObjectRelationship;
use App\Models\Organization;
use App\Models\RiskControlMapping;
use App\Models\RiskKriMapping;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use App\Support\Graph\PivotRelationshipMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The object graph stays current without anyone remembering to keep it so.
 *
 * Before this, `object_relationships` had exactly one writer — the backfill —
 * so the graph was a photograph of the last migration. Every link made through
 * the product afterwards lived in the typed pivot and nowhere else, and the
 * network widget rendered a stale neighbourhood while looking perfectly well.
 *
 * These tests pin the projection at the level it now happens: the pivot MODEL.
 * Nothing here calls the projector directly, because the property being
 * asserted is that you cannot write the pivot without also writing the edge.
 */
class PivotEdgeProjectionTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private int $kriSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDomainFixtures();
    }

    #[Test]
    public function creating_a_mapping_through_the_model_writes_the_mitigates_edge(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Dual authorisation covers the approval step',
            'control_weight' => 0.65,
            'is_key_control' => true,
        ]);

        $edges = ObjectRelationship::ofType('mitigates')->get();

        $this->assertCount(1, $edges, 'A pivot write must project exactly one edge');

        $edge = $edges->first();

        // Direction is the same one the backfill uses: the control is the
        // actor, so it is the FROM side. A runtime hook that disagreed with
        // the migrator would give a graph whose shape depended on when a row
        // happened to be written.
        $this->assertSame($control->graphObject()->id, $edge->from_object_id);
        $this->assertSame($risk->graphObject()->id, $edge->to_object_id);
        $this->assertSame($this->organization->id, $edge->organization_id);
        $this->assertSame(0.65, (float) $edge->weight);
        $this->assertTrue($edge->attributes['is_key_control']);
        $this->assertSame('Dual authorisation covers the approval step', $edge->attributes['mapping_rationale']);
    }

    #[Test]
    public function attaching_through_the_relation_projects_too(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        // belongsToMany::attach() writes through the query builder unless the
        // relation declares using() — which is the hole that would have left
        // every sync()-driven screen out of the graph.
        $risk->controls()->attach($control->id, [
            'control_weight' => 0.4,
            'is_key_control' => false,
            'mapping_rationale' => 'fixture',
        ]);

        $this->assertSame(1, ObjectRelationship::ofType('mitigates')->count());
    }

    #[Test]
    public function deleting_the_mapping_removes_the_edge(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $mapping = RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Linked during control creation',
        ]);

        $this->assertSame(1, ObjectRelationship::ofType('mitigates')->count());

        // This is the path ControlController::unlinkFromRisk takes.
        $mapping->delete();

        $this->assertSame(0, ObjectRelationship::ofType('mitigates')->count());
        // The endpoints themselves survive: unlinking two things is not
        // deleting either of them.
        $this->assertNotNull($control->graphObject());
        $this->assertNotNull($risk->graphObject());
    }

    #[Test]
    public function detaching_through_the_relation_removes_the_edge(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $this->attachControl($risk, $control, 0.5, true);
        $this->assertSame(1, ObjectRelationship::ofType('mitigates')->count());

        $risk->controls()->detach($control->id);

        $this->assertSame(0, ObjectRelationship::ofType('mitigates')->count());
    }

    #[Test]
    public function asserting_the_same_mapping_twice_does_not_produce_two_edges(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'First assertion',
            'control_weight' => 0.5,
        ]);

        // The unique index on (risk_id, control_id) stops a genuine duplicate
        // row, so the realistic double-write is the row being re-saved — an
        // importer replaying a file, or the backfill running over rows the
        // hook already projected.
        DB::table('risk_control_mapping')->delete();

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Second assertion',
            'control_weight' => 0.9,
        ]);

        $edges = ObjectRelationship::ofType('mitigates')->get();

        $this->assertCount(1, $edges, 'relate() is updateOrCreate, not insert');
        $this->assertSame(0.9, (float) $edges->first()->weight);
        $this->assertSame('Second assertion', $edges->first()->attributes['mapping_rationale']);
    }

    #[Test]
    public function the_backfill_stays_idempotent_alongside_the_hook(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Written at runtime',
            'control_weight' => 0.25,
        ]);

        (new PivotRelationshipMigrator)->run();
        (new PivotRelationshipMigrator)->run();

        // The two writers share PivotEdgeMap, so the backfill recognises the
        // hook's edge as the same edge rather than writing a second one.
        $this->assertSame(1, ObjectRelationship::ofType('mitigates')->count());
    }

    #[Test]
    public function a_kri_mapping_projects_a_monitored_by_edge(): void
    {
        $risk = $this->makeRisk();
        $kri = $this->makeKri();

        RiskKriMapping::create([
            'risk_id' => $risk->id,
            'kri_id' => $kri->id,
            'correlation_type' => 'leading',
        ]);

        $edge = ObjectRelationship::ofType('monitored_by')->first();

        $this->assertNotNull($edge, 'risk_kri_mapping had no model at all, so nothing could hook it');
        $this->assertSame($risk->graphObject()->id, $edge->from_object_id);
        $this->assertSame($kri->graphObject()->id, $edge->to_object_id);
        $this->assertSame('leading', $edge->attributes['correlation_type']);
    }

    /* ------------------------------------------------------------------ */
    /*  Missing object identities */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_endpoint_with_no_object_row_has_its_identity_created(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        // A record that predates HasObjectIdentity, or whose sync failed and
        // was never repaired, has a typed row and no graph node.
        GraphObject::query()
            ->where('source_model_type', 'control')
            ->where('source_model_id', $control->id)
            ->forceDelete();

        $this->assertSame(0, GraphObject::query()
            ->where('source_model_type', 'control')
            ->where('source_model_id', $control->id)
            ->count());

        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Mapped before the node existed',
        ]);

        // POLICY: create the identity, do not skip. Skipping would leave a
        // pivot row with no edge and nothing to retry it, which is the silent
        // drift the projection exists to end.
        $recreated = GraphObject::query()
            ->where('source_model_type', 'control')
            ->where('source_model_id', $control->id)
            ->first();

        $this->assertNotNull($recreated, 'The endpoint identity should have been created on demand');
        $this->assertSame(1, ObjectRelationship::ofType('mitigates')->count());
        $this->assertSame($recreated->id, ObjectRelationship::ofType('mitigates')->first()->from_object_id);
    }

    #[Test]
    public function an_endpoint_that_cannot_be_resolved_is_skipped_without_throwing(): void
    {
        $risk = $this->makeRisk();
        $control = $this->makeControl();

        $otherOrg = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        // The control now belongs to somebody else, so it is invisible to this
        // tenant — the shape a cross-tenant or orphaned pivot row takes. The
        // update goes through the query builder because moving a record
        // between tenants is not something the model is allowed to do.
        DB::table('controls')->where('id', $control->id)->update(['organization_id' => $otherOrg->id]);

        $mapping = RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Points at something this tenant cannot see',
        ]);

        // POLICY: no throw, and no half-edge. Writing an edge to an endpoint
        // we cannot resolve would mean inventing one; refusing the pivot write
        // would mean a degraded index failing a domain write. So: skip, log,
        // and let the backfill count it as unresolved.
        $this->assertTrue($mapping->exists);
        $this->assertDatabaseCount('risk_control_mapping', 1);
        $this->assertSame(0, ObjectRelationship::ofType('mitigates')->count());

        // And deleting it is equally quiet.
        $mapping->delete();
        $this->assertSame(0, ObjectRelationship::ofType('mitigates')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  End to end: the widget that was showing a stale graph */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_network_widget_sees_a_link_created_at_runtime(): void
    {
        foreach (['risk.view', 'control.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->actor->givePermissionTo(['risk.view', 'control.view']);

        $unit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'code' => 'BU-NET',
            'name' => 'Network Test Unit',
            'is_active' => true,
        ]);

        $risk = $this->makeRisk(['business_unit_id' => $unit->id]);
        $control = $this->makeControl(['business_unit_id' => $unit->id]);

        // No backfill is run anywhere in this test. This is the link a user
        // makes today.
        RiskControlMapping::create([
            'risk_id' => $risk->id,
            'control_id' => $control->id,
            'mapping_rationale' => 'Created after the last backfill',
            'control_weight' => 1.0,
            'is_key_control' => true,
        ]);

        $definition = WidgetDefinition::create([
            'organization_id' => $this->organization->id,
            'code' => 'network-projection-test',
            'name' => 'Neighbourhood',
            'widget_type' => 'network',
            'context_binding' => 'inherit_subtree',
            'period_binding' => 'selected',
            'query' => ['source' => 'risks'],
        ]);

        $rendered = app(WidgetDataService::class)->render(
            $definition,
            new WidgetContext($this->actor, $unit->graphObject()),
        );

        $this->assertSame('ok', $rendered['state'] ?? null, 'The widget should render');

        $edges = collect($rendered['data']['edges'] ?? []);

        $this->assertTrue(
            $edges->contains(fn (array $edge) => $edge['code'] === 'mitigates'
                && $edge['from'] === $control->graphObject()->id
                && $edge['to'] === $risk->graphObject()->id),
            'The network widget did not draw the mitigates link created at runtime'
        );
    }

    /* ------------------------------------------------------------------ */

    private function makeKri(): KeyRiskIndicator
    {
        $n = ++$this->kriSequence;

        return KeyRiskIndicator::create([
            'organization_id' => $this->organization->id,
            'kri_code' => 'KRI-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Failed reconciliations per week '.$n,
            'measurement_frequency' => 'weekly',
            'is_active' => true,
        ]);
    }
}
