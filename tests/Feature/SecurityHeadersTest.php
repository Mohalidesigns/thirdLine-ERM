<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Http\Middleware\SetSecurityHeaders;

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
