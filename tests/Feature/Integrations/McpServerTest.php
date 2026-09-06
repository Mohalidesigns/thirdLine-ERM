<?php

namespace Tests\Feature\Integrations;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-07 TASK 5 acceptance: the MCP server answers query_objects with
 * permission-correct results.
 *
 * Three properties, and they are the whole design:
 *
 *   read-only    an agent that can write to a risk register can fabricate a
 *                control test result, and nothing after the fact tells that
 *                apart from a real one
 *   the caller's rights, not the agent's — otherwise the permission system has
 *                a hole in the shape of a chatbot
 *   citations    every result carries the ids it came from. WP-02 removed
 *                twelve fabricated figures from this platform; an AI surface
 *                returning numbers without provenance would put them back.
 */
class McpServerTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
    }

    /* ================================================================== */

    #[Test]
    public function it_advertises_its_tools(): void
    {
        $response = $this->rpc('tools/list', [], $this->token(['*']));

        $names = collect($response->json('result.tools'))->pluck('name');

        foreach ([
            'list_object_types', 'query_objects', 'get_object',
            'traverse_graph', 'get_measure_series', 'list_my_tasks',
        ] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    #[Test]
    public function initialize_tells_the_agent_to_cite_its_sources(): void
    {
        $result = $this->rpc('initialize', [], $this->token(['*']))->json('result');

        $this->assertSame('atheris-erm', $result['serverInfo']['name']);
        $this->assertStringContainsString('citations', $result['instructions']);
        $this->assertStringContainsString('read-only', $result['instructions']);
    }

    #[Test]
    public function query_objects_returns_records_with_citations(): void
    {
        $risk = $this->makeRisk(['title' => 'Reconciliation backlog']);

        $result = $this->tool('query_objects', ['type' => 'risks'], $this->token(['risk.view']));

        $this->assertSame(1, $result['count']);
        $this->assertSame('Reconciliation backlog', $result['records'][0]['title']);
        $this->assertContains('risks:'.$risk->id, $result['citations']);
    }

    #[Test]
    public function query_objects_honours_the_filter_allowlist(): void
    {
        $this->makeRisk(['title' => 'Open', 'status' => 'active']);
        $this->makeRisk(['title' => 'Closed', 'status' => 'closed']);

        $token = $this->token(['risk.view']);

        $filtered = $this->tool('query_objects', ['type' => 'risks', 'filters' => ['status' => 'active']], $token);
        $this->assertSame(1, $filtered['count']);

        // A field the resource does not publish is ignored rather than passed
        // to the builder — an agent can otherwise be talked into filtering on a
        // column nobody decided to expose.
        $unfiltered = $this->tool('query_objects', ['type' => 'risks', 'filters' => ['created_by' => 999]], $token);
        $this->assertSame(2, $unfiltered['count']);
    }

    #[Test]
    public function a_tool_refuses_what_the_calling_user_may_not_see(): void
    {
        $this->makeRisk();

        // The token asks for everything; the user is a risk-analyst, who cannot
        // view loss events.
        $token = $this->token(['*'], role: 'risk-analyst');

        $this->assertSame(1, $this->tool('query_objects', ['type' => 'risks'], $token)['count']);

        $response = $this->rpc('tools/call', [
            'name' => 'query_objects',
            'arguments' => ['type' => 'loss-events'],
        ], $token);

        // A JSON-RPC error, not an HTTP one: an agent cannot act on a 403 with
        // an HTML body, but it can explain "this token may not loss_event.view".
        $this->assertSame(-32003, $response->json('error.code'));
        $this->assertStringContainsString('loss_event.view', $response->json('error.message'));
    }

    #[Test]
    public function it_cannot_reach_another_organizations_records(): void
    {
        $mine = $this->makeRisk(['title' => 'Mine']);

        $otherOrg = \ThirdLine\Platform\Tenancy\TenantContext::bypass(fn () => \App\Models\Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]), 'test fixture');

        \ThirdLine\Platform\Tenancy\TenantContext::actingAs($otherOrg->id, function () use ($otherOrg) {
            $category = \App\Models\RiskCategory::create([
                'organization_id' => $otherOrg->id, 'code' => 'OPS', 'name' => 'Operational',
            ]);

            \App\Models\Risk::create([
                'organization_id' => $otherOrg->id,
                'risk_code' => 'RK-OTHER-1',
                'title' => 'Theirs',
                'description' => 'Another organization entirely.',
                'category_id' => $category->id,
                'status' => 'active',
            ]);
        });

        $result = $this->tool('query_objects', ['type' => 'risks'], $this->token(['risk.view']));

        $this->assertSame(1, $result['count']);
        $this->assertSame('Mine', $result['records'][0]['title']);
        $this->assertContains('risks:'.$mine->id, $result['citations']);
    }

    #[Test]
    public function list_object_types_reports_only_what_this_token_can_query(): void
    {
        $result = $this->tool('list_object_types', [], $this->token(['risk.view']));

        $this->assertContains('risks', $result['queryable_types']);
        $this->assertNotContains('loss-events', $result['queryable_types']);
    }

    #[Test]
    public function list_my_tasks_says_so_when_the_token_has_no_user(): void
    {
        // Named scope rather than ['*']: a machine token acts as no user, so
        // nothing narrows a wildcard down afterwards, and ApiToken now both
        // refuses to issue one with '*' and refuses to honour it on a token
        // that already holds it. The scope this tool needs is task.view; what
        // the test is about is the token having no acting user behind it.
        $token = $this->machineToken(['task.view']);

        $result = $this->tool('list_my_tasks', [], $token);

        // Better than an empty list, which an agent would report as "you have
        // nothing to do".
        $this->assertStringContainsString('machine token', $result['error']);
        $this->assertSame([], $result['tasks']);
    }

    #[Test]
    public function there_are_no_write_tools(): void
    {
        $names = collect($this->rpc('tools/list', [], $this->token(['*']))->json('result.tools'))->pluck('name');

        foreach ($names as $name) {
            $this->assertTrue(
                str_starts_with($name, 'list_') || str_starts_with($name, 'get_')
                || str_starts_with($name, 'query_') || str_starts_with($name, 'traverse_'),
                "[{$name}] is not obviously read-only. Every MCP tool must be."
            );
        }
    }

    #[Test]
    public function an_unknown_tool_is_a_json_rpc_error(): void
    {
        $response = $this->rpc('tools/call', [
            'name' => 'delete_everything',
            'arguments' => [],
        ], $this->token(['*']));

        $this->assertSame(-32003, $response->json('error.code'));
        $this->assertStringContainsString('no [delete_everything] tool', $response->json('error.message'));
    }

    #[Test]
    public function the_endpoint_refuses_an_unauthenticated_call(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(401);
    }

    /* ================================================================== */

    private function rpc(string $method, array $params, string $token)
    {
        return $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);
    }

    /** @return array<string, mixed> */
    private function tool(string $tool, array $arguments, string $token): array
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments], $token)
            ->assertOk()
            ->json('result.structuredContent');
    }

    /**
     * dashboard.view is always included: it is the platform's "may use this
     * application" permission and the MCP route's guard, so every real token
     * reaching this endpoint carries it. The per-tool permission is what the
     * tests here are actually exercising.
     */
    private function token(array $scopes, string $role = 'risk-manager'): string
    {
        $scopes = array_values(array_unique(array_merge($scopes, ['dashboard.view'])));

        $user = User::create([
            'name' => 'Agent Owner',
            'email' => Str::random(8).'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $user->assignRole($role);

        $plain = Str::random(48);

        $token = ApiToken::create([
            'organization_id' => $this->organization->id,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'name' => 'Agent token',
            'token_type' => ApiToken::TYPE_PERSONAL,
            'token' => hash('sha256', $plain),
            'abilities' => $scopes,
        ]);

        return $token->id.'|'.$plain;
    }

    private function machineToken(array $scopes): string
    {
        $scopes = array_values(array_unique(array_merge($scopes, ['dashboard.view'])));

        $plain = Str::random(48);

        $token = ApiToken::create([
            'organization_id' => $this->organization->id,
            'tokenable_type' => null,
            'tokenable_id' => null,
            'name' => 'Machine agent',
            'token_type' => ApiToken::TYPE_CLIENT,
            'token' => hash('sha256', $plain),
            'abilities' => $scopes,
        ]);

        return $token->id.'|'.$plain;
    }
}
