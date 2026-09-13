<?php

namespace Tests\Feature\Licensing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;
use ThirdLine\Platform\Licensing\LicenseManager;

class LicenseMiddlewareTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private LicenseManager $licenseManagerMock;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite disables the global gate via phpunit env so feature tests can hit
        // real routes unlicensed; this file specifically exercises the enforcement
        // path, so turn it back on here.
        config(['licensing.enforce_valid' => true]);

        $this->licenseManagerMock = Mockery::mock(LicenseManager::class);

        // The LicenseHeartbeat middleware calls isLicensed() on every request to
        // decide whether a background sync is due. These tests exercise the
        // EnsureLicenseValid/Feature middleware, not heartbeat scheduling, so
        // default it to false (short-circuits the heartbeat path). Individual
        // tests can override via their own validate()/hasFeature() expectations.
        $this->licenseManagerMock->shouldReceive('isLicensed')->zeroOrMoreTimes()->andReturn(false);

        $this->app->instance(LicenseManager::class, $this->licenseManagerMock);

        // Register test routes
        $this->registerTestRoutes();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function registerTestRoutes(): void
    {
        Route::middleware(['web'])
            ->group(function () {
                // Test route for EnsureLicenseValid middleware
                Route::get('/test/license-valid', function () {
                    return response()->json(['message' => 'License is valid']);
                })->middleware('ensure.license.valid')->name('test.license-valid');

                // Test route for EnsureLicenseValid with write mode
                Route::post('/test/license-write', function () {
                    return response()->json(['message' => 'Write operation allowed']);
                })->middleware('ensure.license.valid:write')->name('test.license-write');

                // Test routes for EnsureLicenseFeature middleware. Laravel
                // doesn't expand {feature} route placeholders into middleware
                // string args, so we register one concrete route per feature
                // used by the test cases below.
                foreach (['audit', 'compliance', 'ai_assistant', 'risk', 'swift_cscf', 'analytics'] as $feature) {
                    Route::get("/test/feature/{$feature}", fn () => response()->json([
                        'message' => "Feature {$feature} is accessible",
                    ]))->middleware("ensure.license.feature:{$feature}")->name("test.feature.{$feature}");
                }

                // Test route for checking license status
                Route::get('/test/status', function () {
                    return response()->json(['success' => true]);
                })->middleware('ensure.license.valid')->name('test.status');

                // Allow-listed recovery route: the gate must let anything named
                // settings.license* through even while unlicensed (no redirect loop).
                Route::get('/test/admin/settings/license', fn () => response()->json(['ok' => true]))
                    ->middleware('ensure.license.valid')->name('admin.license.test-alias');
            });
    }

    // ============ EnsureLicenseValid Tests ============

    public function test_ensure_license_valid_allows_request_when_license_valid(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => true,
                'mode' => 'normal',
                'plan' => 'professional',
                'features' => ['audit' => true],
            ]);

        $response = $this->get('/test/license-valid');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'License is valid']);
    }

    public function test_ensure_license_valid_redirects_when_unlicensed(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'mode' => 'unlicensed',
                'reason' => 'no_license',
                'message' => 'No license found',
            ]);

        $response = $this->get('/test/license-valid');

        $response->assertRedirect(route('admin.license'));
        $response->assertSessionHas('error', 'Please activate your license to continue.');
    }

    public function test_ensure_license_valid_returns_json_when_unlicensed_expects_json(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'mode' => 'unlicensed',
                'reason' => 'no_license',
                'message' => 'No license found',
            ]);

        $response = $this->getJson('/test/license-valid');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'no_license',
            'message' => 'No active license found. Please activate your license.',
        ]);
    }

    public function test_ensure_license_valid_blocks_when_license_locked(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'mode' => 'locked',
                'reason' => 'tamper_detected',
                'message' => 'System integrity check failed.',
            ]);

        $response = $this->get('/test/license-valid');

        // Middleware forwards the LicenseManager's contextual message,
        // not a generic one — so the session flash should reflect the
        // tamper-detection wording that the mocked status returned.
        $response->assertRedirect(route('admin.license'));
        $response->assertSessionHas('error', 'System integrity check failed.');
    }

    public function test_ensure_license_valid_returns_json_when_locked_expects_json(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'mode' => 'locked',
                'reason' => 'tamper_detected',
                'message' => 'System integrity check failed.',
            ]);

        $response = $this->getJson('/test/license-valid');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'license_locked',
            'message' => 'System integrity check failed.',
        ]);
    }

    public function test_ensure_license_valid_blocks_writes_in_read_only_mode(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'mode' => 'read_only',
                'reason' => 'expired',
                'message' => 'Your license has expired.',
            ]);

        $response = $this->post('/test/license-write');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Your license has expired. The system is in read-only mode.');
    }

    public function test_ensure_license_valid_returns_json_for_readonly_write_when_expects_json(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => false,
                'mode' => 'read_only',
                'reason' => 'expired',
                'message' => 'Your license has expired.',
            ]);

        $response = $this->postJson('/test/license-write');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'license_read_only',
            'message' => 'Your license has expired. The system is in read-only mode.',
        ]);
    }

    public function test_ensure_license_valid_allows_reads_in_read_only_mode(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => true,
                'mode' => 'read_only',
                'reason' => 'expired',
                'message' => 'License expired but read-only allowed.',
            ]);

        $response = $this->get('/test/license-valid');

        $response->assertStatus(200);
    }

    public function test_ensure_license_valid_allows_writes_in_grace_mode(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn([
                'valid' => true,
                'mode' => 'grace',
                'message' => 'In grace period.',
            ]);

        $response = $this->post('/test/license-write');

        $response->assertStatus(200);
    }

    public function test_ensure_license_valid_injects_license_status_into_request(): void
    {
        $expectedStatus = [
            'valid' => true,
            'mode' => 'normal',
            'plan' => 'enterprise',
            'features' => ['audit' => true, 'risk' => true],
        ];

        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn($expectedStatus);

        // Create a test route that accesses the injected status
        Route::get('/test/check-status', function () {
            $status = request()->get('_license_status');

            return response()->json(['status' => $status]);
        })->middleware('ensure.license.valid');

        $response = $this->get('/test/check-status');

        $response->assertStatus(200);
        $response->assertJson(['status' => $expectedStatus]);
    }

    public function test_global_gate_is_skipped_when_enforcement_disabled(): void
    {
        // Emergency valve: with enforcement off the gate short-circuits BEFORE
        // consulting the LicenseManager, so validate() must never be called.
        config(['licensing.enforce_valid' => false]);
        $this->licenseManagerMock->shouldNotReceive('validate');

        $response = $this->get('/test/license-valid');

        $response->assertStatus(200);
    }

    public function test_global_gate_allowlists_recovery_routes_when_unlicensed(): void
    {
        // settings.license* must stay reachable while unlicensed, or the redirect
        // target would itself redirect — trapping the user with no way to activate.
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->zeroOrMoreTimes()
            ->andReturn(['valid' => false, 'mode' => 'unlicensed']);

        $response = $this->get('/test/admin/settings/license');

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
    }

    // ============ EnsureLicenseFeature Tests ============

    /**
     * The feature gate enforces module entitlements ONLY when a valid license is
     * present, so every feature test primes validate() to a valid status first.
     */
    private function mockValidLicense(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->zeroOrMoreTimes()
            ->andReturn(['valid' => true, 'mode' => 'normal', 'features' => []]);
    }

    public function test_ensure_license_feature_allows_access_when_feature_enabled(): void
    {
        $this->mockValidLicense();
        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('audit')
            ->once()
            ->andReturn(true);

        $response = $this->get('/test/feature/audit');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Feature audit is accessible']);
    }

    public function test_ensure_license_feature_blocks_when_feature_not_licensed(): void
    {
        $this->mockValidLicense();
        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('compliance')
            ->once()
            ->andReturn(false);

        $response = $this->get('/test/feature/compliance');

        $response->assertRedirect(route('risk.dashboard'));
        $response->assertSessionHas(
            'error',
            "The 'compliance' module is not included in your current license plan. Please upgrade your license."
        );
    }

    public function test_ensure_license_feature_returns_json_when_blocked_and_expects_json(): void
    {
        $this->mockValidLicense();
        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('ai_assistant')
            ->once()
            ->andReturn(false);

        $response = $this->getJson('/test/feature/ai_assistant');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'feature_not_licensed',
            'message' => "The 'ai_assistant' module is not included in your current license plan.",
            'feature' => 'ai_assistant',
        ]);
    }

    public function test_ensure_license_feature_allows_multiple_features(): void
    {
        $this->mockValidLicense();
        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('audit')
            ->andReturn(true);

        $response = $this->get('/test/feature/audit');
        $response->assertStatus(200);

        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('risk')
            ->andReturn(true);

        $response = $this->get('/test/feature/risk');
        $response->assertStatus(200);
    }

    public function test_ensure_license_feature_blocks_unlicensed_features(): void
    {
        $this->mockValidLicense();
        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('swift_cscf')
            ->once()
            ->andReturn(false);

        $response = $this->get('/test/feature/swift_cscf');

        $response->assertRedirect(route('risk.dashboard'));
    }

    public function test_ensure_license_feature_accepts_feature_parameter(): void
    {
        $this->mockValidLicense();
        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('analytics')
            ->once()
            ->andReturn(true);

        $response = $this->get('/test/feature/analytics');

        $response->assertStatus(200);
    }

    public function test_ensure_license_feature_fails_open_when_no_valid_license(): void
    {
        // With no usable license the feature gate does NOT add a second redirect
        // (the app's unlicensed handling governs access). hasFeature must never be
        // consulted in this path, so a dev/test install without a license keeps
        // working and only an ACTIVE license without the feature is blocked.
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->once()
            ->andReturn(['valid' => false, 'mode' => 'unlicensed']);
        $this->licenseManagerMock->shouldNotReceive('hasFeature');

        $response = $this->get('/test/feature/swift_cscf');

        $response->assertStatus(200);
    }

    // ============ Integration Tests ============

    public function test_valid_license_with_enabled_feature(): void
    {
        // validate() is now consulted by BOTH the valid-gate and the feature-gate.
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->zeroOrMoreTimes()
            ->andReturn([
                'valid' => true,
                'mode' => 'normal',
                'features' => ['audit' => true],
            ]);

        $this->licenseManagerMock
            ->shouldReceive('hasFeature')
            ->with('audit')
            ->once()
            ->andReturn(true);

        $response = $this->get('/test/license-valid');
        $response->assertStatus(200);

        $response = $this->get('/test/feature/audit');
        $response->assertStatus(200);
    }

    public function test_expired_license_blocks_writes_but_allows_reads(): void
    {
        $this->licenseManagerMock
            ->shouldReceive('validate')
            ->andReturn([
                'valid' => false,
                'mode' => 'read_only',
                'reason' => 'expired',
                'message' => 'License expired.',
            ]);

        // Read should be allowed
        $response = $this->get('/test/license-valid');
        $response->assertStatus(200);

        // Write should be blocked
        $response = $this->post('/test/license-write');
        $response->assertRedirect();
    }

    public function test_license_modes_are_respected(): void
    {
        // Test all license modes
        $modes = ['normal', 'grace', 'read_only', 'locked', 'unlicensed'];

        foreach ($modes as $mode) {
            $this->licenseManagerMock
                ->shouldReceive('validate')
                ->andReturn([
                    'valid' => ! in_array($mode, ['locked', 'unlicensed']),
                    'mode' => $mode,
                    'features' => ['audit' => true],
                ]);

            $response = $this->get('/test/license-valid');

            // Normal, grace, read_only should pass
            if (in_array($mode, ['normal', 'grace', 'read_only'])) {
                $response->assertStatus(200);
            } else {
                // Locked and unlicensed should be rejected
                $response->assertStatus(302); // Redirect
            }

            Mockery::close();
            $this->licenseManagerMock = Mockery::mock(LicenseManager::class);
            // Re-establish the heartbeat guard's call on the freshly built mock.
            $this->licenseManagerMock->shouldReceive('isLicensed')->zeroOrMoreTimes()->andReturn(false);
            $this->app->instance(LicenseManager::class, $this->licenseManagerMock);
        }
    }
}
