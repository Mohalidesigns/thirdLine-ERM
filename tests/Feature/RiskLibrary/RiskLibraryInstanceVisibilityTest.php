<?php

namespace Tests\Feature\RiskLibrary;

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Gate-2 blocker (2026-11-27): the library Show page listed EVERY linked risk instance —
 * title, entity, owner, residual rating — to anyone holding `risk_library.view`. Risk
 * champions hold that without `risk.view`, and a branch-pinned user holds `risk.view`
 * only for their own subtree. Linked instances are register rows, so they are listed
 * on the register's terms: `risk.view` plus graph reach. The total stays org-wide.
 */
class RiskLibraryInstanceVisibilityTest extends RiskLibraryTestCase
{
    private function itemWithInstancesOnBothEntities()
    {
        $item = $this->libraryService->create($this->validLibraryPayload(), $this->actor);

        foreach ([$this->entityA, $this->entityB] as $entity) {
            $this->libraryService->attachToTarget($item, [
                'entity_id' => $entity->id,
                'risk_owner_id' => $this->actor->id,
            ], $this->actor);
        }

        return $item;
    }

    public function test_a_user_without_risk_view_sees_the_count_but_no_instance_rows(): void
    {
        $item = $this->itemWithInstancesOnBothEntities();
        $champion = $this->userWithPermissions(['risk_library.view'], 'champion@example.test');

        $this->actingAs($champion)
            ->get(route('risk.library.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instances.total', 2)
                ->where('instances.can_view_rows', false)
                ->where('instances.rows', [])
                ->where('instances.hidden_count', 2));
    }

    public function test_a_branch_pinned_user_sees_only_instances_inside_their_subtree(): void
    {
        $item = $this->itemWithInstancesOnBothEntities();
        $pinned = $this->userWithPermissions(['risk_library.view', 'risk.view'], 'pinned@example.test');
        $pinned->forceFill(['scope_entity_id' => $this->entityA->id])->save();

        $this->actingAs($pinned)
            ->get(route('risk.library.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instances.total', 2)
                ->where('instances.can_view_rows', true)
                ->has('instances.rows', 1)
                ->where('instances.rows.0.entity_name', $this->entityA->name)
                ->where('instances.hidden_count', 1));
    }

    public function test_a_full_org_user_sees_every_instance(): void
    {
        $item = $this->itemWithInstancesOnBothEntities();

        $this->actingAs($this->actor)
            ->get(route('risk.library.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('instances.total', 2)
                ->has('instances.rows', 2)
                ->where('instances.hidden_count', 0));
    }
}
