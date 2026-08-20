<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two configuration files whose defaults were wrong, and which nothing else in
 * the suite would notice.
 *
 * CORS. config/cors.php did not exist, so Laravel's packaged default applied:
 * `'allowed_origins' => ['*']` over `api/*`. Bearer-token authentication limits
 * what that could actually be used for today — CORS is browser-enforced and
 * there are no cookie credentials on the API — but it is a policy nobody chose,
 * it is one Sanctum setting away from being a cross-site read of the whole API,
 * and it is a finding a bank's information security reviewer raises on sight.
 *
 * SESSION COOKIE. `'secure' => env('SESSION_SECURE_COOKIE')` had no default, so
 * it resolved to null and the session cookie was not marked Secure in any
 * environment where nobody had set that variable — which is every environment,
 * since no .env.example documents it. `'encrypt'` defaulted to false, leaving
 * the payload (including the resolved tenant) in the clear on disk.
 *
 * The assertions below are about the SHAPE of the configuration rather than the
 * values in this environment: the suite runs with APP_ENV=testing, where both
 * session settings are deliberately left to the environment so that tests can
 * make plain HTTP requests. What has to hold everywhere is that the CORS
 * allowlist is closed unless someone opened it, and that the session settings
 * are forced rather than defaulted outside local and testing.
 */
class SecurityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /* ================================================================== */
    /*  CORS */
    /* ================================================================== */

    #[Test]
    public function the_cors_policy_exists_and_is_closed_by_default(): void
    {
        $this->assertFileExists(
            config_path('cors.php'),
            'Without this file Laravel applies its packaged default, which is allowed_origins => [*].'
        );

        $this->assertSame(
            [],
            config('cors.allowed_origins'),
            'The CORS allowlist must default to closed. Environments that need a browser client on '
            .'another origin list it in CORS_ALLOWED_ORIGINS.'
        );

        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    #[Test]
    public function the_wildcard_origin_is_refused_even_if_someone_configures_it(): void
    {
        // CORS_ALLOWED_ORIGINS=* is the obvious thing to type when an
        // integration is failing at 2am, so the config file filters it out
        // rather than trusting review to catch it.
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*,https://portal.examplebank.ng';
        $_SERVER['CORS_ALLOWED_ORIGINS'] = '*,https://portal.examplebank.ng';

        try {
            $config = require config_path('cors.php');
        } finally {
            unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        }

        $this->assertSame(['https://portal.examplebank.ng'], $config['allowed_origins']);
    }

    #[Test]
    public function the_api_surface_is_the_only_thing_cors_applies_to(): void
    {
        // The web UI is session and CSRF protected and is only ever loaded from
        // this host. Listing it here would create a way to relax that.
        $this->assertSame(['api/*', 'mcp', 'scim/v2/*'], config('cors.paths'));
    }

    /* ================================================================== */
    /*  Session cookie */
    /* ================================================================== */

    #[Test]
    public function the_session_cookie_is_forced_secure_and_encrypted_outside_development(): void
    {
        // config/session.php resolves both from APP_ENV at load time, so the
        // production shape is checked by loading it with that environment set
        // rather than by trusting the comment above the line.
        $config = $this->sessionConfigFor('production');

        $this->assertTrue($config['secure'], 'The session cookie must be marked Secure in production.');
        $this->assertTrue($config['encrypt'], 'Session payloads must be encrypted at rest in production.');

        // Unchanged and already correct — asserted so a future edit to the same
        // block cannot quietly weaken them.
        $this->assertTrue($config['http_only']);
        $this->assertSame('lax', $config['same_site']);
    }

    #[Test]
    public function an_unrecognised_environment_is_treated_as_production(): void
    {
        // A deployment with APP_ENV=staging, or with APP_ENV unset entirely,
        // must not fall through to the developer defaults.
        foreach (['staging', 'uat', ''] as $environment) {
            $config = $this->sessionConfigFor($environment);

            $this->assertTrue($config['secure'], "APP_ENV={$environment} must still force a Secure cookie.");
            $this->assertTrue($config['encrypt'], "APP_ENV={$environment} must still force encryption.");
        }
    }

    #[Test]
    public function local_development_can_still_run_over_plain_http(): void
    {
        // Forcing Secure in local would make the application unusable on
        // http://localhost, and an unusable control gets deleted rather than
        // fixed.
        $config = $this->sessionConfigFor('local');

        $this->assertNotTrue($config['secure']);
        $this->assertFalse($config['encrypt']);
    }

    /**
     * Load config/session.php as it would resolve under a given APP_ENV.
     *
     * @return array<string, mixed>
     */
    private function sessionConfigFor(string $environment): array
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousServer = $_SERVER['APP_ENV'] ?? null;

        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        try {
            return require config_path('session.php');
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previousServer;
            }
        }
    }
}
