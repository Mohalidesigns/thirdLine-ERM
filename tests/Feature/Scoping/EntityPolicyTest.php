<?php

namespace Tests\Feature\Scoping;

use App\Models\Entity;
use App\Policies\EntityPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;

/**
 * Phase 3.1 — EntityPolicy: permission, then tenancy, then node scope.
 */
class EntityPolicyTest extends ScopingTestCase
{
    #[Test]
    public function the_policy_is_discovered_by_convention(): void
    {
        $this->assertInstanceOf(EntityPolicy::class, Gate::getPolicyFor(Entity::class));
    }

    #[Test]
    public function each_ability_needs_its_permission(): void
    {
        $viewer = $this->userWith(['entity.view'], email: 'viewer@example.test');
        $editor = $this->userWith(['entity.view', 'entity.edit'], email: 'editor@example.test');
        $creator = $this->userWith(['entity.create'], email: 'creator@example.test');
        $deleter = $this->userWith(['entity.view', 'entity.delete'], email: 'deleter@example.test');
        $nobody = $this->userWith([], email: 'nobody@example.test');

        $this->assertTrue($viewer->can('viewAny', Entity::class));
        $this->assertTrue($viewer->can('view', $this->retail));
        $this->assertFalse($viewer->can('update', $this->retail));
        $this->assertFalse($viewer->can('delete', $this->retail));
        $this->assertFalse($viewer->can('create', Entity::class));

        $this->assertTrue($editor->can('update', $this->retail));
        $this->assertFalse($editor->can('delete', $this->retail));

        $this->assertTrue($creator->can('create', Entity::class));
        $this->assertFalse($creator->can('viewAny', Entity::class));

        $this->assertTrue($deleter->can('delete', $this->retail));

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertFalse($nobody->can($ability, Entity::class));
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse($nobody->can($ability, $this->retail));
        }
    }

    #[Test]
    public function update_and_delete_require_the_edit_or_delete_permission_and_not_just_view(): void
    {
        $editorOnly = $this->userWith(['entity.edit'], email: 'edit-only@example.test');

        // entity.edit without entity.view: may update, may not view — the
        // policy asks for the verb's own permission, as the route does.
        $this->assertTrue($editorOnly->can('update', $this->retail));
        $this->assertFalse($editorOnly->can('view', $this->retail));
    }

    #[Test]
    public function another_tenants_entity_is_never_reachable(): void
    {
        $viewer = $this->userWith(['entity.view', 'entity.edit', 'entity.delete']);

        $this->assertFalse($viewer->can('view', $this->foreign));
        $this->assertFalse($viewer->can('update', $this->foreign));
        $this->assertFalse($viewer->can('delete', $this->foreign));
    }

    #[Test]
    public function a_pinned_user_reaches_their_node_and_below_but_not_a_sibling_or_an_ancestor(): void
    {
        $pinned = $this->userWith(['entity.view', 'entity.edit', 'entity.delete'], $this->retail);

        $this->assertTrue($pinned->can('view', $this->retail));
        $this->assertTrue($pinned->can('view', $this->branch));
        $this->assertTrue($pinned->can('update', $this->branch));

        $this->assertFalse($pinned->can('view', $this->treasury));
        $this->assertFalse($pinned->can('view', $this->group));
        $this->assertFalse($pinned->can('update', $this->treasury));
        $this->assertFalse($pinned->can('delete', $this->group));
    }

    #[Test]
    public function a_user_pinned_to_a_node_that_no_longer_exists_reaches_nothing(): void
    {
        $orphan = $this->userWith(['entity.view'], $this->treasury);
        $this->treasury->delete();

        $this->assertFalse($orphan->fresh()->can('view', $this->retail));
        $this->assertFalse($orphan->fresh()->can('view', $this->group));
    }

    #[Test]
    public function a_full_organization_role_is_not_confined_by_its_scope_node(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('risk-manager');

        $manager = $this->userWith(['entity.view'], $this->retail);
        $manager->assignRole('risk-manager');

        $this->assertTrue($manager->fresh()->can('view', $this->treasury));
    }
}
