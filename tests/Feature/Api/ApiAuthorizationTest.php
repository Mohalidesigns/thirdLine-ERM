<?php

namespace Tests\Feature\Api;

use App\Http\Api\ApiResourceRegistry;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-07 TASK 2 acceptance: no unguarded API endpoint, and no token that can
 * exceed the person behind it.
 *
 * The structural test is the one that keeps working as endpoints are added; the
 * behavioural ones below cover the four ways an API leaks on a multi-tenant
 * platform — no token, a revoked token, a token whose owner has left, and a
 * token pointed at another organization's data.
 */
class ApiAuthorizationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->bootDomainFixtures();
    }

    /* ================================================================== */
    /*  Structure */
    /* ================================================================== */

    #[Test]
    public function every_api_v1_route_carries_a_scope_guard(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $guarded = collect($middleware)->contains(fn ($m) => is_string($m) && (
                str_starts_with($m, 'scope:') || $m === 'scope.resource'
            ));

            $authenticated = collect($middleware)->contains(fn ($m) => is_string($m) && $m === 'api.auth');

            if (! $guarded || ! $authenticated) {
                $unguarded[] = implode('|', $route->methods()).' '.$route->uri()
                    .($authenticated ? ' (no scope)' : ' (no authentication)');
            }
        }

        $this->assertSame([], $unguarded, "API routes without an authorization guard:\n  ".implode("\n  ", $unguarded));
    }

    #[Test]
    public function every_registered_resource_names_a_permission_that_exists(): void
    {
        $known = \Spatie\Permission\Models\Permission::pluck('name')->all();
        $missing = [];

        foreach (ApiResourceRegistry::all() as $name => $definition) {
            foreach ($definition['permissions'] as $action => $permission) {
                if (! in_array($permission, $known, true)) {
                    $missing[$name][] = $action.' => '.$permission;
                }
            }

            // A writable resource with no create permission would accept a POST
            // that nothing authorizes, which is the inverse mistake and just as
            // easy to make.
            if (($definition['writable'] ?? []) !== [] && ! isset($definition['permissions']['create'])) {
                $missing[$name][] = 'writable but has no create permission';
            }
        }

        // A scope naming a permission the seeder never creates fails closed —
        // safe, but it silently makes the endpoint unreachable for everybody
        // and nothing says why.
        $this->assertSame([], $missing, 'Resources name permissions that do not exist: '.json_encode($missing, JSON_PRETTY_PRINT));
    }

    /* ================================================================== */
    /*  Behaviour */
    /* ================================================================== */

    #[Test]
    public function a_request_with_no_token_is_refused(): void
    {
        $this->getJson('/api/v1/risks')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.title', 'Unauthenticated');
    }

    #[Test]
    public function a_revoked_token_is_refused(): void
    {
        [$token, $plain] = $this->issueToken(['risk.view']);
        $token->revoke();

        $this->withToken($plain)->getJson('/api/v1/risks')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.detail', 'That token has been revoked.');
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        [, $plain] = $this->issueToken(['risk.view'], expiresAt: now()->subDay());

        $this->withToken($plain)->getJson('/api/v1/risks')->assertStatus(401);
    }

    #[Test]
    public function a_token_whose_owner_has_been_deactivated_is_refused(): void
    {
        [$token, $plain] = $this->issueToken(['risk.view']);

        $token->actingUser()->update(['is_active' => false]);

        // The case that actually happens: somebody leaves, their account is
        // deactivated, and nobody remembers the token exists.
        $this->withToken($plain)->getJson('/api/v1/risks')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.detail', 'The user this token belongs to is no longer active.');
    }

    #[Test]
    public function a_token_without_the_scope_is_refused(): void
    {
        [, $plain] = $this->issueToken(['control.view']);

        $this->withToken($plain)->getJson('/api/v1/risks')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.title', 'Forbidden');
    }

    #[Test]
    public function a_scope_cannot_exceed_the_permission_of_the_user_behind_it(): void
    {
        // The token asks for everything; the user is a risk-analyst, who may
        // view risks but not delete them.
        [, $plain] = $this->issueToken(['*'], role: 'risk-analyst');

        $this->withToken($plain)->getJson('/api/v1/risks')->assertOk();

        // risk.delete is not held by risk-analyst, so the * scope grants
        // nothing — which is the only safe direction for a credential a user
        // can mint for themselves.
        $this->withToken($plain)->deleteJson('/api/v1/risks/1')->assertStatus(405);

        $response = $this->withToken($plain)->getJson('/api/v1/me');
        $effective = $response->json('data.attributes.effective');

        $this->assertNotContains('risk.delete', $effective ?? []);
    }

    #[Test]
    public function a_token_cannot_reach_another_organizations_records(): void
    {
        $mine = $this->makeRisk(['title' => 'My tenant risk']);

        $otherOrg = \App\Support\Tenancy\TenantContext::bypass(fn () => Organization::create([
            'name' => 'Other Bank PLC',
            'short_name' => 'OTHR',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]), 'test fixture');

        $theirs = \App\Support\Tenancy\TenantContext::actingAs($otherOrg->id, function () use ($otherOrg) {
            $category = \App\Models\RiskCategory::create([
                'organization_id' => $otherOrg->id,
                'code' => 'OPS',
                'name' => 'Operational Risk',
            ]);

            return \App\Models\Risk::create([
                'organization_id' => $otherOrg->id,
                'risk_code' => 'RK-OTHER-0001',
                'title' => 'Their tenant risk',
                'description' => 'A risk belonging to another organization entirely.',
                'category_id' => $category->id,
                'status' => 'active',
            ]);
        });

        [, $plain] = $this->issueToken(['risk.view']);

        $listed = $this->withToken($plain)->getJson('/api/v1/risks')->assertOk()->json('data');

        $this->assertNotEmpty($listed);
        $this->assertContains((string) $mine->id, collect($listed)->pluck('id')->all());
        $this->assertNotContains((string) $theirs->id, collect($listed)->pluck('id')->all());

        // And not by id either — there is no parameter that widens the tenant.
        $this->withToken($plain)->getJson('/api/v1/risks/'.$theirs->id)->assertStatus(404);
    }

    #[Test]
    public function a_token_with_no_organization_is_refused(): void
    {
        [$token, $plain] = $this->issueToken(['risk.view']);

        $token->forceFill(['organization_id' => null])->save();

        $this->withToken($plain)->getJson('/api/v1/risks')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.detail', 'That token has no organization and cannot be used.');
    }

    /* ================================================================== */

    /** @param list<string> $scopes @return array{0: ApiToken, 1: string} */
    private function issueToken(array $scopes, string $role = 'risk-manager', $expiresAt = null): array
    {
        $user = User::create([
            'name' => 'API User '.Str::random(4),
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
            'name' => 'Test token',
            'token_type' => ApiToken::TYPE_PERSONAL,
            'token' => hash('sha256', $plain),
            'abilities' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $token->id.'|'.$plain];
    }
}
