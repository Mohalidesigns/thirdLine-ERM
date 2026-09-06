<?php

namespace Tests\Feature;

use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every web response must carry the baseline security headers, and the
 * Content-Security-Policy is enforced — this product loads nothing from a
 * CDN, so there is nothing a strict policy would break. (ThirdLine VAPT-022,
 * adapted from report-only to enforced.)
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_page_carries_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'), 'The CSP must be enforced, not report-only.');
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    #[Test]
    public function the_policy_is_self_only_for_every_fetch_directive(): void
    {
        $policy = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("font-src 'self' data:", $policy);
        $this->assertStringContainsString("img-src 'self' data: blob:", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $policy, 'No external origin may appear in the policy.');
    }

    /**
     * THE TEST THAT SHOULD HAVE EXISTED IN PHASE 6.8.
     *
     * That phase dropped 'unsafe-inline' from script-src and asserted the
     * header no longer contained it. The assertion passed and the application
     * was unusable: Ziggy's @routes is an INLINE script that defines the global
     * route(), the browser refused to execute it, and every page threw
     * "route is not defined" before React mounted. A blank screen on every
     * route, shipped, because the suite checks the policy string and never
     * asks whether the page it protects can still run.
     *
     * No test here executes JavaScript, so this asks the question statically
     * and it is enough: every inline script in the shell must carry a nonce the
     * header actually allows.
     */
    #[Test]
    public function every_inline_script_in_the_shell_carries_a_nonce_the_policy_allows(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $policy, $m);
        $scriptSrc = $m[1] ?? '';

        preg_match_all("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $scriptSrc, $allowed);
        $allowedNonces = $allowed[1];

        $this->assertNotEmpty(
            $allowedNonces,
            'script-src allows no nonce, so any inline script the shell emits is dead on arrival.'
        );

        preg_match_all('/<script\b([^>]*)>/i', $response->getContent(), $tags);

        $offenders = [];

        foreach ($tags[1] as $attributes) {
            // A script with a src is covered by 'self'; only inline needs the nonce.
            if (preg_match('/\bsrc\s*=/i', $attributes)) {
                continue;
            }

            if (! preg_match('/\bnonce\s*=\s*["\']([^"\']+)["\']/i', $attributes, $found)
                || ! in_array($found[1], $allowedNonces, true)) {
                $offenders[] = trim($attributes) ?: '(no attributes)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An inline <script> in the shell carries no nonce the policy allows, so the browser will\n"
            ."refuse to run it. If this is Ziggy's @routes, the page loses route() and every screen\n"
            ."throws before it mounts:\n  ".implode("\n  ", $offenders)
        );
    }

    /**
     * The Phase 0 TODO, closed in Phase 6.8: the Blade + Livewire screens
     * needed inline and eval'd script, and they are gone.
     */
    #[Test]
    public function script_src_carries_no_unsafe_source(): void
    {
        $policy = $this->get('/login')->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $policy, $m);
        $scriptSrc = $m[1] ?? '';

        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);

        // Not merely absent today — unreachable. This assertion was conditional
        // on livewire/livewire being installed between Phase 0 and Phase 6.8,
        // which was right while the removal was pending and wrong afterwards: a
        // policy that reopens itself when a package reappears is not a policy.
        preg_match('/script-src ([^;]+)/', (new SetSecurityHeaders)->policy(), $direct);

        $this->assertStringNotContainsString(
            'unsafe',
            $direct[1] ?? '',
            "script-src must carry no 'unsafe-*' source under any condition. style-src "
            .'keeps unsafe-inline, which is asserted separately: Tailwind and the tenant '
            .'branding block are inline styles.'
        );
    }

    #[Test]
    public function hsts_only_on_secure_requests(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
