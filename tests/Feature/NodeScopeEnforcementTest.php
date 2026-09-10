<?php

namespace Tests\Feature;

use App\Grids\GridRegistry;
use App\Models\ApiToken;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixture;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Node scoping, ENFORCED — as distinct from GraphScopeTest, which proves the
 * rule is correct.
 *
 * GraphScopeTest has always passed. It exercises Model::visibleTo() directly,
 * and every branch of it was green while `grep -rn "visibleTo(" app/` returned
 * exactly one hit: the trait's own docblock. The rule was built, tested and
 * called from nowhere, so a user pinned to a branch was served the entire
 * organisation's register, controls, issues, loss events and KRIs in every
 * grid, every export, every API response, and could open any record by id.
 *
 * This suite asserts the wiring rather than the rule: that the caller-facing
 * read paths actually ask. It is deliberately written against ROUTES and GRID
 * DEFINITIONS rather than against models, because the defect was never in the
 * model layer.
 *
 *   group            (root)
 *    ├── retail      <- the pinned user is here
 *    │    └── branch     ... and sees this
 *    └── treasury    <- sibling; must be invisible everywhere
 */
class NodeScopeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** Tables carrying entity_id, and therefore ScopedToGraph. */
    private const SCOPED_TABLES = [
        'risks', 'controls', 'issues', 'loss_events', 'key_risk_indicators',
    ];

    private Organization $org;

    private Entity $retail;

    private Entity $branch;

    private Entity $treasury;

    private TenantFixture $fixture;

    private User $pinned;

    /** @var array<string, int> table => id of the row on retail */
    private array $onRetail = [];

    /** @var array<string, int> table => id of the row on branch (below retail) */
    private array $onBranch = [];

    /** @var array<string, int> table => id of the row on treasury (the sibling) */
    private array $onTreasury = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Delegated Bank PLC',
            'short_name' => 'DELEG',
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

        $group = $this->entity($type, 'GRP', 'Group', null);
        $this->retail = $this->entity($type, 'RET', 'Retail Banking', $group->id);
        $this->branch = $this->entity($type, 'BRN', 'Ikeja Branch', $this->retail->id);
        $this->treasury = $this->entity($type, 'TRS', 'Treasury', $group->id);

        $this->fixture = new TenantFixture;

        foreach (self::SCOPED_TABLES as $table) {
            $this->onRetail[$table] = $this->rowOn($table, $this->retail, 'RETAIL');
            $this->onBranch[$table] = $this->rowOn($table, $this->branch, 'BRANCH');
            $this->onTreasury[$table] = $this->rowOn($table, $this->treasury, 'TREASURY');
        }

        $this->pinned = $this->pinnedUser($this->retail);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  (a) Grids */
    /* ------------------------------------------------------------------ */

    /** @return list<array{0: string, 1: string}> grid name => backing table */
    public static function scopedGridProvider(): array
    {
        return [
            'risk register' => ['risks', 'risks'],
            'control library' => ['controls', 'controls'],
            'issue log' => ['issues', 'issues'],
            'loss event register' => ['loss_events', 'loss_events'],
            'KRI library' => ['kris', 'key_risk_indicators'],
        ];
    }

    #[Test]
    #[DataProvider('scopedGridProvider')]
    public function a_grid_lists_the_subtree_and_nothing_beside_it(string $grid, string $table): void
    {
        $this->actingAs($this->pinned);

        $listed = GridRegistry::resolve($grid)->query()->get()->modelKeys();

        $this->assertContains($this->onRetail[$table], $listed, "[{$grid}] the user's own node is missing");
        $this->assertContains($this->onBranch[$table], $listed, "[{$grid}] a node below the user's is missing");
        $this->assertNotContains(
            $this->onTreasury[$table],
            $listed,
            "[{$grid}] a sibling branch's records are listed; the grid is tenancy-scoped but not node-scoped"
        );
    }

    #[Test]
    public function a_grid_is_unchanged_for_a_user_who_is_not_pinned(): void
    {
        // The regression that matters in the other direction: a CRO, a risk
        // manager or anyone with no scope_entity_id must keep seeing the whole
        // organisation. Node scoping is opt-in per user and must stay that way.
        $this->actingAs($this->unpinnedUser());

        $listed = GridRegistry::resolve('risks')->query()->get()->modelKeys();

        $this->assertContains($this->onTreasury['risks'], $listed);
    }

    #[Test]
    public function a_full_org_role_is_not_narrowed_by_its_pin(): void
    {
        // Pinned to retail, but a CRO. config('authorization.full_org_roles')
        // wins: the board pack cannot be cut down to whoever opened it.
        $cro = $this->pinnedUser($this->retail, 'chief-risk-officer', 'cro@example.test');

        $this->actingAs($cro);

        $this->assertContains(
            $this->onTreasury['risks'],
            GridRegistry::resolve('risks')->query()->get()->modelKeys()
        );
    }

    /* ------------------------------------------------------------------ */
    /*  (b) show() — 404, not 403 */
    /* ------------------------------------------------------------------ */

    /** @return list<array{0: string, 1: string}> URL template => backing table */
    public static function showRouteProvider(): array
    {
        return [
            'risk' => ['/risk/register/%d', 'risks'],
            'control' => ['/risk/controls/%d', 'controls'],
            'issue' => ['/risk/issues/%d', 'issues'],
            'loss event' => ['/risk/loss-events/%d', 'loss_events'],
            'KRI' => ['/risk/kri/%d', 'key_risk_indicators'],
        ];
    }

    #[Test]
    #[DataProvider('showRouteProvider')]
    public function a_sibling_branchs_record_is_not_found_rather_than_forbidden(string $template, string $table): void
    {
        $this->actingAs($this->pinned);

        $sibling = $this->get(sprintf($template, $this->onTreasury[$table]));

        // 404 SPECIFICALLY. The caller holds every permission in the product,
        // so a 403 would mean some other check refused the request and node
        // scoping was never exercised — and leaking the record's existence is
        // itself a disclosure. Same convention as TenancyIsolationTest.
        $this->assertSame(
            404,
            $sibling->getStatusCode(),
            sprintf('GET %s returned %d; a sibling branch\'s record must be invisible, not merely forbidden',
                sprintf($template, $this->onTreasury[$table]), $sibling->getStatusCode())
        );

        // And the user's own record still resolves. Asserted as "not 404"
        // rather than "200": the fixture rows are structurally valid but
        // semantically empty, so a detail page may fail to render for reasons
        // that have nothing to do with authorization — while a 404 here would
        // mean the scope had locked the user out of their own subtree.
        $own = $this->get(sprintf($template, $this->onRetail[$table]));

        $this->assertNotSame(
            404,
            $own->getStatusCode(),
            sprintf('GET %s returned 404; the user is pinned to this very node', sprintf($template, $this->onRetail[$table]))
        );
    }

    /* ------------------------------------------------------------------ */
    /*  (c) Exports */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_export_contains_only_the_subtree(): void
    {
        $this->actingAs($this->pinned);

        $csv = $this->streamed('/risk/export/register');

        $this->assertStringContainsString('RK-RETAIL', $csv, 'the export dropped the user\'s own node');
        $this->assertStringContainsString('RK-BRANCH', $csv, 'the export dropped a node below the user\'s');
        $this->assertStringNotContainsString(
            'RK-TREASURY',
            $csv,
            'a sibling branch\'s risks left the building in a CSV; an unscoped export is worse than an unscoped screen'
        );
    }

    #[Test]
    public function an_export_is_unchanged_for_a_user_who_is_not_pinned(): void
    {
        $this->actingAs($this->unpinnedUser());

        $this->assertStringContainsString('RK-TREASURY', $this->streamed('/risk/export/register'));
    }

    /* ------------------------------------------------------------------ */
    /*  (d) API */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_api_serves_only_the_subtree(): void
    {
        $token = $this->tokenFor($this->pinned, ['risk.view']);

        $ids = collect($this->withToken($token)->getJson('/api/v1/risks')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains((string) $this->onRetail['risks'], $ids);
        $this->assertContains((string) $this->onBranch['risks'], $ids);
        $this->assertNotContains((string) $this->onTreasury['risks'], $ids);

        // And not by id either — the collection and the single fetch have to
        // agree, or the filter on the list is decoration.
        $this->withToken($token)
            ->getJson('/api/v1/risks/'.$this->onTreasury['risks'])
            ->assertStatus(404);

        $this->withToken($token)
            ->getJson('/api/v1/risks/'.$this->onRetail['risks'])
            ->assertOk();
    }

    #[Test]
    public function the_api_is_unchanged_for_a_token_whose_user_is_not_pinned(): void
    {
        $token = $this->tokenFor($this->unpinnedUser(), ['risk.view']);

        $ids = collect($this->withToken($token)->getJson('/api/v1/risks')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains((string) $this->onTreasury['risks'], $ids);
    }

    /* ------------------------------------------------------------------ */
    /*  Coverage: the wiring may not quietly come undone */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_grid_over_a_scoped_model_asks_for_the_node_scope(): void
    {
        $this->actingAs($this->pinned);

        $unscoped = [];

        foreach (array_keys(self::gridNames()) as $name) {
            $definition = GridRegistry::resolve($name);
            $model = $definition->query()->getModel();

            if (! in_array(\App\Models\Concerns\ScopedToGraph::class, class_uses_recursive($model), true)) {
                continue;
            }

            // A scoped model's grid must produce SQL that mentions the
            // entities table — that is what GraphScope's prefix match compiles
            // to, and nothing else in these queries touches it.
            if (! str_contains($definition->query()->toSql(), 'entities')) {
                $unscoped[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unscoped,
            'These grids list a node-scoped model without applying visibleTo(): '.implode(', ', $unscoped)
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @return array<string, class-string> */
    private static function gridNames(): array
    {
        $reflection = new \ReflectionClass(GridRegistry::class);
        $property = $reflection->getProperty('grids');
        $property->setAccessible(true);

        return $property->getValue();
    }

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

    /**
     * One structurally valid row on a node, labelled where the table has a
     * reference column the export prints — the CSV assertions need something
     * to look for that is not an auto-increment id.
     */
    private function rowOn(string $table, Entity $node, string $label): int
    {
        $overrides = ['entity_id' => $node->id];

        $overrides += match ($table) {
            'risks' => ['risk_code' => 'RK-'.$label, 'title' => $label.' risk'],
            'loss_events' => ['event_reference' => 'LE-'.$label],
            'issues' => ['issue_reference' => 'ISS-'.$label],
            'controls' => ['control_code' => 'CTL-'.$label],
            'key_risk_indicators' => ['kri_code' => 'KRI-'.$label],
            default => [],
        };

        return $this->fixture->make($table, $this->org->id, $overrides);
    }

    /**
     * A user pinned to a node, holding every permission in the product.
     *
     * Every permission on purpose, and for the reason TenancyIsolationTest
     * gives for using super-admin: a caller who lacks a permission is refused
     * for the wrong reason, and a broken node scope then passes unnoticed
     * behind a 403. The role is a new one rather than an existing seeded role
     * because every seeded role broad enough to read all five modules is listed
     * in config('authorization.full_org_roles') — which would make the user
     * organization-wide and the test vacuous.
     */
    private function pinnedUser(Entity $node, ?string $role = null, string $email = 'pinned@example.test'): User
    {
        if ($role === null) {
            $role = 'branch-manager';

            if (! Role::where('name', $role)->exists()) {
                Role::create(['name' => $role])->givePermissionTo(Permission::all());
            }
        }

        return $this->makeUser($email, $node->id, $role);
    }

    private function unpinnedUser(): User
    {
        return $this->makeUser('unpinned@example.test', null, 'super-admin');
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

    /** @param list<string> $scopes */
    private function tokenFor(User $user, array $scopes): string
    {
        $plain = Str::random(48);

        $token = ApiToken::create([
            'organization_id' => $this->org->id,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'name' => 'Node scope test token',
            'token_type' => ApiToken::TYPE_PERSONAL,
            'token' => hash('sha256', $plain),
            'abilities' => $scopes,
            'expires_at' => null,
        ]);

        return $token->id.'|'.$plain;
    }

    /** The body of a streamed CSV download. */
    private function streamed(string $url): string
    {
        $response = $this->get($url);

        $response->assertOk();

        return $response->streamedContent();
    }
}
