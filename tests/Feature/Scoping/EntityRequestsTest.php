<?php

namespace Tests\Feature\Scoping;

use App\Models\Entity;
use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3.1 — StoreEntityRequest / UpdateEntityRequest: every foreign key is
 * tenant-bound, and the tenant's configured fields are validated in the same
 * pass as the columns.
 */
class EntityRequestsTest extends ScopingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actor->givePermissionTo(['entity.view', 'entity.create', 'entity.edit']);
        $this->actingAs($this->actor);
    }

    #[Test]
    public function an_entity_type_from_another_organization_is_rejected(): void
    {
        $this->post(route('risk.scoping.store'), $this->validPayload(['entity_type_id' => $this->otherType->id]))
            ->assertSessionHasErrors('entity_type_id');

        $this->put(route('risk.scoping.update', $this->branch), $this->validPayload(['entity_type_id' => $this->otherType->id]))
            ->assertSessionHasErrors('entity_type_id');

        $this->assertDatabaseMissing('entities', ['name' => 'Abuja Central Branch']);
    }

    #[Test]
    public function a_parent_from_another_organization_is_rejected(): void
    {
        $this->post(route('risk.scoping.store'), $this->validPayload(['parent_id' => $this->foreign->id]))
            ->assertSessionHasErrors('parent_id');

        $this->put(route('risk.scoping.update', $this->branch), $this->validPayload(['parent_id' => $this->foreign->id]))
            ->assertSessionHasErrors('parent_id');

        $this->assertSame($this->retail->id, $this->branch->fresh()->parent_id);
    }

    #[Test]
    public function an_owner_or_delegate_from_another_organization_is_rejected(): void
    {
        $this->post(route('risk.scoping.store'), $this->validPayload(['owner_id' => $this->otherActor->id]))
            ->assertSessionHasErrors('owner_id')
            ->assertSessionDoesntHaveErrors('delegate_owner_id');

        $this->post(route('risk.scoping.store'), $this->validPayload(['delegate_owner_id' => $this->otherActor->id]))
            ->assertSessionHasErrors('delegate_owner_id');

        $this->put(route('risk.scoping.update', $this->branch), $this->validPayload(['owner_id' => $this->otherActor->id]))
            ->assertSessionHasErrors('owner_id');
    }

    #[Test]
    public function the_enumerations_are_enforced(): void
    {
        $this->post(route('risk.scoping.store'), $this->validPayload([
            'status' => 'sideways',
            'risk_appetite_level' => 'ravenous',
            'category_appetites' => ['credit' => 'ravenous'],
        ]))->assertSessionHasErrors(['status', 'risk_appetite_level', 'category_appetites.credit']);
    }

    #[Test]
    public function no_form_request_uses_a_string_exists_rule(): void
    {
        foreach (glob(app_path('Http/Requests/Scoping/*.php')) as $file) {
            $this->assertStringNotContainsString("'exists:", file_get_contents($file), basename($file));
        }
    }

    #[Test]
    public function a_required_configured_field_fails_the_request_in_the_same_pass_as_the_columns(): void
    {
        // UNIT → BusinessUnit through ObjectTypeRegistry::legacyEntityTypeMap().
        ObjectAttribute::create([
            'object_type_id' => ObjectType::resolve('BusinessUnit')->id,
            'code' => 'cost_centre',
            'label' => 'Cost Centre',
            'data_type' => 'string',
            'is_required' => true,
            'section' => 'Details',
            'sort_order' => 900,
            'is_system' => false,
        ]);

        $this->get(route('risk.scoping.create'))
            ->assertInertia(fn ($page) => $page
                ->where('schemas.'.$this->unitType->id.'.sections.0.fields.0.code', 'cost_centre')
                ->where('schemas.'.$this->unitType->id.'.sections.0.fields.0.errorKey', 'configured_attributes.cost_centre')
                ->where('schemas.'.$this->unitType->id.'.sections.0.fields.0.mapped', false));

        // Missing → refused alongside a bad column, in one response.
        $this->post(route('risk.scoping.store'), $this->validPayload(['entity_type_id' => $this->unitType->id, 'name' => '']))
            ->assertSessionHasErrors(['name', 'configured_attributes.cost_centre']);

        // Present → stored on the entity's graph object.
        $this->post(route('risk.scoping.store'), $this->validPayload([
            'entity_type_id' => $this->unitType->id,
            'configured_attributes' => ['cost_centre' => 'CC-4410'],
        ]))->assertSessionHasNoErrors();

        $entity = Entity::query()->where('name', 'Abuja Central Branch')->firstOrFail();

        $this->assertSame('CC-4410', $entity->graphObject()?->customAttributes()['cost_centre'] ?? null);

        // And read back on the detail page through the presenter.
        $this->actor->givePermissionTo('entity.view');
        $this->get(route('risk.scoping.show', $entity))
            ->assertInertia(fn ($page) => $page
                ->where('configured.sections.0.fields.0.label', 'Cost Centre')
                ->where('configured.sections.0.fields.0.value', 'CC-4410'));

        // A type WITHOUT that field gets no rule for it.
        $this->post(route('risk.scoping.store'), $this->validPayload(['name' => 'Branch without cost centre']))
            ->assertSessionHasNoErrors();
    }
}
