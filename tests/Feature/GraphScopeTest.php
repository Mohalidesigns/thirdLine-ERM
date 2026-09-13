<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Issue;
use App\Models\KeyRiskIndicator;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Support\Authorization\GraphScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantFixture;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Node-scoped authorization: a user pinned to a graph node sees that node and
 * everything beneath it, and nothing to the side or above.
 *
 *   group            (root)
 *    ├── retail      <- the subtree user is pinned here
 *    │    └── branch
 *    └── treasury    <- sibling; must stay invisible
 */
class GraphScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Entity $group;

    private Entity $retail;

    private Entity $branch;

    private Entity $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Graph Bank PLC',
            'short_name' => 'GRAPH',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $type = EntityType::create([
            'organization_id' => $this->org->id,
            'code' => 'BU',
            'name' => 'Business Unit',
            'level' => 1,
        ]);

        $this->group = $this->entity($type, 'GRP', 'Group', null);
        $this->retail = $this->entity($type, 'RET', 'Retail Banking', $this->group->id);
        $this->branch = $this->entity($type, 'BRN', 'Ikeja Branch', $this->retail->id);
        $this->treasury = $this->entity($type, 'TRS', 'Treasury', $this->group->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Materialised path */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function creating_an_entity_materialises_its_path(): void
    {
        $this->assertSame("/{$this->group->id}/", $this->group->fresh()->hierarchy_path);
        $this->assertSame("/{$this->group->id}/{$this->retail->id}/", $this->retail->fresh()->hierarchy_path);
        $this->assertSame(
            "/{$this->group->id}/{$this->retail->id}/{$this->branch->id}/",
            $this->branch->fresh()->hierarchy_path
        );
    }

    #[Test]
    public function reparenting_a_node_rewrites_the_paths_of_its_descendants(): void
    {
        $this->retail->update(['parent_id' => $this->treasury->id]);

        $this->assertSame(
            "/{$this->group->id}/{$this->treasury->id}/{$this->retail->id}/{$this->branch->id}/",
            $this->branch->fresh()->hierarchy_path
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Visibility */
    /* ------------------------------------------------------------------ */

    /** @return list<array{0: class-string, 1: string}> */
    public static function graphModelProvider(): array
    {
        return [
            [Risk::class, 'risks'],
            [Control::class, 'controls'],
            [Issue::class, 'issues'],
            [LossEvent::class, 'loss_events'],
            [KeyRiskIndicator::class, 'key_risk_indicators'],
        ];
    }

    #[Test]
    #[DataProvider('graphModelProvider')]
    public function a_subtree_user_sees_their_node_and_below_but_not_siblings(string $class, string $table): void
    {
        $fixture = new TenantFixture;

        $onRetail = $fixture->make($table, $this->org->id, ['entity_id' => $this->retail->id]);
        $onBranch = $fixture->make($table, $this->org->id, ['entity_id' => $this->branch->id]);
        $onTreasury = $fixture->make($table, $this->org->id, ['entity_id' => $this->treasury->id]);
        $onGroup = $fixture->make($table, $this->org->id, ['entity_id' => $this->group->id]);

        $user = $this->subtreeUser($this->retail);

        $visible = $class::visibleTo($user)->pluck('id')->all();

        $this->assertContains($onRetail, $visible, "[{$table}] own node hidden");
        $this->assertContains($onBranch, $visible, "[{$table}] descendant node hidden");
        $this->assertNotContains($onTreasury, $visible, "[{$table}] sibling node leaked");
        $this->assertNotContains($onGroup, $visible, "[{$table}] ancestor node leaked");
    }

    #[Test]
    public function a_full_org_role_ignores_the_subtree_pin(): void
    {
        $fixture = new TenantFixture;
        $onTreasury = $fixture->make('risks', $this->org->id, ['entity_id' => $this->treasury->id]);

        // Pinned to retail, but holds a full-org role.
        $user = $this->subtreeUser($this->retail, 'chief-risk-officer');

        $this->assertContains($onTreasury, Risk::visibleTo($user)->pluck('id')->all());
        $this->assertFalse(GraphScope::isSubtreeLimited($user));
    }

    #[Test]
    public function a_user_with_no_pin_sees_the_whole_organization(): void
    {
        $fixture = new TenantFixture;
        $onTreasury = $fixture->make('risks', $this->org->id, ['entity_id' => $this->treasury->id]);

        $user = $this->makeUser('unpinned@example.test', null, 'risk-owner');

        $this->assertContains($onTreasury, Risk::visibleTo($user)->pluck('id')->all());
    }

    #[Test]
    public function records_on_no_node_stay_visible_while_the_graph_is_being_populated(): void
    {
        $fixture = new TenantFixture;
        $unassigned = $fixture->make('risks', $this->org->id, ['entity_id' => null]);

        $user = $this->subtreeUser($this->retail);

        $this->assertContains($unassigned, Risk::visibleTo($user)->pluck('id')->all());

        config()->set('authorization.subtree_users_see_unassigned', false);

        $this->assertNotContains($unassigned, Risk::visibleTo($user)->pluck('id')->all());
    }

    #[Test]
    public function a_pin_to_a_soft_deleted_node_fails_closed(): void
    {
        $fixture = new TenantFixture;
        $fixture->make('risks', $this->org->id, ['entity_id' => $this->retail->id]);
        $fixture->make('risks', $this->org->id, ['entity_id' => $this->treasury->id]);

        $user = $this->subtreeUser($this->retail);

        $this->retail->delete();

        // Entities soft-delete, so the pin survives while the node stops
        // resolving. That must deny everything rather than widen to the whole
        // organization.
        $this->assertSame([], Risk::visibleTo($user->fresh())->pluck('id')->all());
    }

    #[Test]
    public function a_node_cannot_be_hard_deleted_while_users_are_pinned_to_it(): void
    {
        $this->subtreeUser($this->retail);

        // Nulling the pin on delete would silently promote the user to
        // organization-wide visibility, so the database refuses instead.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->retail->forceDelete();
    }

    #[Test]
    public function graph_scope_composes_with_tenancy_rather_than_replacing_it(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHER',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $fixture = new TenantFixture;
        $foreign = $fixture->make('risks', $other->id, ['entity_id' => null]);

        $user = $this->subtreeUser($this->retail);

        // Unassigned records are visible to subtree users — but only their own
        // organization's. Tenancy still applies underneath.
        $this->assertNotContains($foreign, Risk::visibleTo($user)->pluck('id')->all());
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function entity(EntityType $type, string $code, string $name, ?int $parentId): Entity
    {
        return Entity::create([
            'organization_id' => $this->org->id,
            'entity_type_id' => $type->id,
            'parent_id' => $parentId,
            'entity_code' => $code,
            'name' => $name,
            'status' => 'active',
            'level' => $parentId === null ? 0 : 1,
        ]);
    }

    private function subtreeUser(Entity $node, string $role = 'risk-owner'): User
    {
        return $this->makeUser('pinned-'.$node->entity_code.'@example.test', $node->id, $role);
    }

    private function makeUser(string $email, ?int $scopeEntityId, string $role): User
    {
        $user = User::create([
            'name' => 'Scoped User',
            'email' => $email,
            'password' => Hash::make('password'),
            'organization_id' => $this->org->id,
            'scope_entity_id' => $scopeEntityId,
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
