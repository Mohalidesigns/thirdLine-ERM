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
     * TODO(phase-6): the Blade + Livewire screens need inline and eval'd
     * script until they are retired. The moment livewire/livewire is
     * uninstalled this test flips: the allowances must be gone.
     */
    #[Test]
    public function inline_and_eval_script_survive_only_while_livewire_is_installed(): void
    {
        $policy = $this->get('/login')->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $policy, $m);
        $scriptSrc = $m[1] ?? '';

        if (class_exists(\Livewire\Livewire::class)) {
            $this->assertStringContainsString("'unsafe-eval'", $scriptSrc, 'Alpine needs unsafe-eval until Phase 6.');
            $this->assertStringContainsString("'unsafe-inline'", $scriptSrc, 'The Blade inline scripts need unsafe-inline until Phase 6.');
            $this->assertSame(["'unsafe-inline'", "'unsafe-eval'"], SetSecurityHeaders::legacyScriptSources());

            return;
        }

        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc, 'Livewire is gone: drop unsafe-eval from script-src (Phase 6 TODO).');
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc, 'Livewire is gone: drop unsafe-inline from script-src (Phase 6 TODO).');
        $this->assertSame([], SetSecurityHeaders::legacyScriptSources());
    }

    #[Test]
    public function hsts_only_on_secure_requests(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
