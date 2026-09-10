<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\SsoProvisioningService;
use App\Support\Sso\SsoAuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

class SsoProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private SsoProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'SSO Bank PLC',
            'short_name' => 'SSOB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $this->service = app(SsoProvisioningService::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Who may sign in */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_existing_user_is_matched_by_email(): void
    {
        $existing = $this->localUser('ada@ssobank.test');

        $resolved = $this->service->resolve('entra', $this->config(), $this->idpUser('Ada@SSOBank.test'));

        $this->assertSame($existing->id, $resolved->id);
    }

    #[Test]
    public function a_deactivated_account_cannot_be_revived_by_the_identity_provider(): void
    {
        $this->localUser('gone@ssobank.test', active: false);

        $this->expectException(SsoAuthenticationException::class);
        $this->expectExceptionMessage('deactivated');

        $this->service->resolve('entra', $this->config(), $this->idpUser('gone@ssobank.test'));
    }

    #[Test]
    public function an_unknown_identity_is_refused_when_auto_provisioning_is_off(): void
    {
        config()->set('sso.auto_provision', false);

        $this->expectException(SsoAuthenticationException::class);
        $this->expectExceptionMessage('automatic provisioning is disabled');

        $this->service->resolve('entra', $this->config(), $this->idpUser('stranger@ssobank.test'));
    }

    #[Test]
    public function an_unknown_identity_is_provisioned_when_enabled(): void
    {
        config()->set('sso.auto_provision', true);
        config()->set('sso.default_organization_id', $this->org->id);

        $user = $this->service->resolve('entra', $this->config(), $this->idpUser('newcomer@ssobank.test', 'New Comer'));

        $this->assertSame('newcomer@ssobank.test', $user->email);
        $this->assertSame('New Comer', $user->name);
        $this->assertSame($this->org->id, $user->organization_id);
        $this->assertTrue($user->is_active);
    }

    #[Test]
    public function a_provisioned_account_cannot_be_used_with_a_local_password(): void
    {
        config()->set('sso.auto_provision', true);
        config()->set('sso.default_organization_id', $this->org->id);

        $user = $this->service->resolve('entra', $this->config(), $this->idpUser('nopassword@ssobank.test'));

        // The account gets a random secret precisely so the password path is
        // unusable; assert a few obvious guesses really do fail.
        foreach (['', 'password', 'nopassword@ssobank.test'] as $guess) {
            $this->assertFalse(Hash::check($guess, $user->password));
        }
    }

    #[Test]
    public function provisioning_without_a_default_organization_is_refused(): void
    {
        config()->set('sso.auto_provision', true);
        config()->set('sso.default_organization_id', null);

        // Tenancy has no ambient default, so there is nowhere safe to put them.
        $this->expectException(SsoAuthenticationException::class);
        $this->expectExceptionMessage('no organization is configured to receive new accounts');

        $this->service->resolve('entra', $this->config(), $this->idpUser('nowhere@ssobank.test'));
    }

    #[Test]
    public function a_domain_outside_the_allowlist_is_refused(): void
    {
        $this->localUser('outsider@elsewhere.test');

        $this->expectException(SsoAuthenticationException::class);
        $this->expectExceptionMessage('elsewhere.test');

        $this->service->resolve('entra', $this->config(['ssobank.test']), $this->idpUser('outsider@elsewhere.test'));
    }

    #[Test]
    public function a_domain_inside_the_allowlist_is_accepted_case_insensitively(): void
    {
        $user = $this->localUser('insider@ssobank.test');

        $resolved = $this->service->resolve('entra', $this->config(['SSOBank.TEST']), $this->idpUser('insider@ssobank.test'));

        $this->assertSame($user->id, $resolved->id);
    }

    #[Test]
    public function an_identity_provider_reporting_an_unverified_email_is_refused(): void
    {
        $this->localUser('unverified@ssobank.test');

        $idpUser = $this->idpUser('unverified@ssobank.test');
        $idpUser->email_verified = false;

        $this->expectException(SsoAuthenticationException::class);
        $this->expectExceptionMessage('unverified');

        $this->service->resolve('entra', $this->config(), $idpUser);
    }

    #[Test]
    public function a_user_with_no_organization_is_refused(): void
    {
        $user = $this->localUser('orphan@ssobank.test');
        $user->forceFill(['organization_id' => null])->saveQuietly();

        $this->expectException(SsoAuthenticationException::class);
        $this->expectExceptionMessage('no organization');

        $this->service->resolve('entra', $this->config(), $this->idpUser('orphan@ssobank.test'));
    }

    /* ------------------------------------------------------------------ */
    /*  Group -> role mapping */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function mapped_groups_become_roles(): void
    {
        config()->set('sso.role_map', [
            'GRC-Risk-Managers' => 'risk-manager',
            'GRC-Board' => 'board-member',
        ]);

        $user = $this->localUser('mapped@ssobank.test');

        $this->service->resolve('entra', $this->config(), $this->idpUser('mapped@ssobank.test', groups: ['GRC-Risk-Managers', 'GRC-Board']));

        $this->assertEqualsCanonicalizing(
            ['risk-manager', 'board-member'],
            $user->fresh()->roles->pluck('name')->all()
        );
    }

    #[Test]
    public function group_matching_ignores_case(): void
    {
        config()->set('sso.role_map', ['GRC-Risk-Managers' => 'risk-manager']);

        $user = $this->localUser('casing@ssobank.test');

        $this->service->resolve('entra', $this->config(), $this->idpUser('casing@ssobank.test', groups: ['grc-RISK-managers']));

        $this->assertTrue($user->fresh()->hasRole('risk-manager'));
    }

    #[Test]
    public function an_unmapped_group_grants_nothing(): void
    {
        config()->set('sso.role_map', ['GRC-Risk-Managers' => 'risk-manager']);

        // The map is an allowlist: naming a group in the directory must not be
        // a way to name a role here.
        $roles = $this->service->mapGroupsToRoles($this->idpUser('x@ssobank.test', groups: ['super-admin', 'Domain Admins']));

        $this->assertSame([], $roles);
    }

    #[Test]
    public function a_mapping_to_a_role_that_does_not_exist_is_dropped(): void
    {
        config()->set('sso.role_map', ['GRC-Ghost' => 'role-that-was-never-seeded']);

        $roles = $this->service->mapGroupsToRoles($this->idpUser('x@ssobank.test', groups: ['GRC-Ghost']));

        $this->assertSame([], $roles);
    }

    #[Test]
    public function a_user_removed_from_a_group_loses_the_role_on_next_login(): void
    {
        config()->set('sso.role_map', ['GRC-Risk-Managers' => 'risk-manager']);
        config()->set('sso.sync_roles_on_login', true);

        $user = $this->localUser('demoted@ssobank.test');
        $user->assignRole('risk-manager');

        // Signs in again, now belonging to no mapped group.
        $this->service->resolve('entra', $this->config(), $this->idpUser('demoted@ssobank.test', groups: ['Some-Other-Group']));

        $this->assertFalse($user->fresh()->hasRole('risk-manager'));
    }

    #[Test]
    public function locally_granted_roles_survive_when_sync_is_disabled(): void
    {
        config()->set('sso.role_map', ['GRC-Risk-Managers' => 'risk-manager']);
        config()->set('sso.sync_roles_on_login', false);

        $user = $this->localUser('kept@ssobank.test');
        $user->assignRole('compliance-officer');

        $this->service->resolve('entra', $this->config(), $this->idpUser('kept@ssobank.test', groups: ['GRC-Risk-Managers']));

        $this->assertEqualsCanonicalizing(
            ['compliance-officer', 'risk-manager'],
            $user->fresh()->roles->pluck('name')->all()
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<string>  $allowedDomains
     * @return array<string, mixed>
     */
    private function config(array $allowedDomains = []): array
    {
        return [
            'driver' => 'oidc',
            'groups_claim' => 'groups',
            'allowed_domains' => $allowedDomains,
        ];
    }

    /**
     * @param  list<string>  $groups
     */
    private function idpUser(string $email, string $name = 'IdP User', array $groups = []): object
    {
        return (object) [
            'id' => 'sub-'.md5($email),
            'email' => $email,
            'name' => $name,
            'groups' => $groups,
            'email_verified' => true,
            'user' => [],
        ];
    }

    private function localUser(string $email, bool $active = true): User
    {
        return User::create([
            'organization_id' => $this->org->id,
            'name' => 'Local User',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => $active,
        ]);
    }
}
