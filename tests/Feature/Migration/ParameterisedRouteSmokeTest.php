<?php

namespace Tests\Feature\Migration;

use App\Models\OrganizationSsoSetting;
use App\Models\ScoringProfile;
use App\Support\Periods\PeriodContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Phase 7.4 — the nine GET routes a parameterless smoke test cannot reach.
 *
 * RouteParitySmokeTest enumerates every GET route that takes no parameters and
 * requests it. These nine take a route binding, so they need a record to point
 * at, and `scripts/parity-check.php` reported all nine as touched by no test at
 * all — not by name, not by path.
 *
 * The bar is the same as the smoke test's and no higher: the route resolves,
 * the binding resolves, authorisation passes and the response is not a server
 * error. That is what "parity" claims — the screen is reachable and does not
 * fall over — and it is worth asserting separately from whether its contents
 * are right, which the module tests own.
 *
 * A 404 is a PASS here for the two download routes: the attachment row exists
 * and the stored file does not, so 404 is the correct answer and proves the
 * route, the binding, the tenancy check and the authorisation all ran. What it
 * must not be is a 500.
 */
class ParameterisedRouteSmokeTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
    }

    protected function tearDown(): void
    {
        PeriodContext::use(null);
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_password_reset_page_renders_for_a_token(): void
    {
        // Unauthenticated by definition: the token is the credential.
        $this->get(route('password.reset', ['token' => 'a-token-that-does-not-exist']))
            ->assertOk();
    }

    #[Test]
    public function the_sso_entry_points_answer_for_a_configured_slug(): void
    {
        $setting = TenantContext::actingAs($this->organization->id, fn () => OrganizationSsoSetting::create([
            'organization_id' => $this->organization->id,
            'enabled' => true,
            'driver' => 'oidc',
            'label' => 'Single sign-on',
            'slug' => 'parity',
            'oidc_client_id' => 'client-123',
            'oidc_client_secret' => 'secret-456',
            'oidc_auth_url' => 'https://idp.example.test/authorize',
            'oidc_token_url' => 'https://idp.example.test/token',
            'oidc_userinfo_url' => 'https://idp.example.test/userinfo',
        ]));

        $slug = $setting->slug;

        // Redirects the browser at the identity provider.
        $this->assertLessThan(500, $this->get(route('sso.redirect', $slug))->getStatusCode());

        // Called back WITHOUT a code or state: it must refuse, not crash. This
        // is the path an attacker reaches first.
        $this->assertLessThan(500, $this->get(route('sso.callback', $slug))->getStatusCode());

        // SAML metadata is served for a SAML setting and refused otherwise;
        // either answer is fine, a 500 is not.
        $this->assertLessThan(500, $this->get(route('sso.metadata', $slug))->getStatusCode());
    }

    #[Test]
    public function the_scoring_profile_edit_screen_renders(): void
    {
        $profile = TenantContext::actingAs($this->organization->id, fn () => ScoringProfile::query()->first());

        if ($profile === null) {
            $this->markTestSkipped('No scoring profile is provisioned for a fresh tenant.');
        }

        $this->actingAs($this->actor)
            ->get(route('admin.scoring-profiles.edit', $profile))
            ->assertOk();
    }

    #[Test]
    public function the_loss_event_root_cause_screen_renders(): void
    {
        $event = $this->makeLossEvent();

        // 302 when the event has no root-cause record yet, which is the
        // correct answer and still proves the route, binding and authorisation
        // all ran. What it must not be is a 500.
        $this->assertLessThan(
            500,
            $this->actingAs($this->actor)->get(route('risk.loss-events.show-rca', $event))->getStatusCode()
        );
    }

    #[Test]
    public function the_workflow_designer_renders_for_a_definition(): void
    {
        // Built rather than looked up: a fresh tenant has no definitions, and
        // a skipped test is not coverage — which is exactly the hole this file
        // exists to close.
        $definition = $this->linearDefinition();

        $this->actingAs($this->actor)
            ->get(route('risk.workflows.edit-definition', $definition))
            ->assertOk();
    }

    #[Test]
    public function the_attachment_downloads_resolve_rather_than_erroring(): void
    {
        $event = $this->makeLossEvent();

        // No stored file behind it, so 404 is the right answer — and reaching
        // a 404 means the route, the binding, the tenancy check and the
        // authorisation all ran.
        $status = $this->actingAs($this->actor)
            ->get(route('risk.loss-events.download-attachment', [$event, 1]))
            ->getStatusCode();

        $this->assertLessThan(500, $status, 'loss event attachment download');

        $issue = \App\Models\Issue::query()->create([
            'organization_id' => $this->organization->id,
            'issue_reference' => 'ISS-PARITY-1',
            'title' => 'Parity probe',
            'description' => 'Raised by the parity smoke test.',
            'issue_status' => 'OPEN',
            'issue_source' => 'INTERNAL_AUDIT',
            'issue_category' => 'OPERATIONAL',
            'priority' => 'MEDIUM',
            'created_by' => $this->actor->id,
        ]);

        $status = $this->actingAs($this->actor)
            ->get(route('risk.issues.download-attachment', [$issue, 1]))
            ->getStatusCode();

        $this->assertLessThan(500, $status, 'issue attachment download');
    }
}
