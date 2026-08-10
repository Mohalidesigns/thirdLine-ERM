<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ScimToken;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScimProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sso.scim.enabled', true);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->orgA = $this->organization('Alpha SCIM PLC', 'ASCIM');
        $this->orgB = $this->organization('Beta SCIM PLC', 'BSCIM');

        [, $this->tokenA] = TenantContext::actingAs(
            $this->orgA->id,
            fn () => ScimToken::issue($this->orgA->id, 'Test directory')
        );

        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Authentication */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function scim_requires_a_bearer_token(): void
    {
        $this->getJson('/scim/v2/Users')->assertStatus(401);
    }

    #[Test]
    public function an_invalid_token_is_refused(): void
    {
        $this->withToken('scim_not-a-real-token')
            ->getJson('/scim/v2/Users')
            ->assertStatus(401);
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        [$token, $plaintext] = TenantContext::actingAs(
            $this->orgA->id,
            fn () => ScimToken::issue($this->orgA->id, 'Expired', null, now()->subDay())
        );

        $this->assertTrue($token->isExpired());

        $this->withToken($plaintext)->getJson('/scim/v2/Users')->assertStatus(401);
    }

    #[Test]
    public function scim_is_unavailable_when_disabled(): void
    {
        config()->set('sso.scim.enabled', false);

        $this->withToken($this->tokenA)->getJson('/scim/v2/Users')->assertStatus(404);
    }

    #[Test]
    public function only_the_hash_of_a_token_is_stored(): void
    {
        $this->assertDatabaseMissing('scim_tokens', ['token_hash' => $this->tokenA]);
        $this->assertDatabaseHas('scim_tokens', ['token_hash' => ScimToken::hash($this->tokenA)]);
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_token_only_sees_its_own_organizations_users(): void
    {
        $mine = $this->user($this->orgA, 'mine@ascim.test');
        $theirs = $this->user($this->orgB, 'theirs@bscim.test');

        $response = $this->withToken($this->tokenA)->getJson('/scim/v2/Users')->assertOk();

        $ids = collect($response->json('Resources'))->pluck('id')->all();

        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $theirs->id, $ids);
    }

    #[Test]
    public function a_token_cannot_read_another_organizations_user_by_id(): void
    {
        $theirs = $this->user($this->orgB, 'hidden@bscim.test');

        $this->withToken($this->tokenA)
            ->getJson("/scim/v2/Users/{$theirs->id}")
            ->assertStatus(404);
    }

    #[Test]
    public function a_token_cannot_deactivate_another_organizations_user(): void
    {
        $theirs = $this->user($this->orgB, 'safe@bscim.test');

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Users/{$theirs->id}", [
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
            ])
            ->assertStatus(404);

        $this->assertTrue($theirs->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ */
    /*  Users */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_can_be_created(): void
    {
        $response = $this->withToken($this->tokenA)
            ->postJson('/scim/v2/Users', [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
                'userName' => 'Chidi@ascim.test',
                'name' => ['givenName' => 'Chidi', 'familyName' => 'Okonkwo'],
                'title' => 'Risk Analyst',
                'active' => true,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('userName', 'chidi@ascim.test');
        $response->assertJsonPath('displayName', 'Chidi Okonkwo');
        $response->assertJsonPath('active', true);

        $this->assertDatabaseHas('users', [
            'email' => 'chidi@ascim.test',
            'organization_id' => $this->orgA->id,
            'job_title' => 'Risk Analyst',
        ]);
    }

    #[Test]
    public function a_created_user_cannot_sign_in_with_a_guessable_password(): void
    {
        $this->withToken($this->tokenA)->postJson('/scim/v2/Users', [
            'userName' => 'nopass@ascim.test',
        ])->assertStatus(201);

        $user = User::query()->withoutGlobalScopes()->where('email', 'nopass@ascim.test')->first();

        foreach (['', 'password', 'nopass@ascim.test', 'changeme'] as $guess) {
            $this->assertFalse(Hash::check($guess, $user->password));
        }
    }

    #[Test]
    public function a_duplicate_username_is_a_conflict(): void
    {
        $this->user($this->orgA, 'taken@ascim.test');

        $this->withToken($this->tokenA)
            ->postJson('/scim/v2/Users', ['userName' => 'taken@ascim.test'])
            ->assertStatus(409)
            ->assertJsonPath('scimType', 'uniqueness');
    }

    #[Test]
    public function a_username_taken_in_another_organization_is_still_a_conflict(): void
    {
        // Email is the login identifier, so uniqueness is global. The response
        // must not reveal anything about the other tenant beyond the conflict.
        $this->user($this->orgB, 'shared@bscim.test');

        $response = $this->withToken($this->tokenA)
            ->postJson('/scim/v2/Users', ['userName' => 'shared@bscim.test'])
            ->assertStatus(409);

        $this->assertStringNotContainsString('Beta SCIM', json_encode($response->json()));
    }

    #[Test]
    public function an_invalid_username_is_rejected(): void
    {
        $this->withToken($this->tokenA)
            ->postJson('/scim/v2/Users', ['userName' => 'not-an-email'])
            ->assertStatus(400)
            ->assertJsonPath('scimType', 'invalidValue');
    }

    #[Test]
    public function a_user_can_be_deactivated_by_patch(): void
    {
        $user = $this->user($this->orgA, 'leaver@ascim.test');

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Users/{$user->id}", [
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
            ])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertFalse($user->fresh()->is_active);
    }

    #[Test]
    public function a_pathless_patch_body_is_also_understood(): void
    {
        // Entra ID sends {op: replace, value: {active: false}} with no path.
        $user = $this->user($this->orgA, 'entra-style@ascim.test');

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Users/{$user->id}", [
                'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
            ])
            ->assertOk();

        $this->assertFalse($user->fresh()->is_active);
    }

    #[Test]
    public function delete_deactivates_rather_than_destroying(): void
    {
        $user = $this->user($this->orgA, 'departed@ascim.test');

        $this->withToken($this->tokenA)
            ->deleteJson("/scim/v2/Users/{$user->id}")
            ->assertStatus(204);

        // The row survives: risks, assessments and audit entries reference it.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertFalse($user->fresh()->is_active);
    }

    #[Test]
    public function users_can_be_filtered_by_username(): void
    {
        $this->user($this->orgA, 'wanted@ascim.test');
        $this->user($this->orgA, 'unwanted@ascim.test');

        $response = $this->withToken($this->tokenA)
            ->getJson('/scim/v2/Users?filter='.urlencode('userName eq "wanted@ascim.test"'))
            ->assertOk();

        $this->assertSame(1, $response->json('totalResults'));
        $this->assertSame('wanted@ascim.test', $response->json('Resources.0.userName'));
    }

    #[Test]
    public function an_unsupported_filter_is_refused_rather_than_ignored(): void
    {
        // Silently dropping the filter would return the whole tenant to a
        // directory that asked for one user.
        $this->withToken($this->tokenA)
            ->getJson('/scim/v2/Users?filter='.urlencode('userName sw "a"'))
            ->assertStatus(400)
            ->assertJsonPath('scimType', 'invalidFilter');
    }

    /* ------------------------------------------------------------------ */
    /*  Groups */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function groups_list_the_application_roles(): void
    {
        $response = $this->withToken($this->tokenA)->getJson('/scim/v2/Groups')->assertOk();

        $names = collect($response->json('Resources'))->pluck('displayName')->all();

        $this->assertContains('risk-manager', $names);
        $this->assertContains('super-admin', $names);
    }

    #[Test]
    public function a_member_can_be_added_to_a_group(): void
    {
        $user = $this->user($this->orgA, 'promoted@ascim.test');
        $role = Role::where('name', 'risk-manager')->first();

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Groups/{$role->id}", [
                'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]],
            ])
            ->assertOk();

        $this->assertTrue($user->fresh()->hasRole('risk-manager'));
    }

    #[Test]
    public function a_member_can_be_removed_from_a_group(): void
    {
        $user = $this->user($this->orgA, 'revoked@ascim.test');
        $user->assignRole('risk-manager');
        $role = Role::where('name', 'risk-manager')->first();

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Groups/{$role->id}", [
                'Operations' => [['op' => 'remove', 'path' => 'members', 'value' => [['value' => (string) $user->id]]]],
            ])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasRole('risk-manager'));
    }

    #[Test]
    public function a_group_membership_change_cannot_reach_another_organization(): void
    {
        $theirs = $this->user($this->orgB, 'target@bscim.test');
        $role = Role::where('name', 'super-admin')->first();

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Groups/{$role->id}", [
                'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => (string) $theirs->id]]]],
            ])
            ->assertOk();

        $this->assertFalse(
            $theirs->fresh()->hasRole('super-admin'),
            "a token for one organization granted a role to another organization's user"
        );
    }

    #[Test]
    public function groups_cannot_be_created_over_scim(): void
    {
        // Roles are bound to permissions by the seeder; a directory admin must
        // not be able to mint an authorization principal.
        $this->withToken($this->tokenA)
            ->postJson('/scim/v2/Groups', ['displayName' => 'invented-role'])
            ->assertStatus(405);

        $this->assertDatabaseMissing('roles', ['name' => 'invented-role']);
    }

    #[Test]
    public function only_the_members_attribute_is_writable_on_a_group(): void
    {
        $role = Role::where('name', 'risk-manager')->first();

        $this->withToken($this->tokenA)
            ->patchJson("/scim/v2/Groups/{$role->id}", [
                'Operations' => [['op' => 'replace', 'path' => 'displayName', 'value' => 'renamed']],
            ])
            ->assertStatus(400)
            ->assertJsonPath('scimType', 'mutability');

        $this->assertSame('risk-manager', $role->fresh()->name);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function organization(string $name, string $short): Organization
    {
        return Organization::create([
            'name' => $name,
            'short_name' => $short,
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);
    }

    private function user(Organization $org, string $email): User
    {
        return TenantContext::actingAs($org->id, fn () => User::create([
            'organization_id' => $org->id,
            'name' => 'SCIM User',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]));
    }
}
