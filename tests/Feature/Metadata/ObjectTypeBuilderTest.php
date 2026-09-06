<?php

namespace Tests\Feature\Metadata;

use App\Models\GraphObject;
use App\Models\ObjectAttribute;
use App\Models\ObjectRelationship;
use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-05 TASK 1 acceptance — the object type builder, and the guardrails that
 * stop "configure, don't code" from meaning "delete the schema from a web form".
 *
 * The acceptance criterion is the first test: a new object type with five
 * custom fields, a relationship and a lifecycle, created entirely through the
 * UI with no code change. Everything after it is a way that could go wrong.
 *
 * Migration Phase 6.3 replaced the four Livewire components with pages and
 * ordinary write routes. Every assertion below is the one it was; what changed
 * is that the tests now post to the routes a browser posts to, which is a
 * stricter thing to assert than a component's public properties — the Form
 * Requests and the policies are in the path now, and they were not before.
 *
 * Every write asserts a REDIRECT as well as an empty error bag. On its own,
 * `assertSessionHasNoErrors()` passes on a 500 — an exception puts nothing in
 * the error bag — and during this port it did exactly that, twice, while the
 * write silently never happened.
 */
class ObjectTypeBuilderTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The acceptance scenario */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_new_type_with_five_fields_a_relationship_and_a_lifecycle_is_created_entirely_through_the_ui(): void
    {
        /* ---- 1. the type ---- */

        $this->post(route('admin.builder.object-types.store'), [
            'name' => 'Third Party',
            'code' => 'ThirdParty',
            'category' => 'governance',
            'icon' => 'handshake',
            'code_prefix' => 'TP',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $type = ObjectType::where('code', 'ThirdParty')->first();

        $this->assertNotNull($type);
        $this->assertSame($this->organization->id, $type->organization_id, 'a type built here belongs to its tenant');
        $this->assertFalse($type->is_system, 'only the seeded registry is system');
        $this->assertSame('Third Parties', $type->plural_name, 'the plural is derived when not given');

        /* ---- 2. five fields, one of each interesting kind ---- */

        $fields = [
            ['code' => 'legal_name', 'label' => 'Legal name', 'data_type' => 'string', 'is_required' => true],
            ['code' => 'rc_number', 'label' => 'RC number', 'data_type' => 'string', 'is_unique' => true],
            ['code' => 'criticality', 'label' => 'Criticality', 'data_type' => 'enum',
                'enum_options' => ['Low', 'Medium', 'High']],
            ['code' => 'annual_spend', 'label' => 'Annual spend', 'data_type' => 'money'],
            ['code' => 'holds_customer_data', 'label' => 'Holds customer data', 'data_type' => 'bool', 'is_pii' => true],
        ];

        foreach ($fields as $field) {
            $this->post(route('admin.builder.attributes.store', $type->id), $field)
                ->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertCount(5, $type->fresh()->attributeDefinitions);

        $criticality = ObjectAttribute::where('object_type_id', $type->id)->where('code', 'criticality')->first();
        $this->assertSame(['Low', 'Medium', 'High'], $criticality->enum_options);

        /* ---- 3. a relationship type, constrained to it ---- */

        $this->post(route('admin.builder.relationship-types.store'), [
            'name' => 'Supplied by',
            'code' => 'supplied_by',
            'inverse_code' => 'supplies',
            'cardinality' => 'many_to_many',
            'from_type_ids' => [ObjectType::resolve('Risk')->id],
            'to_type_ids' => [$type->id],
            'has_weight' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $relationship = ObjectRelationshipType::where('code', 'supplied_by')->first();

        $this->assertNotNull($relationship);
        $this->assertTrue($relationship->has_weight, 'weight is what makes graph roll-up arithmetic');
        $this->assertTrue($relationship->permits(ObjectType::resolve('Risk')->id, $type->id));
        $this->assertFalse($relationship->permits($type->id, $type->id), 'the constraint has to actually constrain');

        /* ---- 4. a lifecycle ---- */

        $this->post(route('admin.builder.lifecycles.store'), [
            'object_type_id' => $type->id,
            'name' => 'Third party onboarding',
            'code' => 'third-party-onboarding',
            'states' => [
                ['code' => 'proposed', 'name' => 'Proposed', 'color' => '#3b82f6', 'is_initial' => true,
                    'is_terminal' => false, 'allowed_transitions' => ['due_diligence'],
                    'required_permission' => null, 'required_workflow_id' => null],
                ['code' => 'due_diligence', 'name' => 'Due diligence', 'color' => '#f59e0b', 'is_initial' => false,
                    'is_terminal' => false, 'allowed_transitions' => ['approved', 'rejected'],
                    'required_permission' => null, 'required_workflow_id' => null],
                ['code' => 'approved', 'name' => 'Approved', 'color' => '#22c55e', 'is_initial' => false,
                    'is_terminal' => true, 'allowed_transitions' => [],
                    'required_permission' => 'admin.metadata', 'required_workflow_id' => null],
                ['code' => 'rejected', 'name' => 'Rejected', 'color' => '#ef4444', 'is_initial' => false,
                    'is_terminal' => true, 'allowed_transitions' => [],
                    'required_permission' => null, 'required_workflow_id' => null],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lifecycle = $type->fresh()->lifecycles()->first();

        $this->assertNotNull($lifecycle);
        $this->assertSame('proposed', $lifecycle->initialState()['code']);
        $this->assertTrue($lifecycle->allowsTransition('due_diligence', 'approved'));
        $this->assertFalse($lifecycle->allowsTransition('proposed', 'approved'), 'no skipping due diligence');
        $this->assertSame('admin.metadata', $lifecycle->permissionFor('approved'));
        $this->assertEqualsCanonicalizing(['approved', 'rejected'], $lifecycle->terminalStates());

        // No migration was written, no class was added, no deploy happened.
        $this->assertTrue(true);
    }

    /* ------------------------------------------------------------------ */
    /*  Guardrails */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_system_type_cannot_be_deleted(): void
    {
        $risk = ObjectType::resolve('Risk');

        $this->expectException(ValidationException::class);

        app(MetadataGuard::class)->assertTypeDeletable($risk);
    }

    #[Test]
    public function a_type_with_records_cannot_be_deleted(): void
    {
        $type = $this->makeTenantType();

        $this->makeGraphObject($type, 'A record of that type');

        $this->delete(route('admin.builder.object-types.destroy', $type->id))
            ->assertSessionHasErrors('type');

        $this->assertNotNull($type->fresh(), 'the type should still be there');
    }

    #[Test]
    public function a_type_that_something_inherits_from_cannot_be_deleted(): void
    {
        $parent = $this->makeTenantType(['code' => 'Parent', 'name' => 'Parent']);
        $this->makeTenantType(['code' => 'Child', 'name' => 'Child', 'parent_type_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        app(MetadataGuard::class)->assertTypeDeletable($parent);
    }

    #[Test]
    public function a_type_cannot_be_made_its_own_ancestor(): void
    {
        $a = $this->makeTenantType(['code' => 'TypeA', 'name' => 'Type A']);
        $b = $this->makeTenantType(['code' => 'TypeB', 'name' => 'Type B', 'parent_type_id' => $a->id]);

        // Pointing A at B closes the loop A → B → A, which would spin every
        // attribute resolution in the product.
        $this->put(route('admin.builder.object-types.update', $a->id), [
            'name' => $a->name,
            'code' => $a->code,
            'category' => $a->category,
            'parent_type_id' => $b->id,
        ])->assertSessionHasErrors('parent_type_id');

        $this->assertNull($a->fresh()->parent_type_id);
    }

    #[Test]
    public function a_system_types_code_and_category_are_locked_but_its_presentation_is_not(): void
    {
        $risk = ObjectType::resolve('Risk');

        // The code is posted anyway — a locked field is not a field an
        // attacker cannot type — and the request drops it rather than trusting
        // the form to have disabled the input.
        $this->put(route('admin.builder.object-types.update', $risk->id), [
            'name' => $risk->name,
            'code' => 'RENAMED',
            'category' => 'reference',
            'color' => '#123456',
            'icon' => 'shield',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $risk->fresh();

        $this->assertSame('Risk', $fresh->code, 'the platform resolves system types by code from a dozen places');
        $this->assertSame('governance', $fresh->category, 'nor is its category the tenant\'s to move');
        $this->assertSame('#123456', $fresh->color, 'presentation is the tenant\'s to change');
        $this->assertSame('shield', $fresh->icon);
    }

    /* ------------------------------------------------------------------ */
    /*  The dangerous edit: changing a data type in use */
    /* ------------------------------------------------------------------ */

    private function fieldWithStoredValue(string $dataType, mixed $value): ObjectAttribute
    {
        $type = $this->makeTenantType();

        $attribute = ObjectAttribute::create([
            'object_type_id' => $type->id,
            'code' => 'estimate',
            'label' => 'Estimate',
            'data_type' => $dataType,
        ]);

        $object = $this->makeGraphObject($type, 'A record');

        $object->setCustomAttributes(['estimate' => $value]);
        $object->saveQuietly();

        return $attribute;
    }

    #[Test]
    public function a_lossy_data_type_change_on_a_field_in_use_is_refused(): void
    {
        // "approximately ₦4m" becomes 0 under a naive cast, in every record
        // that ever held it, with no undo.
        $attribute = $this->fieldWithStoredValue('text', 'approximately ₦4m');

        $this->expectException(ValidationException::class);

        app(MetadataGuard::class)->assertDataTypeChangeIsSafe($attribute, 'int');
    }

    #[Test]
    public function a_widening_data_type_change_is_allowed_silently(): void
    {
        $attribute = $this->fieldWithStoredValue('int', 42);

        // int → decimal cannot lose anything, so it needs no ceremony.
        app(MetadataGuard::class)->assertDataTypeChangeIsSafe($attribute, 'decimal');

        $this->assertTrue(true);
    }

    #[Test]
    public function a_data_type_change_on_an_unused_field_is_allowed(): void
    {
        $type = $this->makeTenantType();

        $attribute = ObjectAttribute::create([
            'object_type_id' => $type->id,
            'code' => 'unused',
            'label' => 'Unused',
            'data_type' => 'text',
        ]);

        app(MetadataGuard::class)->assertDataTypeChangeIsSafe($attribute, 'int');

        $this->assertTrue(true);
    }

    #[Test]
    public function the_builder_offers_a_migration_path_rather_than_a_bare_refusal(): void
    {
        $attribute = $this->fieldWithStoredValue('text', 'approximately ₦4m');

        // The warning is available as soon as the type is changed, not at
        // save, when the rest of the form's state would already be lost.
        $impact = $this->getJson(route('admin.builder.attributes.impact', [
            $attribute->object_type_id, $attribute->id,
        ]).'?data_type=int')->assertOk()->json();

        $this->assertTrue($impact['needs_migration_path']);
        $this->assertSame(1, $impact['affected_records']);

        // Saving without choosing a path is still refused.
        $this->put(route('admin.builder.attributes.update', [$attribute->object_type_id, $attribute->id]),
            $this->attributePayload($attribute, ['data_type' => 'int'])
        )->assertSessionHasErrors();

        $this->assertSame('text', $attribute->fresh()->data_type);

        $this->put(route('admin.builder.attributes.update', [$attribute->object_type_id, $attribute->id]),
            $this->attributePayload($attribute, [
                'data_type' => 'int',
                'confirm_lossy_change' => true,
                'migration_strategy' => 'preserve_as_text',
            ])
        )->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('int', $attribute->fresh()->data_type);
    }

    #[Test]
    public function the_clear_migration_path_discards_the_values_deliberately(): void
    {
        $attribute = $this->fieldWithStoredValue('text', 'approximately ₦4m');

        $this->put(route('admin.builder.attributes.update', [$attribute->object_type_id, $attribute->id]),
            $this->attributePayload($attribute, [
                'data_type' => 'int',
                'confirm_lossy_change' => true,
                'migration_strategy' => 'clear',
            ])
        )->assertSessionHasNoErrors()->assertRedirect();

        $object = GraphObject::withoutGlobalScopes()
            ->where('object_type_id', $attribute->object_type_id)->first();

        $this->assertNull($object->customAttributes()['estimate']);
    }

    #[Test]
    public function a_system_attributes_data_type_cannot_be_changed_at_all(): void
    {
        $attribute = ObjectAttribute::where('code', 'control_type')
            ->where('object_type_id', ObjectType::resolve('Control')->id)
            ->firstOrFail();

        $this->expectException(ValidationException::class);

        app(MetadataGuard::class)->assertDataTypeChangeIsSafe($attribute, 'string');
    }

    /* ------------------------------------------------------------------ */
    /*  Deleting a relationship type archives its edges */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function deleting_a_relationship_type_with_instances_requires_confirmation_and_archives_them(): void
    {
        $type = $this->makeTenantType();

        $relationshipType = ObjectRelationshipType::create([
            'organization_id' => $this->organization->id,
            'code' => 'depends_on_vendor',
            'name' => 'Depends on vendor',
            'cardinality' => 'many_to_many',
            'is_system' => false,
        ]);

        $from = $this->makeGraphObject($type, 'From');
        $to = $this->makeGraphObject($type, 'To');

        $edge = ObjectRelationship::create([
            'organization_id' => $this->organization->id,
            'relationship_type_id' => $relationshipType->id,
            'from_object_id' => $from->id,
            'to_object_id' => $to->id,
        ]);

        $guard = app(MetadataGuard::class);

        $this->assertSame(1, $guard->relationshipInstanceCount($relationshipType));

        // Unconfirmed: refused, with a message saying what would happen.
        try {
            $guard->deleteRelationshipType($relationshipType);
            $this->fail('deleting a relationship type with live edges should ask first');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('archives', $error->errors()['relationship_type'][0]);
        }

        $this->assertNull($edge->fresh()->archived_at);

        // Confirmed: the edges are archived, not destroyed. What once mitigated
        // what is exactly the sort of thing an auditor asks about later.
        $archived = $guard->deleteRelationshipType($relationshipType, confirmed: true);

        $this->assertSame(1, $archived);
        $this->assertNotNull($edge->fresh()->archived_at);
        $this->assertSame(0, $guard->relationshipInstanceCount($relationshipType->fresh()));
        $this->assertStringContainsString('archived', $relationshipType->fresh()->name);
    }

    #[Test]
    public function a_system_relationship_type_cannot_be_deleted(): void
    {
        $mitigates = ObjectRelationshipType::where('is_system', true)->firstOrFail();

        $this->expectException(ValidationException::class);

        app(MetadataGuard::class)->deleteRelationshipType($mitigates, confirmed: true);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle coherence */
    /* ------------------------------------------------------------------ */

    public static function incoherentLifecycles(): array
    {
        $state = fn (array $overrides) => array_merge([
            'code' => 'x', 'name' => 'X', 'is_initial' => false, 'is_terminal' => false,
            'allowed_transitions' => [],
        ], $overrides);

        return [
            'no initial state' => [[
                $state(['code' => 'a', 'is_terminal' => true]),
            ]],
            'two initial states' => [[
                $state(['code' => 'a', 'is_initial' => true, 'allowed_transitions' => ['b']]),
                $state(['code' => 'b', 'is_initial' => true, 'is_terminal' => true]),
            ]],
            'no terminal state' => [[
                $state(['code' => 'a', 'is_initial' => true, 'allowed_transitions' => ['b']]),
                $state(['code' => 'b', 'allowed_transitions' => ['a']]),
            ]],
            'transition to a state that does not exist' => [[
                $state(['code' => 'a', 'is_initial' => true, 'allowed_transitions' => ['ghost']]),
                $state(['code' => 'b', 'is_terminal' => true]),
            ]],
            'a state nothing can reach' => [[
                $state(['code' => 'a', 'is_initial' => true, 'allowed_transitions' => ['b']]),
                $state(['code' => 'b', 'is_terminal' => true]),
                $state(['code' => 'orphan', 'is_terminal' => true]),
            ]],
            'duplicate codes' => [[
                $state(['code' => 'a', 'is_initial' => true, 'allowed_transitions' => ['a']]),
                $state(['code' => 'a', 'is_terminal' => true]),
            ]],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('incoherentLifecycles')]
    public function an_incoherent_lifecycle_is_refused(array $states): void
    {
        $this->expectException(ValidationException::class);

        app(MetadataGuard::class)->assertLifecycleIsCoherent($states);
    }

    #[Test]
    public function editing_a_system_lifecycle_forks_it_rather_than_mutating_it(): void
    {
        // The seeded machines describe the state strings already in the domain
        // tables. Editing one in place would silently invalidate live rows.
        $system = \App\Models\ObjectLifecycle::where('is_system', true)->firstOrFail();
        $originalStates = $system->states;

        $this->put(route('admin.builder.lifecycles.update', $system->id), [
            'object_type_id' => $system->object_type_id,
            'code' => $system->code,
            'name' => 'My own version',
            'states' => [
                ['code' => 'open', 'name' => 'Open', 'color' => '#3b82f6', 'is_initial' => true,
                    'is_terminal' => false, 'allowed_transitions' => ['shut'],
                    'required_permission' => null, 'required_workflow_id' => null],
                ['code' => 'shut', 'name' => 'Shut', 'color' => '#6b7280', 'is_initial' => false,
                    'is_terminal' => true, 'allowed_transitions' => [],
                    'required_permission' => null, 'required_workflow_id' => null],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($originalStates, $system->fresh()->states, 'the seeded lifecycle must be untouched');
        $this->assertTrue($system->fresh()->is_system);

        $fork = \App\Models\ObjectLifecycle::where('name', 'My own version')->first();

        $this->assertNotNull($fork);
        $this->assertFalse($fork->is_system);
        $this->assertSame($this->organization->id, $fork->organization_id);
    }

    /* ------------------------------------------------------------------ */
    /*  The pages render, and are guarded */
    /* ------------------------------------------------------------------ */

    public static function builderRoutes(): array
    {
        return [
            'landing' => ['admin.builder', 'admin.metadata'],
            'object types' => ['admin.builder.object-types', 'admin.metadata'],
            'relationship types' => ['admin.builder.relationship-types', 'admin.metadata'],
            'lifecycles' => ['admin.builder.lifecycles', 'admin.metadata'],
            'scoring profiles' => ['admin.builder.scoring-profiles', 'admin.scoring'],
            'configuration bundles' => ['admin.configuration', 'admin.configuration'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('builderRoutes')]
    public function every_builder_page_renders(string $route, string $permission): void
    {
        $this->get(route($route))->assertOk();
    }

    #[Test]
    public function the_attribute_editor_renders_for_a_type(): void
    {
        $this->get(route('admin.builder.attributes', ObjectType::resolve('Control')))
            ->assertOk()
            ->assertSee('Control Name', false);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('builderRoutes')]
    public function a_user_without_the_permission_is_refused(string $route, string $permission): void
    {
        $outsider = \App\Models\User::create([
            'name' => 'Read-only analyst',
            'email' => 'analyst-'.$this->organization->id.'@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $outsider->assignRole('risk-analyst');

        $this->actingAs($outsider)->get(route($route))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */

    /**
     * A complete attribute payload, since the update route validates the whole
     * field rather than the one property a Livewire `set()` used to touch.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributePayload(ObjectAttribute $attribute, array $overrides = []): array
    {
        return array_merge([
            'code' => $attribute->code,
            'label' => $attribute->label,
            'data_type' => $attribute->data_type,
            'section' => $attribute->section ?: 'Details',
            'sort_order' => (int) $attribute->sort_order,
            'width' => $attribute->width ?: 'half',
        ], $overrides);
    }

    private function makeGraphObject(ObjectType $type, string $name): GraphObject
    {
        return GraphObject::create([
            'organization_id' => $this->organization->id,
            'object_type_id' => $type->id,
            // objects.code is NOT NULL: every node in the graph carries a
            // human-quotable reference.
            'code' => strtoupper(substr(md5($name.$type->id), 0, 10)),
            'name' => $name,
            'created_by' => $this->actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTenantType(array $overrides = []): ObjectType
    {
        return ObjectType::create(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'TenantType',
            'name' => 'Tenant Type',
            'category' => 'governance',
            'is_system' => false,
        ], $overrides));
    }
}
