<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationSsoSetting;
use App\Models\User;
use App\Support\Sso\SamlDriver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Single sign-on is configured by each client in the admin UI after purchase,
 * so the configuration is per-organization data — which means it needs the
 * same tenancy and secrecy guarantees as everything else in the platform.
 */
class OrganizationSsoSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sso.enabled', true);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->orgA = $this->organization('Alpha Bank PLC', 'ALPHA');
        $this->orgB = $this->organization('Beta Bank PLC', 'BETA');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution before authentication */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_configuration_is_resolvable_by_slug_with_no_one_signed_in(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha']);

        TenantContext::clear();

        $resolved = OrganizationSsoSetting::resolveBySlug('alpha');

        $this->assertNotNull($resolved);
        $this->assertSame($this->orgA->id, $resolved->organization_id);
    }

    #[Test]
    public function a_disabled_configuration_does_not_resolve(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'enabled' => false]);

        $this->assertNull(OrganizationSsoSetting::resolveBySlug('alpha'));
    }

    #[Test]
    public function an_email_domain_resolves_to_the_organization_that_claims_it(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'allowed_domains' => ['alphabank.test']]);
        $this->oidcSetting($this->orgB, ['slug' => 'beta', 'allowed_domains' => ['betabank.test']]);

        $this->assertSame('beta', OrganizationSsoSetting::resolveByEmailDomain('someone@BetaBank.test')?->slug);
        $this->assertSame('alpha', OrganizationSsoSetting::resolveByEmailDomain('someone@alphabank.test')?->slug);
        $this->assertNull(OrganizationSsoSetting::resolveByEmailDomain('someone@unknown.test'));
    }

    #[Test]
    public function a_configuration_with_no_domains_is_not_discoverable_by_email(): void
    {
        // Otherwise one client's federation would swallow every address that
        // matched nothing else.
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'allowed_domains' => []]);

        $this->assertNull(OrganizationSsoSetting::resolveByEmailDomain('someone@anything.test'));
    }

    #[Test]
    public function discovery_does_not_reveal_whether_a_domain_is_federated(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'allowed_domains' => ['alphabank.test']]);

        $this->post('/auth/sso/discover', ['email' => 'nobody@unknown.test'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Single sign-on is not available for that email address.');
    }

    #[Test]
    public function discovery_sends_a_known_domain_to_its_organizations_sign_in_url(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'allowed_domains' => ['alphabank.test']]);

        $this->post('/auth/sso/discover', ['email' => 'staff@alphabank.test'])
            ->assertRedirect(url('/auth/sso/alpha'));
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy and secrecy */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_administrator_cannot_see_another_organizations_configuration(): void
    {
        $this->oidcSetting($this->orgB, ['slug' => 'beta']);

        TenantContext::set($this->orgA->id);

        $this->assertSame(0, OrganizationSsoSetting::query()->count());
    }

    #[Test]
    public function slugs_are_unique_across_organizations(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'shared']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->oidcSetting($this->orgB, ['slug' => 'shared']);
    }

    #[Test]
    public function secrets_are_encrypted_at_rest(): void
    {
        $setting = $this->oidcSetting($this->orgA, [
            'slug' => 'alpha',
            'oidc_client_secret' => 'super-secret-value',
        ]);

        $raw = DB::table('organization_sso_settings')->where('id', $setting->id)->value('oidc_client_secret');

        $this->assertNotSame('super-secret-value', $raw, 'the client secret is stored in clear text');
        $this->assertStringNotContainsString('super-secret-value', (string) $raw);
        $this->assertSame('super-secret-value', $setting->fresh()->oidc_client_secret);
    }

    #[Test]
    public function the_settings_form_never_renders_a_stored_secret(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'oidc_client_secret' => 'super-secret-value']);

        $html = $this->actingAs($this->admin($this->orgA))
            ->get('/admin/settings/sso')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('super-secret-value', $html);
    }

    /* ------------------------------------------------------------------ */
    /*  Admin UI */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_sso_screen_requires_its_own_permission(): void
    {
        $user = $this->userFor($this->orgA, 'risk-manager');

        $this->actingAs($user)->get('/admin/settings/sso')->assertForbidden();
    }

    #[Test]
    public function saving_settings_creates_the_configuration(): void
    {
        $admin = $this->admin($this->orgA);

        $this->actingAs($admin)->put('/admin/settings/sso', [
            'enabled' => '1',
            'driver' => 'oidc',
            'label' => 'Sign in with Microsoft',
            'slug' => 'alpha-bank',
            'oidc_client_id' => 'client-123',
            'oidc_client_secret' => 'secret-456',
            'oidc_auth_url' => 'https://login.microsoftonline.com/t/oauth2/v2.0/authorize',
            'oidc_token_url' => 'https://login.microsoftonline.com/t/oauth2/v2.0/token',
            'oidc_userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'allowed_domains' => 'alphabank.test, alpha.example',
            'role_map' => [['group' => 'GRC-Admins', 'role' => 'super-admin']],
            'sync_roles_on_login' => '1',
        ])->assertRedirect();

        $setting = OrganizationSsoSetting::resolveBySlug('alpha-bank');

        $this->assertNotNull($setting);
        $this->assertTrue($setting->enabled);
        $this->assertSame($this->orgA->id, $setting->organization_id);
        $this->assertSame(['alphabank.test', 'alpha.example'], $setting->allowed_domains);
        $this->assertSame(['GRC-Admins' => 'super-admin'], $setting->role_map);
    }

    #[Test]
    public function a_blank_secret_field_keeps_the_stored_secret(): void
    {
        $admin = $this->admin($this->orgA);
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'oidc_client_secret' => 'keep-me']);

        $this->actingAs($admin)->put('/admin/settings/sso', $this->validPayload(['slug' => 'alpha']));

        $this->assertSame('keep-me', OrganizationSsoSetting::resolveBySlug('alpha')->oidc_client_secret);
    }

    #[Test]
    public function a_secret_can_be_cleared_explicitly(): void
    {
        $admin = $this->admin($this->orgA);
        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'oidc_client_secret' => 'remove-me']);

        $this->actingAs($admin)->put('/admin/settings/sso', $this->validPayload([
            'slug' => 'alpha',
            'clear_oidc_client_secret' => '1',
        ]));

        $setting = $this->stored('alpha');

        $this->assertNull($setting->oidc_client_secret);

        // Removing a required credential also takes the provider out of
        // service, rather than leaving a sign-in button that cannot work.
        $this->assertFalse($setting->enabled);
    }

    #[Test]
    public function a_group_cannot_be_mapped_to_a_role_that_does_not_exist(): void
    {
        // The map is an allowlist — a client must not be able to invent an
        // authorization principal by typing its name.
        $admin = $this->admin($this->orgA);

        $this->actingAs($admin)->put('/admin/settings/sso', $this->validPayload([
            'slug' => 'alpha',
            'role_map' => [['group' => 'GRC-Ghosts', 'role' => 'not-a-real-role']],
        ]));

        $this->assertSame([], $this->stored('alpha')->role_map);
    }

    /**
     * The stored row regardless of whether it is currently enabled — the
     * enabled-only resolver is not the right lens for assertions about saving.
     */
    private function stored(string $slug): OrganizationSsoSetting
    {
        return OrganizationSsoSetting::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
    }

    #[Test]
    public function enabling_a_half_configured_provider_is_refused(): void
    {
        $admin = $this->admin($this->orgA);

        $this->actingAs($admin)->put('/admin/settings/sso', [
            'enabled' => '1',
            'driver' => 'saml',
            'label' => 'SAML',
            'slug' => 'alpha',
            // No IdP entity id, sign-on URL or certificate.
        ])->assertSessionHas('warning');

        $setting = OrganizationSsoSetting::query()->withoutGlobalScopes()->where('slug', 'alpha')->first();

        $this->assertFalse($setting->enabled, 'a provider missing required fields must not be advertised as enabled');
    }

    #[Test]
    public function insecure_endpoint_urls_are_rejected(): void
    {
        $admin = $this->admin($this->orgA);

        $this->actingAs($admin)->put('/admin/settings/sso', $this->validPayload([
            'slug' => 'alpha',
            'oidc_auth_url' => 'http://login.example.test/authorize',
        ]))->assertSessionHasErrors('oidc_auth_url');
    }

    /* ------------------------------------------------------------------ */
    /*  SAML */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function saml_metadata_is_generated_and_served(): void
    {
        $this->samlSetting($this->orgA, ['slug' => 'alpha']);

        $response = $this->get('/auth/sso/alpha/metadata')->assertOk();

        $xml = $response->getContent();

        $this->assertStringContainsString('<md:EntityDescriptor', $xml);
        $this->assertStringContainsString(url('/auth/sso/alpha/acs'), $xml);
        $this->assertStringContainsString('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST', $xml);
    }

    #[Test]
    public function saml_metadata_is_not_served_for_an_oidc_organization(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha']);

        $this->get('/auth/sso/alpha/metadata')->assertNotFound();
    }

    #[Test]
    public function saml_sign_in_redirects_to_the_identity_provider(): void
    {
        $this->samlSetting($this->orgA, ['slug' => 'alpha']);

        $response = $this->get('/auth/sso/alpha');

        $response->assertRedirectContains('https://idp.example.test/sso');
        $response->assertRedirectContains('SAMLRequest=');
    }

    #[Test]
    public function an_unsigned_or_forged_assertion_is_rejected(): void
    {
        $this->samlSetting($this->orgA, ['slug' => 'alpha']);

        // A response the IdP never signed. The toolkit must refuse it; the user
        // must not be signed in.
        $forged = base64_encode(
            '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '.
            'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_forged" Version="2.0" '.
            'IssueInstant="'.now()->toIso8601String().'" Destination="'.url('/auth/sso/alpha/acs').'">'.
            '<saml:Issuer>https://idp.example.test/metadata</saml:Issuer>'.
            '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'.
            '<saml:Assertion ID="_a" Version="2.0" IssueInstant="'.now()->toIso8601String().'">'.
            '<saml:Issuer>https://idp.example.test/metadata</saml:Issuer>'.
            '<saml:Subject><saml:NameID>attacker@alphabank.test</saml:NameID></saml:Subject>'.
            '</saml:Assertion></samlp:Response>'
        );

        $this->post('/auth/sso/alpha/acs', ['SAMLResponse' => $forged])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_empty_assertion_post_does_not_sign_anyone_in(): void
    {
        $this->samlSetting($this->orgA, ['slug' => 'alpha']);

        $this->post('/auth/sso/alpha/acs', [])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_acs_endpoint_rejects_an_organization_configured_for_oidc(): void
    {
        $this->oidcSetting($this->orgA, ['slug' => 'alpha']);

        $this->post('/auth/sso/alpha/acs', ['SAMLResponse' => 'x'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'This organization is not configured for SAML sign-in.');
    }

    #[Test]
    public function saml_settings_demand_signed_assertions(): void
    {
        $setting = $this->samlSetting($this->orgA, ['slug' => 'alpha']);

        // Reach into the driver's settings to prove the security posture is not
        // silently permissive — the single most consequential SAML setting.
        $driver = new SamlDriver($setting);
        $reflection = new \ReflectionMethod($driver, 'settingsArray');
        $settings = $reflection->invoke($driver);

        $this->assertTrue($settings['strict']);
        $this->assertTrue($settings['security']['wantAssertionsSigned']);
        $this->assertTrue($settings['security']['rejectUnsolicitedResponsesWithInResponseTo']);
    }

    /* ------------------------------------------------------------------ */
    /*  Sign-in flow */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_unknown_slug_is_refused(): void
    {
        $this->get('/auth/sso/does-not-exist')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Unknown or disabled single sign-on provider.');
    }

    #[Test]
    public function sso_is_refused_when_the_deployment_switch_is_off(): void
    {
        config()->set('sso.enabled', false);
        $this->oidcSetting($this->orgA, ['slug' => 'alpha']);

        $this->get('/auth/sso/alpha')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Single sign-on is not enabled for this deployment.');
    }

    #[Test]
    public function the_login_page_only_offers_sso_when_some_client_has_configured_it(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Continue with single sign-on');

        $this->oidcSetting($this->orgA, ['slug' => 'alpha', 'allowed_domains' => ['alphabank.test']]);

        $this->get('/login')->assertOk()->assertSee('Continue with single sign-on');
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => '1',
            'driver' => 'oidc',
            'label' => 'Single sign-on',
            'slug' => 'alpha',
            'oidc_client_id' => 'client-123',
            'oidc_auth_url' => 'https://idp.example.test/authorize',
            'oidc_token_url' => 'https://idp.example.test/token',
            'oidc_userinfo_url' => 'https://idp.example.test/userinfo',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function oidcSetting(Organization $org, array $attributes = []): OrganizationSsoSetting
    {
        return TenantContext::actingAs($org->id, fn () => OrganizationSsoSetting::create(array_merge([
            'organization_id' => $org->id,
            'enabled' => true,
            'driver' => 'oidc',
            'label' => 'Single sign-on',
            'oidc_client_id' => 'client-123',
            'oidc_client_secret' => 'secret-456',
            'oidc_auth_url' => 'https://idp.example.test/authorize',
            'oidc_token_url' => 'https://idp.example.test/token',
            'oidc_userinfo_url' => 'https://idp.example.test/userinfo',
        ], $attributes)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function samlSetting(Organization $org, array $attributes = []): OrganizationSsoSetting
    {
        return TenantContext::actingAs($org->id, fn () => OrganizationSsoSetting::create(array_merge([
            'organization_id' => $org->id,
            'enabled' => true,
            'driver' => 'saml',
            'label' => 'SAML',
            'saml_idp_entity_id' => 'https://idp.example.test/metadata',
            'saml_idp_sso_url' => 'https://idp.example.test/sso',
            'saml_idp_x509_cert' => self::TEST_CERTIFICATE,
        ], $attributes)));
    }

    private function admin(Organization $org): User
    {
        return $this->userFor($org, 'super-admin');
    }

    private function userFor(Organization $org, string $role): User
    {
        $user = TenantContext::actingAs($org->id, fn () => User::create([
            'organization_id' => $org->id,
            'name' => 'Admin',
            'email' => strtolower($role).'-'.$org->id.'@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]));

        $user->assignRole($role);

        return $user;
    }

    /**
     * A self-signed certificate used only as an IdP signing key in tests. It
     * verifies nothing real — its job is to make the toolkit's configuration
     * valid so metadata can be generated and forged assertions rejected.
     */
    private const TEST_CERTIFICATE = 'MIICajCCAdOgAwIBAgIBADANBgkqhkiG9w0BAQ0FADBSMQswCQYDVQQGEwJ1czET
MBEGA1UECAwKQ2FsaWZvcm5pYTEVMBMGA1UECgwMT25lbG9naW4gSW5jMRcwFQYD
VQQDDA5zcC5leGFtcGxlLmNvbTAeFw0xNDA3MTcxNDEyNTZaFw0xNTA3MTcxNDEy
NTZaMFIxCzAJBgNVBAYTAnVzMRMwEQYDVQQIDApDYWxpZm9ybmlhMRUwEwYDVQQK
DAxPbmVsb2dpbiBJbmMxFzAVBgNVBAMMDnNwLmV4YW1wbGUuY29tMIGfMA0GCSqG
SIb3DQEBAQUAA4GNADCBiQKBgQDZx+ON4IUoIWxgukTb1tOiX3bMYzYQiwWPUNMp
+Fq82xoNogso2bykZG0yiJm5o8zv/sd6pGouayMgkx/2FSOdc36T0jGbCHuRSbti
a0PEzNIRtmViMrt3AeoWBidRXmZsxCNLwgIV6dn2WpuE5Az0bHgpZnQxTKFek0BM
KU/d8wIDAQABo1AwTjAdBgNVHQ4EFgQUGHxYqZYyX7cTxKVODVgZwSTdCnwwHwYD
VR0jBBgwFoAUGHxYqZYyX7cTxKVODVgZwSTdCnwwDAYDVR0TBAUwAwEB/zANBgkq
hkiG9w0BAQ0FAAOBgQByFOl+hMFICbd3DJfnp2Rgd/dqttsZG/tyhILWvErbio/D
Ee98mXpowhTkC04ENprOyXi7ZbUqiicF89uAGyt1oqgTUCD1VsLahqIcmrzgumNy
TwLGWo17WDAa1/usDhetWAMhgzF/Cnf5ek0nK00m0YZGyc4LzgD0CROMASTWNg==';
}
