<?php

namespace Tests\Feature\Process;

use App\Models\BusinessProcess;
use App\Models\Entity;
use App\Models\Risk;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Gate-2 blocker (2026-11-27): the process Show page listed every linked risk and
 * entity to anyone holding `process.view`. A role can hold that without
 * `risk.view` / `entity.view`, and a branch-pinned user holds those only for their
 * own subtree. Linked risks and entities are listed on their own modules' terms
 * (permission plus graph reach); the counts stay process-wide.
 */
class ProcessShowLinkedVisibilityTest extends ProcessTestCase
{
    private Entity $entityB;

    private BusinessProcess $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityB = Entity::create([
            'organization_id' => $this->organization->id,
            'entity_type_id' => $this->entityType->id,
            'entity_code' => 'ENT-GH',
            'name' => 'Ghana Banking Subsidiary',
            'status' => 'active',
            'level' => 1,
            'created_by' => $this->actor->id,
        ]);

        $this->process = $this->processService->create([
            'code' => 'PRC-VIS',
            'name' => 'Payments Clearing',
            'owner_id' => $this->actor->id,
        ], $this->actor);

        foreach ([[$this->entityA, 'RSK-V-001'], [$this->entityB, 'RSK-V-002']] as [$entity, $code]) {
            $this->processService->attachToEntity($entity, $this->process, $this->actor);

            Risk::create([
                'organization_id' => $this->organization->id,
                'risk_code' => $code,
                'title' => "Clearing failure at {$entity->entity_code}",
                'description' => 'Clearing cut-off missed.',
                'category_id' => $this->category->id,
                'business_unit_id' => $this->unit->id,
                'entity_id' => $entity->id,
                'process_id' => $this->process->id,
                'risk_owner_id' => $this->actor->id,
                'status' => 'active',
            ]);
        }
    }

    public function test_a_user_with_only_process_view_sees_counts_but_no_risks_or_entities(): void
    {
        $champion = $this->userWithPermissions(['process.view'], 'champion-process@example.test');

        $this->actingAs($champion)
            ->get(route('risk.processes.show', $this->process))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('risks', [])
                ->where('entities', [])
                ->where('linkedCounts.risks', 2)
                ->where('linkedCounts.risks_hidden', 2)
                ->where('linkedCounts.can_view_risks', false)
                ->where('linkedCounts.entities', 2)
                ->where('linkedCounts.entities_hidden', 2));
    }

    public function test_a_branch_pinned_user_sees_only_risks_and_entities_inside_their_subtree(): void
    {
        $pinned = $this->userWithPermissions(['process.view', 'risk.view', 'entity.view'], 'pinned-process@example.test');
        $pinned->forceFill(['scope_entity_id' => $this->entityA->id])->save();

        $this->actingAs($pinned)
            ->get(route('risk.processes.show', $this->process))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('risks', 1)
                ->where('risks.0.risk_code', 'RSK-V-001')
                ->has('entities', 1)
                ->where('entities.0.id', $this->entityA->id)
                ->where('linkedCounts.risks_hidden', 1)
                ->where('linkedCounts.entities_hidden', 1));
    }

    public function test_a_full_org_user_sees_every_linked_risk_and_entity(): void
    {
        $this->actingAs($this->actor)
            ->get(route('risk.processes.show', $this->process))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('risks', 2)
                ->has('entities', 2)
                ->where('linkedCounts.risks_hidden', 0)
                ->where('linkedCounts.entities_hidden', 0));
    }

    public function test_a_branch_pinned_user_cannot_create_a_process_attached_to_an_entity_outside_their_subtree(): void
    {
        $pinned = $this->userWithPermissions(['process.view', 'process.create', 'entity.view'], 'pinned-create@example.test');
        $pinned->forceFill(['scope_entity_id' => $this->entityA->id])->save();

        $this->actingAs($pinned)
            ->postJson(route('risk.processes.store'), [
                'level' => 'process',
                'name' => 'Out-of-reach attach attempt',
                'entity_id' => $this->entityB->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('entity_id');

        $this->assertFalse(BusinessProcess::query()->where('name', 'Out-of-reach attach attempt')->exists());

        $this->actingAs($pinned)
            ->postJson(route('risk.processes.store'), [
                'level' => 'process',
                'name' => 'In-reach attach',
                'entity_id' => $this->entityA->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(BusinessProcess::query()->where('name', 'In-reach attach')->exists());
    }
}
