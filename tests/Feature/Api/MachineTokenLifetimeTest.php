<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * A client_credentials token must expire, and it may not ask for everything.
 *
 * WHY BOTH RULES APPLY ONLY TO MACHINE TOKENS. A personal token is bounded by
 * the person behind it — ApiToken::permits() intersects its abilities with its
 * owner's permissions, and AuthenticateApiToken refuses it once that owner is
 * deactivated — so a `*` personal token issued to a risk-analyst still cannot
 * delete a risk, and the leaver process disarms it. A client_credentials token
 * acts as NOBODY. Its abilities are the whole of its authority, there is no
 * second check, and no leaver process ever touches it.
 *
 * PREVIOUS BEHAVIOUR: `expires_at` was nullable and left null by every creation
 * path that did not pass a lifetime, config/sanctum.php sets
 * `'expiration' => null`, and permits() honoured `*` identically for both kinds
 * — so `php artisan api:token "Nightly feed" --machine --organization=1` minted
 * a credential with unrestricted access to that tenant and no end date.
 *
 * ApiAuthorizationTest covers the personal-token side and must keep passing;
 * nothing here changes it.
 */
class MachineTokenLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Token Bank PLC',
            'short_name' => 'TOKB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Lifetime */
    /* ================================================================== */

    #[Test]
    public function a_machine_token_created_without_a_lifetime_is_given_one(): void
    {
        $token = $this->machineToken(['risk.view']);

        $this->assertNotNull(
            $token->expires_at,
            'A client_credentials token with no expiry is a permanent credential that acts as nobody.'
        );

        $this->assertEqualsWithDelta(
            ApiToken::MACHINE_DEFAULT_LIFETIME_DAYS,
            now()->diffInDays($token->expires_at),
            1,
        );
    }

    #[Test]
    public function a_machine_token_cannot_be_given_an_effectively_unlimited_lifetime(): void
    {
        // The admin form accepts up to 3,650 days, which for a credential with
        // no user behind it is indistinguishable from never expiring.
        $token = $this->machineToken(['risk.view'], expiresAt: now()->addDays(3650));

        $this->assertEqualsWithDelta(
            ApiToken::MACHINE_MAX_LIFETIME_DAYS,
            now()->diffInDays($token->expires_at),
            1,
        );
    }

    #[Test]
    public function a_shorter_lifetime_is_left_alone(): void
    {
        // The ceiling is a cap, not a replacement — an integration that rotates
        // weekly must be able to say so.
        $token = $this->machineToken(['risk.view'], expiresAt: now()->addDays(7));

        $this->assertEqualsWithDelta(7, now()->diffInDays($token->expires_at), 1);
    }

    #[Test]
    public function a_personal_token_is_not_given_a_forced_expiry(): void
    {
        // Deliberately unchanged. A personal token is already bounded by its
        // owner's account, and ApiAuthorizationTest issues them without one.
        $token = ApiToken::create([
            'organization_id' => $this->org->id,
            'tokenable_type' => $this->user()->getMorphClass(),
            'tokenable_id' => $this->user()->id,
            'name' => 'Personal token',
            'token_type' => ApiToken::TYPE_PERSONAL,
            'token' => hash('sha256', Str::random(48)),
            'abilities' => ['risk.view'],
        ]);

        $this->assertNull($token->expires_at);
    }

    /* ================================================================== */
    /*  Scopes */
    /* ================================================================== */

    #[Test]
    public function a_machine_token_cannot_be_issued_with_the_wildcard_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/may not be issued with the \* scope/');

        $this->machineToken(['*']);
    }

    #[Test]
    public function the_refusal_covers_a_wildcard_hidden_in_a_longer_list(): void
    {
        // `['risk.view', '*']` reads as a narrow grant and is not one.
        $this->expectException(InvalidArgumentException::class);

        $this->machineToken(['risk.view', '*']);
    }

    #[Test]
    public function a_personal_token_may_still_hold_the_wildcard(): void
    {
        // It means "everything I can do", and permits() intersects it with the
        // owner's permissions. ApiAuthorizationTest asserts that intersection.
        $user = $this->user();

        $token = ApiToken::create([
            'organization_id' => $this->org->id,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'name' => 'Personal wildcard',
            'token_type' => ApiToken::TYPE_PERSONAL,
            'token' => hash('sha256', Str::random(48)),
            'abilities' => ['*'],
        ]);

        $this->assertTrue($token->permits('risk.view'));
    }

    #[Test]
    public function a_machine_token_that_already_holds_the_wildcard_is_not_honoured(): void
    {
        // THE UPGRADE CASE, and the one that matters most: refusing to issue
        // new wildcards does nothing about the credentials already sitting in
        // customers' schedulers. Written past the creating hook on purpose —
        // this is a row that predates the rule.
        $token = $this->machineToken(['risk.view']);
        $token->forceFill(['abilities' => ['*']])->saveQuietly();
        $token->refresh();

        $this->assertFalse(
            $token->permits('risk.view'),
            'A machine token has no user behind it, so * cannot be narrowed by anything and must not be honoured.'
        );

        // Sanctum's own can() still answers true for any ability when * is
        // present, which is exactly why permits() cannot delegate to it.
        $this->assertTrue($token->can('risk.view'));
    }

    #[Test]
    public function a_machine_token_with_named_scopes_works_exactly_as_before(): void
    {
        $token = $this->machineToken(['risk.view']);

        $this->assertTrue($token->permits('risk.view'));
        $this->assertFalse($token->permits('risk.delete'));
    }

    /* ================================================================== */
    /*  The admin screen answers rather than throwing */
    /* ================================================================== */

    #[Test]
    public function the_admin_screen_refuses_a_wildcard_machine_token_with_an_explanation(): void
    {
        $admin = $this->user();
        $admin->givePermissionTo('api.tokens', 'api.tokens.manage');

        $this->actingAs($admin)
            ->from(route('admin.api-tokens.index'))
            ->post(route('admin.api-tokens.store'), [
                'name' => 'Everything feed',
                'scopes' => ['*'],
                'token_type' => 'client_credentials',
            ])
            ->assertRedirect(route('admin.api-tokens.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, ApiToken::query()->where('name', 'Everything feed')->count());
    }

    #[Test]
    public function the_admin_screen_issues_a_machine_token_with_a_default_expiry(): void
    {
        $admin = $this->user();
        $admin->givePermissionTo('api.tokens', 'api.tokens.manage');

        $this->actingAs($admin)
            ->post(route('admin.api-tokens.store'), [
                'name' => 'Nightly KRI feed',
                'scopes' => ['measure.view'],
                'token_type' => 'client_credentials',
            ])
            ->assertSessionHas('success');

        $token = ApiToken::query()->where('name', 'Nightly KRI feed')->firstOrFail();

        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            ApiToken::MACHINE_DEFAULT_LIFETIME_DAYS,
            now()->diffInDays($token->expires_at),
            1,
        );
    }

    /* ================================================================== */

    /** @param list<string> $scopes */
    private function machineToken(array $scopes, $expiresAt = null): ApiToken
    {
        return ApiToken::create([
            'organization_id' => $this->org->id,
            'tokenable_type' => null,
            'tokenable_id' => null,
            'name' => 'Machine token '.Str::random(4),
            'token_type' => ApiToken::TYPE_CLIENT,
            'client_id' => 'cid_'.Str::random(24),
            'token' => hash('sha256', Str::random(48)),
            'abilities' => $scopes,
            'expires_at' => $expiresAt,
        ]);
    }

    private function user(): User
    {
        return $this->user ??= tap(User::create([
            'organization_id' => $this->org->id,
            'name' => 'Token Admin',
            'email' => 'tokens@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]), fn (User $u) => $u->assignRole('risk-manager'));
    }
}
