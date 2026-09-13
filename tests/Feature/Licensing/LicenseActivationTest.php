<?php

namespace Tests\Feature\Licensing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ThirdLine\Platform\Licensing\LicenseLoader;
use ThirdLine\Platform\Licensing\LicenseManager;

class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    // NOTE: the base Tests\TestCase snapshots and restores the real
    // storage/licensing files around every test, so the store()/remove()
    // performed here cannot de-license the developer machine.

    public function test_activating_a_forged_jwt_fails_and_removes_the_stored_token(): void
    {
        // Three base64 segments so looksLikeJwt() routes it down the JWT path,
        // but signed by nobody — signature verification must reject it. This is
        // exactly what happens when a licence is issued by a server whose public
        // key the consumer does not trust (e.g. local server vs prod key).
        $forged = implode('.', [
            rtrim(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '='),
            rtrim(base64_encode(json_encode([
                'iss' => 'thirdline-grc-licensing',
                'plan' => 'enterprise',
                'exp' => time() + 86400,
            ])), '='),
            rtrim(base64_encode(str_repeat('x', 64)), '='),
        ]);

        $result = app(LicenseManager::class)->activate($forged);

        // Previously this returned success=true (logged as activation_success
        // with plan=none) while the app sat locked on "possible forgery".
        $this->assertFalse($result['success'], 'Activation of an unverifiable token must not report success');
        $this->assertSame('invalid_license', $result['reason'] ?? null);
        $this->assertNotEmpty($result['error'] ?? '');

        // The unusable token must not be left installed as a hard lock.
        $this->assertFalse(
            app(LicenseLoader::class)->exists(),
            'A token that fails validation must be removed, returning the install to a clean unlicensed state'
        );
    }
}
