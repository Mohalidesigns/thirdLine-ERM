<?php

namespace App\Services;

use App\Models\User;
use App\Support\Sso\SsoAuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Turns an authenticated IdP identity into a local user, or refuses to.
 *
 * Deliberately protocol-agnostic: it is handed a plain array of settings and
 * an object carrying email/name/groups, so OIDC and SAML share one set of
 * security rules rather than each growing its own.
 *
 * Settings come from the client's own configuration row
 * (OrganizationSsoSetting::toProviderConfig) when one exists, and fall back to
 * config/sso.php for env-configured single-tenant installs. Every rule here is
 * a security decision that has its own test: which domains may sign in, which
 * groups grant which roles, whether an unknown identity may create an account,
 * and whether a deactivated account can be revived by a directory that still
 * knows it.
 */
class SsoProvisioningService
{
    /**
     * @param  array<string, mixed>  $providerConfig
     *
     * @throws SsoAuthenticationException
     */
    public function resolve(string $provider, array $providerConfig, object $idpUser): User
    {
        $email = strtolower(trim((string) ($idpUser->email ?? '')));

        if ($email === '') {
            throw new SsoAuthenticationException('The identity provider did not return an email address.');
        }

        // Only enforce email_verified when the IdP actually asserts it. Some
        // deployments omit the claim; treating "absent" as "unverified" would
        // lock out otherwise correct configurations.
        $verified = $idpUser->email_verified ?? ($idpUser->user['email_verified'] ?? null);

        if ($verified === false) {
            throw new SsoAuthenticationException('The identity provider reports this email address as unverified.');
        }

        $this->assertDomainAllowed($email, $providerConfig);

        $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();

        if (! $user) {
            $user = $this->provision($provider, $email, $idpUser, $providerConfig);
        }

        // A user who already exists in a different organization must not be
        // adopted by this one just because its IdP asserted their address.
        $expectedOrganizationId = $providerConfig['organization_id'] ?? null;

        if ($expectedOrganizationId !== null && (int) $user->organization_id !== (int) $expectedOrganizationId) {
            throw new SsoAuthenticationException('This account belongs to a different organization.');
        }

        // A deactivated account stays deactivated. The IdP saying someone
        // exists is not the same as this platform saying they may sign in —
        // offboarding here must not be undone by a stale directory entry.
        if (! $user->is_active) {
            throw new SsoAuthenticationException('This account has been deactivated.');
        }

        if (empty($user->organization_id)) {
            throw new SsoAuthenticationException('This account has no organization assigned.');
        }

        $this->syncRoles($user, $idpUser, $providerConfig);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     *
     * @throws SsoAuthenticationException
     */
    private function assertDomainAllowed(string $email, array $providerConfig): void
    {
        $allowed = array_filter(array_map('trim', $providerConfig['allowed_domains'] ?? []));

        if ($allowed === []) {
            return;
        }

        $domain = Str::afterLast($email, '@');

        foreach ($allowed as $candidate) {
            if (strcasecmp($domain, ltrim($candidate, '@')) === 0) {
                return;
            }
        }

        throw new SsoAuthenticationException("Sign-in from the domain \"{$domain}\" is not permitted.");
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     *
     * @throws SsoAuthenticationException
     */
    private function provision(string $provider, string $email, object $idpUser, array $providerConfig): User
    {
        $autoProvision = $providerConfig['auto_provision'] ?? config('sso.auto_provision');

        if (! $autoProvision) {
            throw new SsoAuthenticationException(
                'No account exists for this identity, and automatic provisioning is disabled. '
                .'Ask an administrator to create the account first.'
            );
        }

        // The organization comes from the client's own configuration row; the
        // env default only applies to single-tenant installs that have none.
        $organizationId = $providerConfig['organization_id'] ?? config('sso.default_organization_id');

        if (empty($organizationId)) {
            throw new SsoAuthenticationException(
                'Automatic provisioning is enabled but no organization is configured to receive new accounts. '
                .'There is no default tenant to place the account in.'
            );
        }

        // Bypass tenancy explicitly: the user is being created before any
        // tenant is resolved, because resolving the tenant is what this
        // account will later be used for.
        return TenantContext::bypass(function () use ($email, $idpUser, $organizationId) {
            return User::create([
                'organization_id' => (int) $organizationId,
                'name' => (string) ($idpUser->name ?: Str::before($email, '@')),
                'email' => $email,
                // Not a usable credential: a random secret means the local
                // password path can never authenticate this account, only SSO
                // or an explicit reset can.
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'must_change_password' => false,
            ]);
        }, "SSO provisioning via {$provider}");
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    private function syncRoles(User $user, object $idpUser, array $providerConfig = []): void
    {
        $roles = $this->mapGroupsToRoles($idpUser, $providerConfig['role_map'] ?? null);

        // A brand-new identity that matches no group still needs somewhere to
        // land, or the account exists but can reach nothing.
        if ($roles === [] && $user->roles->isEmpty()) {
            $roles = (array) ($providerConfig['default_roles'] ?? config('sso.default_roles', []));
        }

        $sync = $providerConfig['sync_roles_on_login'] ?? config('sso.sync_roles_on_login');

        if ($sync) {
            // Applied even when the mapped set is empty. Returning early here
            // meant a user removed from every group in the directory kept the
            // roles that membership had granted them — which defeats the point
            // of syncing on login.
            $user->syncRoles($roles);

            return;
        }

        if ($roles !== []) {
            $user->assignRole($roles);
        }
    }

    /**
     * Map IdP groups to application roles.
     *
     * The map is an allowlist. A group with no entry contributes nothing, and
     * a value that is not a role this application defines is dropped — so
     * whoever can name a group in the directory still cannot name a role here.
     *
     * @param  array<string, string>|null  $roleMap
     * @return list<string>
     */
    public function mapGroupsToRoles(object $idpUser, ?array $roleMap = null): array
    {
        $groups = $idpUser->groups ?? ($idpUser->user['groups'] ?? []);

        if (! is_array($groups) || $groups === []) {
            return [];
        }

        $map = collect($roleMap ?? config('sso.role_map', []))
            ->mapWithKeys(fn ($role, $group) => [strtolower((string) $group) => $role]);

        $known = Role::query()->pluck('name')->map('strtolower')->all();

        return collect($groups)
            ->map(fn ($group) => $map->get(strtolower((string) $group)))
            ->filter()
            ->filter(fn (string $role) => in_array(strtolower($role), $known, true))
            ->unique()
            ->values()
            ->all();
    }
}
