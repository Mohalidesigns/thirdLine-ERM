<?php

namespace Tests\Feature\TprmPortal;

use App\Mail\Tprm\PortalSignInCode;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Portal\PortalAuthService;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The vendor portal's sign-in screens, walked end to end — FR-PRT-01.
 *
 * WHY THIS EXISTS. Phase 8a shipped the portal's guard with its six sign-in
 * pages as `return <div>Entry</div>;` placeholders, and every test stayed
 * green for weeks while production showed vendors the word "Entry" and
 * nothing else. The guard tests asserted the right COMPONENT NAME, and
 * `inertia.testing.ensure_pages_exist` only checks that a file of that name
 * exists — a stub satisfies both. No JavaScript runs in this suite, so the
 * second half of this file reads the page source: a floor, not a substitute
 * for a browser, but a placeholder cannot pass it.
 */
class PortalSignInScreensTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Sup3r-Str0ng-P@ssphrase!';

    private Organization $bank;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The walks                                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_emailed_code_sign_in_walks_from_the_login_screen_to_the_dashboard(): void
    {
        Mail::fake();
        $vendor = $this->portalUser(PortalUser::METHOD_EMAIL);

        $this->get(route('tprm-portal.login', ['client' => $this->bank->uuid]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Auth/Login')
                ->where('client.name', 'Lagos Union Bank')
                ->where('client.uuid', $this->bank->uuid));

        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $vendor->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('tprm-portal.mfa.challenge'));

        $this->get(route('tprm-portal.mfa.challenge'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Auth/MfaChallenge')
                ->where('method', PortalUser::METHOD_EMAIL)
                ->has('sent_to')
                ->has('resend_in')
                ->has('expires_in_minutes'));

        $this->post(route('tprm-portal.mfa.verify'), ['code' => $this->emailedCode()])
            ->assertRedirect(route('tprm-portal.dashboard'));

        // The follow-up request, not just the redirect: a session that did
        // not survive would bounce this to the entry page — which is the
        // screen a vendor would then see instead of their portal.
        $this->get(route('tprm-portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Dashboard')
                ->where('auth.user.email', $vendor->email));
    }

    #[Test]
    public function an_authenticator_sign_in_enrols_and_lands_on_the_dashboard(): void
    {
        $vendor = $this->portalUser(PortalUser::METHOD_TOTP);

        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $vendor->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('tprm-portal.mfa.setup'));

        $secret = null;

        $this->get(route('tprm-portal.mfa.setup'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($vendor, &$secret): void {
                $page->component('TprmPortal/Auth/MfaSetup')
                    ->where('email', $vendor->email)
                    ->where('uri', fn (string $uri): bool => str_starts_with($uri, 'otpauth://totp/'))
                    ->where('secret', function (string $value) use (&$secret): bool {
                        $secret = $value;

                        return $value !== '';
                    });
            });

        $this->post(route('tprm-portal.mfa.enrol'), ['code' => Totp::at((string) $secret, time())])
            ->assertRedirect(route('tprm-portal.dashboard'));

        $this->get(route('tprm-portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('TprmPortal/Dashboard'));
    }

    #[Test]
    public function an_accepted_invitation_walks_through_its_second_factor_to_the_dashboard(): void
    {
        Mail::fake();
        // `uncompromised()` asks the breach corpus; nothing leaves the suite.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $thirdParty = $this->thirdParty();
        $token = app(PortalAuthService::class)->invite($thirdParty, 'new.person@cloudspan.test')['token'];

        $this->get(route('tprm-portal.invitation.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Auth/InvitationAccept')
                ->where('email', 'new.person@cloudspan.test')
                ->where('vendor', 'Cloudspan Nigeria Limited')
                ->where('client', 'Lagos Union Bank')
                ->has('expires_at'));

        $this->post(route('tprm-portal.invitation.accept', ['token' => $token]), [
            'name' => 'New Person',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('tprm-portal.mfa.setup'));

        // A new account is on the emailed code, so setup hands over to the
        // challenge and the code is sent on the way in.
        $this->get(route('tprm-portal.mfa.setup'))->assertRedirect(route('tprm-portal.mfa.challenge'));
        $this->get(route('tprm-portal.mfa.challenge'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('TprmPortal/Auth/MfaChallenge'));

        $this->post(route('tprm-portal.mfa.verify'), ['code' => $this->emailedCode()])
            ->assertRedirect(route('tprm-portal.dashboard'));

        $this->get(route('tprm-portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Dashboard')
                ->where('auth.user.email', 'new.person@cloudspan.test'));
    }

    #[Test]
    public function an_unknown_invitation_renders_the_refusal_with_its_reason(): void
    {
        $this->get(route('tprm-portal.invitation.show', ['token' => Str::random(64)]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Auth/InvitationRefused')
                ->where('reason', fn (string $reason): bool => str_contains($reason, 'not recognised')));
    }

    #[Test]
    public function signing_out_lands_on_the_entry_page_with_the_confirmation(): void
    {
        Mail::fake();
        $vendor = $this->portalUser(PortalUser::METHOD_EMAIL);

        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $vendor->email,
            'password' => self::PASSWORD,
        ]);
        $this->post(route('tprm-portal.mfa.verify'), ['code' => $this->emailedCode()]);

        $this->post(route('tprm-portal.logout'))->assertRedirect(route('tprm-portal.entry'));

        $this->get(route('tprm-portal.entry'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Auth/Entry')
                ->where('flash.success', 'You have been signed out.')
                // The entry page names no client — it would be a customer list.
                ->where('client', null));
    }

    /* ------------------------------------------------------------------ */
    /*  The pages themselves                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function no_portal_page_a_controller_renders_is_a_placeholder(): void
    {
        $components = $this->renderedComponents();

        // An enumerating test that enumerates nothing passes. The six sign-in
        // pages at least must have been found.
        foreach (['Entry', 'Login', 'MfaChallenge', 'MfaSetup', 'InvitationAccept', 'InvitationRefused'] as $auth) {
            $this->assertContains(
                "TprmPortal/Auth/{$auth}",
                $components,
                "The scan of app/Http/Controllers/TprmPortal did not find TprmPortal/Auth/{$auth} — "
                .'if the render call changed shape, fix renderedComponents(), do not drop the page.',
            );
        }

        foreach ($components as $component) {
            $path = resource_path("js/Pages/{$component}.jsx");
            $this->assertFileExists($path, "{$component} is rendered by a portal controller but has no page.");

            $source = $this->code((string) file_get_contents($path));
            $basename = basename($component);

            $this->assertDoesNotMatchRegularExpression(
                '/return\s*\(?\s*<(\w+)>\s*'.preg_quote($basename, '/').'\s*<\/\1>\s*\)?\s*;/',
                $source,
                "resources/js/Pages/{$component}.jsx is still a placeholder that renders only the word "
                ."\"{$basename}\". That is what vendors saw instead of the portal.",
            );

            $this->assertGreaterThan(
                400,
                strlen($source),
                "resources/js/Pages/{$component}.jsx is too small to be a real screen; it is probably a stub.",
            );
        }
    }

    #[Test]
    public function each_sign_in_form_posts_to_the_route_that_handles_it(): void
    {
        $expected = [
            'Login' => ['tprm-portal.login.attempt'],
            'MfaChallenge' => ['tprm-portal.mfa.verify', 'tprm-portal.mfa.resend', 'tprm-portal.mfa.abandon'],
            'MfaSetup' => ['tprm-portal.mfa.enrol', 'tprm-portal.mfa.abandon'],
            'InvitationAccept' => ['tprm-portal.invitation.accept'],
        ];

        foreach ($expected as $page => $routes) {
            $source = $this->code((string) file_get_contents(resource_path("js/Pages/TprmPortal/Auth/{$page}.jsx")));

            foreach ($routes as $name) {
                $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "{$name} is not a registered route.");
                $this->assertStringContainsString(
                    "route('{$name}'",
                    $source,
                    "TprmPortal/Auth/{$page}.jsx never calls {$name}; the screen cannot do the step it exists for.",
                );
            }
        }
    }

    #[Test]
    public function the_code_screen_surfaces_a_refused_resend(): void
    {
        // MfaController::resend answers a refusal with back()->withErrors(['code' => …]),
        // which useForm does not collect because the resend is not its submit.
        // A page that ignores the visit's own errors shows the vendor nothing.
        $source = $this->code((string) file_get_contents(resource_path('js/Pages/TprmPortal/Auth/MfaChallenge.jsx')));

        $this->assertMatchesRegularExpression(
            "/router\.post\(route\('tprm-portal\.mfa\.resend'\).*?onError:/s",
            $source,
            'MfaChallenge.jsx resends without an onError, so a refused resend is invisible to the vendor.',
        );
        $this->assertStringContainsString('role="alert"', $source);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every `Inertia::render('TprmPortal/...')` in the portal's controllers,
     * read from source so a page added next month is covered the day it is.
     *
     * @return list<string>
     */
    private function renderedComponents(): array
    {
        $found = [];

        foreach (glob(app_path('Http/Controllers/TprmPortal/*.php')) ?: [] as $file) {
            preg_match_all("/Inertia::render\(\s*'(TprmPortal\/[^']+)'/", (string) file_get_contents($file), $matches);
            array_push($found, ...$matches[1]);
        }

        return array_values(array_unique($found));
    }

    /** Comments stripped, so a docblock mentioning a route or a stub cannot satisfy or trip a check. */
    private function code(string $source): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);
    }

    private function emailedCode(): string
    {
        $code = null;

        Mail::assertSent(PortalSignInCode::class, function (PortalSignInCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    private function thirdParty(): ThirdParty
    {
        return TenantContext::actingAs($this->bank->id, fn (): ThirdParty => ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]));
    }

    private function portalUser(string $method): PortalUser
    {
        $thirdParty = $this->thirdParty();

        return TenantContext::actingAs($this->bank->id, function () use ($thirdParty, $method): PortalUser {
            $user = new PortalUser([
                'organization_id' => $this->bank->id,
                'third_party_id' => $thirdParty->id,
                'email' => 'ops@cloudspan.test',
                'name' => 'Vendor Operator',
                'password' => self::PASSWORD,
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
                'mfa_method' => $method,
                'mfa_enabled' => false,
            ])->save();

            return $user;
        });
    }
}
