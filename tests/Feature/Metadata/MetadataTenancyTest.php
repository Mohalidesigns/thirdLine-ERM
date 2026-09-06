<?php

namespace Tests\Feature\Metadata;

use App\Models\ObjectLifecycle;
use App\Models\ObjectType;
use App\Models\Organization;
use App\Models\WorkflowDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The metadata registry belongs to somebody.
 *
 * ObjectType, ObjectLifecycle and ObjectRelationshipType all use
 * BelongsToOrganization with `$tenantIncludesGlobal = true`: a tenant sees the
 * seeded system rows and its own, and nobody else's. The four Livewire
 * builders carried seven `exists:object_types,id` and `exists:object_lifecycles,id`
 * rules between them — the bare form, which sees every row in the table — so a
 * tenant could point a parent type, an allowed child, a default lifecycle, a
 * link field's target or a relationship's endpoints at another institution's
 * custom type.
 *
 * A lifecycle state's `required_workflow_id` was worse: it had no rule at all.
 * WorkflowDefinition is strictly tenant-scoped, so a state could demand another
 * institution's workflow before a record could move — a transition nobody in
 * either organisation could ever complete.
 *
 * Each test below posts a foreign id at the screen and asserts the field is
 * refused by name, so a later refactor that reaches for `exists:` again fails
 * here rather than in a customer's configuration.
 */
class MetadataTenancyTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private Organization $foreign;

    private ObjectType $foreignType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');

        $this->foreign = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHB6',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->foreignType = ObjectType::withoutGlobalScopes()->create([
            'organization_id' => $this->foreign->id,
            'code' => 'TheirType',
            'name' => 'Their Type',
            'category' => 'governance',
            'is_system' => false,
        ]);

        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Object types */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_type_cannot_inherit_from_another_institutions_type(): void
    {
        $this->post(route('admin.builder.object-types.store'), [
            'name' => 'Mine',
            'code' => 'Mine',
            'category' => 'governance',
            'parent_type_id' => $this->foreignType->id,
        ])->assertSessionHasErrors('parent_type_id');

        $this->assertDatabaseMissing('object_types', ['code' => 'Mine']);
    }

    #[Test]
    public function another_institutions_type_cannot_be_an_allowed_child(): void
    {
        $this->post(route('admin.builder.object-types.store'), [
            'name' => 'Mine',
            'code' => 'Mine',
            'category' => 'governance',
            'allowed_child_type_ids' => [$this->foreignType->id],
        ])->assertSessionHasErrors('allowed_child_type_ids.0');
    }

    #[Test]
    public function another_institutions_lifecycle_cannot_be_the_default(): void
    {
        $foreignLifecycle = ObjectLifecycle::withoutGlobalScopes()->create([
            'organization_id' => $this->foreign->id,
            'object_type_id' => $this->foreignType->id,
            'code' => 'theirs',
            'name' => 'Theirs',
            'states' => [
                ['code' => 'a', 'name' => 'A', 'is_initial' => true, 'is_terminal' => false, 'allowed_transitions' => ['b']],
                ['code' => 'b', 'name' => 'B', 'is_initial' => false, 'is_terminal' => true, 'allowed_transitions' => []],
            ],
            'is_system' => false,
        ]);

        $this->post(route('admin.builder.object-types.store'), [
            'name' => 'Mine',
            'code' => 'Mine',
            'category' => 'governance',
            'default_lifecycle_id' => $foreignLifecycle->id,
        ])->assertSessionHasErrors('default_lifecycle_id');
    }

    #[Test]
    public function a_soft_deleted_type_is_not_a_valid_target(): void
    {
        // `exists:` never excluded soft-deleted rows, and ObjectType soft
        // deletes — so a type somebody had already removed stayed selectable.
        $mine = $this->makeTenantType(['code' => 'Doomed', 'name' => 'Doomed']);
        $mine->delete();

        $this->post(route('admin.builder.object-types.store'), [
            'name' => 'Mine',
            'code' => 'Mine',
            'category' => 'governance',
            'parent_type_id' => $mine->id,
        ])->assertSessionHasErrors('parent_type_id');
    }

    #[Test]
    public function a_seeded_type_is_still_a_valid_target(): void
    {
        // The tenant-bound rule must not lock tenants out of the shared
        // registry: system rows carry a NULL organization_id and belong to
        // everybody, which is what `$tenantIncludesGlobal` means.
        $this->post(route('admin.builder.object-types.store'), [
            'name' => 'Mine',
            'code' => 'Mine',
            'category' => 'governance',
            'parent_type_id' => ObjectType::resolve('Risk')->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('object_types', ['code' => 'Mine']);
    }

    /* ------------------------------------------------------------------ */
    /*  Fields and edges */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_link_field_cannot_point_at_another_institutions_type(): void
    {
        $mine = $this->makeTenantType();

        $this->post(route('admin.builder.attributes.store', $mine->id), [
            'code' => 'linked',
            'label' => 'Linked',
            'data_type' => 'object_ref',
            'ref_object_type_id' => $this->foreignType->id,
        ])->assertSessionHasErrors('ref_object_type_id');
    }

    #[Test]
    public function an_edge_cannot_be_constrained_to_another_institutions_type(): void
    {
        $this->post(route('admin.builder.relationship-types.store'), [
            'name' => 'Touches',
            'code' => 'touches',
            'cardinality' => 'many_to_many',
            'from_type_ids' => [$this->foreignType->id],
        ])->assertSessionHasErrors('from_type_ids.0');
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle states */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_state_cannot_demand_another_institutions_workflow(): void
    {
        $mine = $this->makeTenantType();

        $foreignWorkflow = WorkflowDefinition::withoutGlobalScopes()->create([
            'organization_id' => $this->foreign->id,
            'code' => 'their_flow',
            'name' => 'Their flow',
            'entity_type' => 'risk',
            'definition' => ['nodes' => [], 'edges' => []],
            'is_active' => true,
        ]);

        $this->post(route('admin.builder.lifecycles.store'), [
            'object_type_id' => $mine->id,
            'code' => 'mine',
            'name' => 'Mine',
            'states' => [
                ['code' => 'a', 'name' => 'A', 'is_initial' => true, 'is_terminal' => false,
                    'allowed_transitions' => ['b'], 'required_workflow_id' => $foreignWorkflow->id],
                ['code' => 'b', 'name' => 'B', 'is_initial' => false, 'is_terminal' => true,
                    'allowed_transitions' => []],
            ],
        ])->assertSessionHasErrors('states.0.required_workflow_id');

        $this->assertDatabaseMissing('object_lifecycles', ['code' => 'mine']);
    }

    #[Test]
    public function a_state_cannot_demand_a_permission_that_does_not_exist(): void
    {
        // An invented permission fails closed — the transition refuses
        // everybody — which is the safe direction and a terrible experience.
        // The form offers Permission::all(); the validator now accepts those.
        $mine = $this->makeTenantType();

        $this->post(route('admin.builder.lifecycles.store'), [
            'object_type_id' => $mine->id,
            'code' => 'mine',
            'name' => 'Mine',
            'states' => [
                ['code' => 'a', 'name' => 'A', 'is_initial' => true, 'is_terminal' => false,
                    'allowed_transitions' => ['b'], 'required_permission' => 'not.a.permission'],
                ['code' => 'b', 'name' => 'B', 'is_initial' => false, 'is_terminal' => true,
                    'allowed_transitions' => []],
            ],
        ])->assertSessionHasErrors('states.0.required_permission');
    }

    #[Test]
    public function a_lifecycle_cannot_be_attached_to_another_institutions_type(): void
    {
        $this->post(route('admin.builder.lifecycles.store'), [
            'object_type_id' => $this->foreignType->id,
            'code' => 'mine',
            'name' => 'Mine',
            'states' => [
                ['code' => 'a', 'name' => 'A', 'is_initial' => true, 'is_terminal' => false,
                    'allowed_transitions' => ['b']],
                ['code' => 'b', 'name' => 'B', 'is_initial' => false, 'is_terminal' => true,
                    'allowed_transitions' => []],
            ],
        ])->assertSessionHasErrors('object_type_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Nested bindings */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_field_cannot_be_edited_through_another_types_url(): void
    {
        // ObjectAttribute carries no organization_id and no tenancy trait, so
        // route-model binding resolves it unscoped.
        $typeA = $this->makeTenantType(['code' => 'TypeA', 'name' => 'Type A']);
        $typeB = $this->makeTenantType(['code' => 'TypeB', 'name' => 'Type B']);

        $attribute = \App\Models\ObjectAttribute::create([
            'object_type_id' => $typeA->id,
            'code' => 'field',
            'label' => 'Field',
            'data_type' => 'string',
        ]);

        $this->put(route('admin.builder.attributes.update', [$typeB->id, $attribute->id]), [
            'code' => 'field',
            'label' => 'Renamed through the wrong door',
            'data_type' => 'string',
        ])->assertNotFound();

        $this->assertSame('Field', $attribute->fresh()->label);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTenantType(array $attributes = []): ObjectType
    {
        return ObjectType::create(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'MyType',
            'name' => 'My Type',
            'category' => 'governance',
            'is_system' => false,
        ], $attributes));
    }
}
