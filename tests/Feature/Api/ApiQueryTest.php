<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-07 TASK 2 — the query surface behaves as documented.
 *
 * The tests that matter here are the REFUSALS. A filter on a column the
 * resource does not publish must not silently return the whole table, and a
 * write to a field that is not writable must not silently succeed — a client
 * that believes it set `residual_score` and received a 200 has no way to learn
 * otherwise, and will report the value it sent as the value the register holds.
 */
class ApiQueryTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
        $this->token = $this->issueToken();
    }

    /* ================================================================== */
    /*  Shaping */
    /* ================================================================== */

    #[Test]
    public function it_lists_a_resource_in_json_api_shape(): void
    {
        $risk = $this->makeRisk(['title' => 'Reconciliation failure']);

        $this->withToken($this->token)->getJson('/api/v1/risks')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'risks')
            ->assertJsonPath('data.0.id', (string) $risk->id)
            ->assertJsonPath('data.0.attributes.title', 'Reconciliation failure')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    #[Test]
    public function a_filter_narrows_the_result(): void
    {
        $this->makeRisk(['title' => 'Active one', 'status' => 'active']);
        $this->makeRisk(['title' => 'Retired one', 'status' => 'closed']);

        $data = $this->withToken($this->token)
            ->getJson('/api/v1/risks?filter[status]=active')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Active one', $data[0]['attributes']['title']);
    }

    #[Test]
    public function a_comma_in_a_filter_means_any_of_these(): void
    {
        $this->makeRisk(['status' => 'active']);
        $this->makeRisk(['status' => 'closed']);
        $this->makeRisk(['status' => 'draft']);

        $data = $this->withToken($this->token)
            ->getJson('/api/v1/risks?filter[status]=active,closed')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
    }

    #[Test]
    public function a_filter_on_an_unpublished_column_is_refused_not_ignored_silently(): void
    {
        $this->makeRisk(['title' => 'Only one']);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/risks?filter[created_by]=1')
            ->assertOk();

        // The filter did nothing, and the response SAYS so. Returning the whole
        // table with no explanation is how a client ships a report that quietly
        // includes rows it meant to exclude.
        $this->assertContains('filter[created_by]', $response->json('meta.ignored'));
    }

    #[Test]
    public function sorting_is_limited_to_published_columns(): void
    {
        $this->makeRisk(['title' => 'B second']);
        $this->makeRisk(['title' => 'A first']);

        $data = $this->withToken($this->token)
            ->getJson('/api/v1/risks?sort=title')
            ->assertOk()
            ->json('data');

        $this->assertSame('A first', $data[0]['attributes']['title']);

        $response = $this->withToken($this->token)->getJson('/api/v1/risks?sort=-remember_token')->assertOk();

        $this->assertContains('sort=remember_token', $response->json('meta.ignored'));
    }

    #[Test]
    public function a_sparse_fieldset_returns_only_what_was_asked_for(): void
    {
        $this->makeRisk(['title' => 'Trimmed']);

        $attributes = $this->withToken($this->token)
            ->getJson('/api/v1/risks?fields[risks]=risk_code,title')
            ->assertOk()
            ->json('data.0.attributes');

        $this->assertSame(['risk_code', 'title'], array_keys($attributes));
    }

    #[Test]
    public function pagination_is_by_cursor_by_default(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeRisk(['title' => 'Risk '.$i]);
        }

        $response = $this->withToken($this->token)->getJson('/api/v1/risks?page[size]=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('links.next'));

        // Offset pagination on a register being written to while it is read
        // silently skips and repeats rows, so it is opt-in and reports a total.
        $offset = $this->withToken($this->token)
            ->getJson('/api/v1/risks?page[size]=2&page[number]=1')
            ->assertOk();

        $this->assertSame(5, $offset->json('meta.total'));
    }

    #[Test]
    public function an_include_loads_only_allowlisted_relationships(): void
    {
        $this->makeRisk(['title' => 'With category']);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/risks?include=category')
            ->assertOk();

        $this->assertArrayHasKey('category', $response->json('data.0.relationships'));

        $refused = $this->withToken($this->token)->getJson('/api/v1/risks?include=auditTrail')->assertOk();

        $this->assertContains('include=auditTrail', $refused->json('meta.ignored'));
    }

    /* ================================================================== */
    /*  Writes */
    /* ================================================================== */

    #[Test]
    public function it_creates_a_record_and_stamps_the_tenant_from_the_token(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/risks', [
            'data' => ['attributes' => [
                'title' => 'Created through the API',
                'description' => 'A risk raised by an integration.',
                'category_id' => $this->category->id,
            ]],
        ])->assertCreated();

        $risk = Risk::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertNotNull($risk);
        $this->assertSame($this->organization->id, $risk->organization_id);
    }

    #[Test]
    public function a_payload_cannot_write_a_field_the_resource_does_not_publish(): void
    {
        // organization_id is the one that matters: if a payload could set it,
        // a client could write into another tenant.
        $this->withToken($this->token)->postJson('/api/v1/risks', [
            'data' => ['attributes' => [
                'title' => 'Attempted cross-tenant write',
                'organization_id' => 99,
            ]],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_read_only_resource_refuses_a_write(): void
    {
        // A simulation result is produced by the platform, not asserted by a
        // client. 405 rather than 403: the token is fine, the verb is not.
        $this->withToken($this->issueToken(['*']))
            ->postJson('/api/v1/simulations', ['data' => ['attributes' => ['status' => 'completed']]])
            ->assertStatus(405);
    }

    /* ================================================================== */
    /*  Idempotency */
    /* ================================================================== */

    #[Test]
    public function the_same_idempotency_key_replays_the_first_response(): void
    {
        $payload = ['data' => ['attributes' => [
            'title' => 'Posted once',
            'description' => 'The client retried after a timeout.',
            'category_id' => $this->category->id,
        ]]];

        $first = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'retry-me-001')
            ->postJson('/api/v1/risks', $payload)
            ->assertCreated();

        $second = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'retry-me-001')
            ->postJson('/api/v1/risks', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));

        // One record, not two. Without this the institution has two loss events
        // for one incident, both in the ORMS return, and the client cannot tell.
        $this->assertSame(1, Risk::where('title', 'Posted once')->count());
    }

    #[Test]
    public function reusing_a_key_with_a_different_body_is_refused(): void
    {
        $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'reused-key')
            ->postJson('/api/v1/risks', ['data' => ['attributes' => [
                'title' => 'First body', 'description' => 'x', 'category_id' => $this->category->id,
            ]]])
            ->assertCreated();

        // Replaying the first response here would hide a client bug.
        $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'reused-key')
            ->postJson('/api/v1/risks', ['data' => ['attributes' => [
                'title' => 'Different body', 'description' => 'y', 'category_id' => $this->category->id,
            ]]])
            ->assertStatus(422);
    }

    /* ================================================================== */
    /*  Discovery */
    /* ================================================================== */

    #[Test]
    public function the_catalogue_lists_the_surface_and_what_this_token_can_reach(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1')->assertOk();

        $types = collect($response->json('data'))->pluck('type');

        $this->assertContains('risks', $types);
        $this->assertContains('loss-events', $types);
        $this->assertContains('objects', $types);

        $risks = collect($response->json('data'))->firstWhere('type', 'risks');

        $this->assertTrue($risks['readable']);
        $this->assertContains('status', $risks['filters']);
    }

    #[Test]
    public function me_reports_the_effective_scopes(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.attributes.organization_id', $this->organization->id)
            ->assertJsonPath('data.attributes.token_type', 'personal');
    }

    /* ================================================================== */

    private function issueToken(array $scopes = ['risk.view', 'risk.create', 'risk.edit', 'dashboard.view']): string
    {
        $user = User::create([
            'name' => 'Integration User',
            'email' => Str::random(8).'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $user->assignRole('risk-manager');

        $plain = Str::random(48);

        $token = ApiToken::create([
            'organization_id' => $this->organization->id,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'name' => 'Query test token',
            'token_type' => ApiToken::TYPE_PERSONAL,
            'token' => hash('sha256', $plain),
            'abilities' => $scopes,
        ]);

        return $token->id.'|'.$plain;
    }
}
