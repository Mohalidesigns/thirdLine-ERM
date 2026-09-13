<?php

namespace Tests\Feature\Scoping;

use App\Models\Entity;
use App\Models\RiskAuditTrail;
use App\Support\Migration\Ported;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3.1 — the four scoping pages render through Inertia with the props
 * the React pages read, and the writes go through the Form Requests and
 * EntityService.
 */
class EntityPagesTest extends ScopingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actor->givePermissionTo(['entity.view', 'entity.create', 'entity.edit', 'entity.delete', 'risk.view']);
        $this->actingAs($this->actor);
    }

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_dashboard_renders_its_figures_tree_and_tables(): void
    {
        $this->makeRisk(['entity_id' => $this->branch->id, 'inherent_rating' => 'Critical']);
        $this->makeRisk(['entity_id' => $this->branch->id, 'inherent_rating' => 'Low', 'last_assessment_date' => now()]);

        $this->get(route('risk.scoping.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Dashboard')
                ->where('kpis.totalEntities', 4)
                ->where('kpis.activeOwners', 0)
                ->where('kpis.exceedingAppetite', null)
                ->where('kpis.pendingAssessments', 1)
                ->has('tree', 1)
                ->where('tree.0.name', 'Group HQ')
                ->where('tree.0.depth', 0)
                ->where('tree.0.level', 0)
                ->has('tree.0.children', 2)
                ->where('tree.0.children.0.name', 'Retail Banking')
                ->where('tree.0.children.0.children.0.name', 'Lagos Island Branch')
                ->where('tree.0.children.0.children.0.depth', 2)
                ->where('tree.0.children.0.children.0.type', 'Branch')
                ->has('heatmap', 4)
                ->where('heatmap.0.name', 'Lagos Island Branch')
                ->where('heatmap.0.risk_total', 2)
                ->where('heatmap.0.critical_count', 1)
                ->where('heatmap.0.low_count', 1)
                ->where('heatmap.0.risk_score', 3.5)
                ->where('heatmap.0.status', 'active')
                ->has('typeDistribution', 3)
                ->where('typeDistribution.0', ['name' => 'Group', 'value' => 1])
                ->where('typeDistribution.1', ['name' => 'Business Unit', 'value' => 2])
                ->has('recentActivity', 4)
                ->has('recentActivity.0.updated_human'));
    }

    #[Test]
    public function a_pinned_user_sees_only_their_subtree_on_the_dashboard(): void
    {
        $pinned = $this->userWith(['entity.view', 'risk.view'], $this->retail);

        $this->actingAs($pinned)->get(route('risk.scoping.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.totalEntities', 2)
                ->has('tree', 1)
                ->where('tree.0.name', 'Retail Banking')
                ->where('tree.0.depth', 0)
                ->has('tree.0.children', 1)
                ->has('heatmap', 2)
                ->where('typeDistribution.0', ['name' => 'Group', 'value' => 0]));
    }

    /* ------------------------------------------------------------------ */
    /*  Create / store */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_create_page_carries_the_lookups_and_a_schema_per_entity_type(): void
    {
        $this->get(route('risk.scoping.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Create')
                ->has('entityTypes', 3)
                ->where('entityTypes.0.name', 'Group')
                ->where('entityTypes.0.level', 0)
                ->has('parentEntities', 4)
                ->has('users', 1)
                ->where('users.0.name', 'Risk Officer')
                ->where('frameworks', ['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'BOFIA', 'SEC Rules'])
                ->has('appetiteLevels', 5)
                ->has('appetiteCategories', 5)
                ->has('schemas.'.$this->branchType->id.'.sections'));
    }

    #[Test]
    public function storing_creates_the_entity_with_a_generated_code_and_the_types_level(): void
    {
        $response = $this->post(route('risk.scoping.store'), $this->validPayload());

        $entity = Entity::query()->where('name', 'Abuja Central Branch')->firstOrFail();

        $response->assertRedirect(route('risk.scoping.show', $entity))
            ->assertSessionHas('success');

        $this->assertMatchesRegularExpression('/^ENT-\d{4}$/', $entity->entity_code);
        $this->assertSame(2, $entity->level);
        $this->assertSame($this->retail->id, $entity->parent_id);
        $this->assertSame($this->actor->id, $entity->created_by);
        $this->assertSame($this->organization->id, $entity->organization_id);
        $this->assertSame(['CBN ORMS'], $entity->regulatory_frameworks);
        // Unset category appetites are dropped, not stored as nulls.
        $this->assertSame(['credit' => 'averse'], $entity->category_appetites);
        $this->assertStringStartsWith($this->retail->hierarchy_path, $entity->hierarchy_path);

        $this->assertDatabaseHas('risk_audit_trail', [
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->id,
            'action_type' => 'create',
        ]);
    }

    #[Test]
    public function storing_without_the_create_permission_is_refused(): void
    {
        $viewer = $this->userWith(['entity.view']);

        $this->actingAs($viewer)->post(route('risk.scoping.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->get(route('risk.scoping.create'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_detail_page_renders_the_entity_its_posture_and_its_lists(): void
    {
        $this->retail->update(['owner_id' => $this->actor->id, 'regulatory_frameworks' => ['NDPA', 'BOFIA'], 'risk_appetite_level' => 'open']);
        $risk = $this->makeRisk(['entity_id' => $this->retail->id, 'inherent_rating' => 'High', 'inherent_score' => 12]);
        $this->makeRisk(['entity_id' => $this->branch->id, 'inherent_rating' => 'Critical']);

        $this->get(route('risk.scoping.show', $this->retail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Show')
                ->where('entity.id', $this->retail->id)
                ->where('entity.name', 'Retail Banking')
                ->where('entity.type', 'Business Unit')
                ->where('entity.level', 1)
                ->where('entity.parent.name', 'Group HQ')
                ->where('entity.owner', 'Risk Officer')
                ->where('entity.regulatory_frameworks', ['NDPA', 'BOFIA'])
                ->where('entity.risk_appetite_level', 'open')
                ->where('riskStats', ['total' => 1, 'critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0])
                ->has('risks', 1)
                ->where('risks.0.code', $risk->risk_code)
                ->where('risks.0.rating', 'High')
                ->where('risks.0.category', 'Operational Risk')
                ->where('risks.0.url', route('risk.register.show', $risk))
                ->has('issues', 0)
                ->has('kris', 0)
                ->has('subEntities', 1)
                ->where('subEntities.0.name', 'Lagos Island Branch')
                ->where('subEntities.0.risks_count', 1)
                ->has('subEntityHeatmap', 1)
                ->where('subEntityHeatmap.0.critical_count', 1)
                ->where('subEntityHeatmap.0.risk_score', 5)
                ->where('categoryDistribution', [['name' => 'Operational Risk', 'value' => 1]])
                ->has('configured.sections')
                ->where('can.update', true)
                ->where('can.delete', true)
                ->where('can.create', true));
    }

    #[Test]
    public function a_viewer_sees_no_edit_or_delete_affordance(): void
    {
        $viewer = $this->userWith(['entity.view']);

        $this->actingAs($viewer)->get(route('risk.scoping.show', $this->retail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.update', false)
                ->where('can.delete', false)
                ->where('can.create', false));
    }

    #[Test]
    public function another_tenants_entity_is_not_found(): void
    {
        $this->get(route('risk.scoping.show', $this->foreign))->assertNotFound();
        $this->get(route('risk.scoping.edit', $this->foreign))->assertNotFound();
        $this->put(route('risk.scoping.update', $this->foreign), $this->validPayload())->assertNotFound();
        $this->delete(route('risk.scoping.destroy', $this->foreign))->assertNotFound();

        $this->assertDatabaseHas('entities', ['id' => $this->foreign->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_pinned_user_is_refused_an_entity_outside_their_subtree(): void
    {
        $pinned = $this->userWith(['entity.view', 'entity.edit', 'entity.delete'], $this->retail);

        $this->actingAs($pinned)->get(route('risk.scoping.show', $this->branch))->assertOk();
        $this->actingAs($pinned)->get(route('risk.scoping.show', $this->treasury))->assertForbidden();
        $this->actingAs($pinned)->get(route('risk.scoping.edit', $this->treasury))->assertForbidden();
        $this->actingAs($pinned)->delete(route('risk.scoping.destroy', $this->group))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /*  Edit / update */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_edit_page_offers_no_parent_that_would_make_a_cycle(): void
    {
        $this->get(route('risk.scoping.edit', $this->retail))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scoping/Edit')
                ->where('entity.id', $this->retail->id)
                ->where('entity.entity_type_id', $this->unitType->id)
                ->where('entity.parent_id', $this->group->id)
                // Not itself, not its own branch — Group HQ and Treasury only.
                ->has('parentEntities', 2)
                ->where('parentEntities.0.name', 'Group HQ')
                ->where('parentEntities.1.name', 'Treasury')
                ->has('schemas.'.$this->unitType->id));
    }

    #[Test]
    public function updating_changes_the_entity_and_records_the_change(): void
    {
        $this->put(route('risk.scoping.update', $this->branch), $this->validPayload([
            'name' => 'Lagos Island Branch (renamed)',
            'status' => 'archived',
            'parent_id' => $this->treasury->id,
        ]))
            ->assertRedirect(route('risk.scoping.show', $this->branch))
            ->assertSessionHas('success');

        $this->branch->refresh();

        $this->assertSame('Lagos Island Branch (renamed)', $this->branch->name);
        $this->assertSame('archived', $this->branch->status);
        $this->assertSame($this->treasury->id, $this->branch->parent_id);
        $this->assertStringStartsWith($this->treasury->fresh()->hierarchy_path, $this->branch->hierarchy_path);

        $this->assertTrue(RiskAuditTrail::query()->where('entity_id', $this->branch->id)->where('action_type', 'update')->exists());
    }

    #[Test]
    public function an_entity_cannot_be_its_own_parent_or_a_descendants_child(): void
    {
        $this->from(route('risk.scoping.edit', $this->retail))
            ->put(route('risk.scoping.update', $this->retail), $this->validPayload(['entity_type_id' => $this->unitType->id, 'parent_id' => $this->retail->id]))
            ->assertRedirect(route('risk.scoping.edit', $this->retail))
            ->assertSessionHasErrors(['parent_id' => 'An entity cannot be its own parent.']);

        $this->from(route('risk.scoping.edit', $this->retail))
            ->put(route('risk.scoping.update', $this->retail), $this->validPayload(['entity_type_id' => $this->unitType->id, 'parent_id' => $this->branch->id]))
            ->assertSessionHasErrors('parent_id');

        $this->assertSame($this->group->id, $this->retail->fresh()->parent_id);
    }

    #[Test]
    public function archived_is_a_status_on_update_but_not_on_create(): void
    {
        $this->post(route('risk.scoping.store'), $this->validPayload(['status' => 'archived']))
            ->assertSessionHasErrors('status');

        $this->put(route('risk.scoping.update', $this->branch), $this->validPayload(['status' => 'archived']))
            ->assertSessionHasNoErrors();
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function deleting_is_refused_while_the_entity_has_sub_entities_or_risks(): void
    {
        $this->from(route('risk.scoping.show', $this->retail))
            ->delete(route('risk.scoping.destroy', $this->retail))
            ->assertRedirect(route('risk.scoping.show', $this->retail))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 sub-entities'));

        $this->makeRisk(['entity_id' => $this->treasury->id]);

        $this->from(route('risk.scoping.show', $this->treasury))
            ->delete(route('risk.scoping.destroy', $this->treasury))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 linked risks'));

        $this->assertDatabaseHas('entities', ['id' => $this->retail->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('entities', ['id' => $this->treasury->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_leaf_entity_is_deleted_and_the_user_lands_on_the_register(): void
    {
        $this->delete(route('risk.scoping.destroy', $this->branch))
            ->assertRedirect(route('risk.scoping.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('entities', ['id' => $this->branch->id]);
    }

    /* ------------------------------------------------------------------ */
    /*  Migration bookkeeping */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_blade_views_are_gone_and_the_routes_are_marked_ported(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/risk/scoping'));

        foreach (['risk.scoping.dashboard', 'risk.scoping.create', 'risk.scoping.show', 'risk.scoping.edit'] as $route) {
            $this->assertTrue(Ported::isRoute($route), "{$route} is listed as ported");
        }
    }
}
