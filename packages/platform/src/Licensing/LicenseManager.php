<?php

namespace ThirdLine\Platform\Licensing;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use ThirdLine\Platform\Licensing\Exceptions\LicenseExpiredException;

class LicenseManager
{
    private LicenseLoader $loader;

    private JwtValidator $validator;

    private DeviceFingerprint $fingerprint;

    private EnforcementEngine $enforcement;

    private SyncManager $sync;

    private TamperDetector $tamper;

    private GracePeriodManager $grace;

    private LicenseAuditLogger $auditLogger;

    private ?object $claims = null;

    public function __construct(
        LicenseLoader $loader,
        JwtValidator $validator,
        DeviceFingerprint $fingerprint,
        EnforcementEngine $enforcement,
        SyncManager $sync,
        TamperDetector $tamper,
        GracePeriodManager $grace,
        LicenseAuditLogger $auditLogger
    ) {
        $this->loader = $loader;
        $this->validator = $validator;
        $this->fingerprint = $fingerprint;
        $this->enforcement = $enforcement;
        $this->sync = $sync;
        $this->tamper = $tamper;
        $this->grace = $grace;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Full license validation pipeline.
     */
    public function validate(): array
    {
        // A server-confirmed revocation (recorded by SyncManager on validate/heartbeat)
        // overrides everything: the locally-stored JWT is still cryptographically valid
        // and unexpired, so without this the app would keep running after a revoke until
        // the token's own expiry. Locked = the app-wide block kicks in.
        if (Cache::get('license.revoked', false)) {
            return $this->failResult('revoked', 'This license has been revoked.');
        }

        // Use cached result to avoid repeated disk reads
        $cached = Cache::get('license.validation_result');
        if ($cached && ($cached['validated_at'] ?? 0) > (time() - config('licensing.validation_cache_seconds', 300))) {
            if (isset($cached['claims'])) {
                $this->claims = (object) $cached['claims'];
                $this->enforcement->setClaims($this->claims);
                $this->enforcement->setMode($cached['mode'] ?? 'normal');
            }

            return $cached;
        }

        // Step 1: Tamper detection
        $tamperIssues = $this->tamper->runChecks();
        if (! empty($tamperIssues)) {
            $this->auditLogger->log('validation_failed', [
                'reason' => 'tamper_detected',
                'issues' => $tamperIssues,
            ]);

            return $this->failResult('tamper_detected', 'System integrity check failed.');
        }

        // Step 2: Load license
        if (! $this->loader->exists()) {
            return $this->noLicenseResult();
        }

        try {
            $token = $this->loader->load();
        } catch (\Exception $e) {
            return $this->failResult('no_license', $e->getMessage());
        }

        // Step 3: Validate JWT (offline)
        try {
            $this->claims = $this->validator->validate($token);
        } catch (LicenseExpiredException $e) {
            return $this->handleExpired();
        } catch (\Exception $e) {
            return $this->failResult('invalid_license', $e->getMessage());
        }

        // Step 4: Device binding check
        if (isset($this->claims->dvc) && ! $this->fingerprint->matches($this->claims->dvc)) {
            $this->auditLogger->log('device_mismatch', [
                'expected' => substr($this->claims->dvc, 0, 20).'...',
                'actual' => substr($this->fingerprint->generate(), 0, 20).'...',
            ]);

            return $this->failResult('device_mismatch', 'License is not bound to this device.');
        }

        // Step 5: Determine enforcement mode
        $plan = $this->claims->plan ?? 'starter';
        $graceStatus = $this->grace->getStatus($plan);
        $isExpired = isset($this->claims->exp) && $this->claims->exp < time();
        $serverReachable = $this->sync->isServerReachable();
        $mode = $this->enforcement->determineMode(
            isExpired: $isExpired,
            isRevoked: false, // Revocation is detected via heartbeat response
            serverReachable: $serverReachable,
            daysSinceLastSync: $this->sync->daysSinceLastSync()
        );

        $this->enforcement->setClaims($this->claims);

        // Step 6: Cache result
        $result = [
            'valid' => true,
            'mode' => $mode,
            'claims' => (array) $this->claims,
            'features' => (array) ($this->claims->feat ?? []),
            'plan' => $this->claims->plan ?? 'unknown',
            'type' => $this->claims->ltyp ?? 'full',
            'days_remaining' => $this->enforcement->getDaysRemaining(),
            'max_users' => $this->claims->mu ?? 0,
            'grace_period' => $graceStatus,
            'license_id' => $this->claims->jti ?? null,
            'license_key' => $this->claims->lk ?? null,
            'validated_at' => time(),
        ];

        Cache::put('license.validation_result', $result, config('licensing.validation_cache_seconds', 300));

        $this->auditLogger->log('validation_success', [
            'mode' => $mode,
            'plan' => $result['plan'],
            'days_remaining' => $result['days_remaining'],
        ]);

        return $result;
    }

    public function hasFeature(string $feature): bool
    {
        if (! $this->claims) {
            $this->validate();
        }

        return $this->enforcement->hasFeature($feature);
    }

    public function requireFeature(string $feature): void
    {
        if (! $this->claims) {
            $this->validate();
        }
        $this->enforcement->requireFeature($feature);
    }

    public function getStatus(): array
    {
        $result = $this->validate();
        $overrides = Cache::get('license.server_entitlements');

        // If the server has reported newer entitlements via heartbeat or live validate,
        // overlay them on top of the JWT-claim-derived view so /settings/license reflects
        // the authoritative server state on the next read.
        if (is_array($overrides)) {
            if (isset($overrides['features'])) {
                $result['features'] = $overrides['features'];
            }
            if (isset($overrides['max_users'])) {
                $result['max_users'] = (int) $overrides['max_users'];
            }
            if (isset($overrides['plan'])) {
                $result['plan'] = $overrides['plan'];
            }
            if (isset($overrides['type'])) {
                $result['type'] = $overrides['type'];
            }
            if (isset($overrides['expires_at'])) {
                try {
                    $expires = Carbon::parse($overrides['expires_at']);
                    $result['days_remaining'] = (int) max(0, now()->diffInDays($expires, false));
                } catch (\Exception $e) {
                    // ignore unparseable expires_at — fall back to JWT-derived value
                }
            }
            $result['_overridden_from_server_at'] = $overrides['fetched_at'] ?? null;
        }

        return [
            'valid' => $result['valid'],
            'mode' => $result['mode'],
            'plan' => $result['plan'] ?? 'none',
            'type' => $result['type'] ?? 'full',
            'is_trial' => in_array($result['type'] ?? 'full', ['trial', 'demo', 'poc'], true),
            'features' => $result['features'] ?? [],
            'days_remaining' => $result['days_remaining'] ?? 0,
            'max_users' => $result['max_users'] ?? 0,
            'grace_period' => $result['grace_period'] ?? null,
            'license_id' => $result['license_id'] ?? null,
            'license_key' => $result['license_key'] ?? null,
            'last_sync' => $this->sync->getLastSyncTimestamp(),
            'last_validate' => $this->sync->getLastValidateTimestamp(),
            'server_overrides_applied' => is_array($overrides),
            'device_fingerprint' => $this->fingerprint->generate(),
        ];
    }

    /**
     * Lean license view shared globally with the SPA (Inertia) so every page can
     * render the app-wide block (revoked / unlicensed / locked) or the tiered
     * expiry banner. Reads from the cached validation result — cheap per request.
     *
     * @return array{mode:string,valid:bool,blocked:bool,block_reason:?string,plan:string,type:string,days_remaining:int,expires_at:?string,warning:?array}
     */
    public function clientNotice(): array
    {
        $status = $this->getStatus();
        $result = Cache::get('license.validation_result', []);
        $claims = $result['claims'] ?? [];

        $exp = isset($claims['exp']) ? Carbon::createFromTimestamp((int) $claims['exp'])->toIso8601String() : null;
        $iat = isset($claims['iat']) ? Carbon::createFromTimestamp((int) $claims['iat'])->toIso8601String() : null;

        $mode = $status['mode'] ?? 'unlicensed';
        $blocked = in_array($mode, ['locked', 'unlicensed'], true);

        $blockReason = null;
        if ($blocked) {
            $reason = $result['reason'] ?? null;
            $blockReason = match (true) {
                Cache::get('license.revoked', false) === true || $reason === 'revoked' => 'revoked',
                $mode === 'unlicensed' => 'unlicensed',
                $reason === 'tamper_detected' => 'integrity',
                $reason === 'device_mismatch' => 'device',
                default => 'locked',
            };
        }

        $warning = $blocked ? null : app(ExpiryNotifier::class)->evaluate([
            'valid' => $status['valid'],
            'mode' => $mode,
            'days_remaining' => $status['days_remaining'],
            'expires_at' => $exp,
            'issued_at' => $iat,
            'type' => $status['type'],
        ]);

        return [
            'mode' => $mode,
            'valid' => (bool) $status['valid'],
            'blocked' => $blocked,
            'block_reason' => $blockReason,
            'plan' => $status['plan'],
            'type' => $status['type'],
            'days_remaining' => (int) $status['days_remaining'],
            'expires_at' => $exp,
            'warning' => $warning,
            // Licensed-module map (key => bool). Drives sidebar visibility so
            // unlicensed modules are hidden, matching the route-level feature gate.
            'features' => (array) ($status['features'] ?? []),
        ];
    }

    /**
     * Persist server-reported entitlements (from heartbeat or live validate) so they
     * overlay the JWT-claim-derived status on the next read. This is what makes
     * "change a parameter on the server → reflects on next fetch" work without
     * re-issuing a JWT.
     */
    public function applyServerEntitlements(array $entitlements): void
    {
        $entitlements['fetched_at'] = now()->toIso8601String();
        Cache::forever('license.server_entitlements', $entitlements);
        // Force getStatus() to recompute the next time it's called.
        Cache::forget('license.validation_result');
    }

    /**
     * Activate a license.
     *
     * Accepts either:
     *   (a) a short license key (e.g. APGRC-XXXX-XXXX-XXXX-XXXX) — exchanged with the
     *       LicensingServer via POST /api/v1/licenses/activate to obtain a signed JWT; or
     *   (b) an already-signed RS256 JWT (offline activation via .lic file or admin-issued token).
     *
     * The classifier is structural: if the input has the dotted JWT shape (3 segments split
     * by '.'), treat it as a JWT and store directly. Otherwise, ask the server to exchange it.
     */
    public function activate(string $input): array
    {
        $input = trim($input);

        try {
            $jwt = $this->looksLikeJwt($input)
                ? $input
                : $this->exchangeKeyForJwt($input);

            $this->loader->store($jwt);
            Cache::forget('license.validation_result');
            // A newly stored license invalidates everything learned about the
            // previous one — otherwise stale server entitlements (old plan/
            // expiry) overlay the fresh JWT and getStatus() reports the old
            // license (observed: re-licensing kept showing the prior plan).
            Cache::forget('license.server_entitlements');
            // A freshly activated license clears any stale revocation lock.
            Cache::forget('license.revoked');
            // Re-baseline the integrity anchors on a fresh activation. These are
            // paired-sample / last-seen caches the TamperDetector compares against;
            // left over from a prior license (or an env/clock change during the
            // churn of re-licensing) they produce false "System integrity check
            // failed" locks on an otherwise valid new license. Clearing them lets
            // the next validate() re-establish a clean baseline.
            foreach ([
                'license.server_time',
                'license.server_time_observed_at',
                'license.last_known_time',
                'license.app_env',
            ] as $anchor) {
                Cache::forget($anchor);
            }

            $result = $this->validate();

            // Activation is only a success if the licence we just stored actually
            // validates. Previously this returned success unconditionally, so a
            // token signed by an untrusted server (public-key mismatch) or bound
            // to another device produced a green "activated successfully" toast
            // while the app sat locked on "possible forgery" — logged as
            // activation_success with plan=none.
            if (! ($result['valid'] ?? false)) {
                $reason = $result['reason'] ?? 'unknown';

                // When the token itself is unusable, remove it so the install
                // returns to a clean "no licence" state instead of a hard lock.
                // (no_license here means the file we JUST stored failed to load
                // back — i.e. it's corrupt.) Environmental failures such as
                // tamper_detected keep the stored token — it may validate fine
                // once the environment is fixed; an expired token is also kept
                // because it legitimately grants read-only mode.
                if (in_array($reason, ['invalid_license', 'device_mismatch', 'no_license'], true)) {
                    $this->loader->remove();
                    Cache::forget('license.validation_result');
                }

                $this->auditLogger->log('activation_failed', [
                    'reason' => $reason,
                    'message' => $result['message'] ?? null,
                    'via' => $this->looksLikeJwt($input) ? 'jwt' : 'server_exchange',
                ]);

                return [
                    'success' => false,
                    'error' => $result['message'] ?? 'The licence failed validation after activation.',
                    'reason' => $reason,
                ];
            }

            $this->auditLogger->log('activation_success', [
                'plan' => $result['plan'] ?? 'unknown',
                'via' => $this->looksLikeJwt($input) ? 'jwt' : 'server_exchange',
            ]);

            // Treat activation as a successful server interaction for sync bookkeeping.
            Cache::forever('license.last_sync', now()->timestamp);

            return ['success' => true, 'result' => $result];
        } catch (\Exception $e) {
            $this->auditLogger->log('activation_failed', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * A JWT has three dot-separated base64url segments; license keys do not.
     */
    private function looksLikeJwt(string $input): bool
    {
        $parts = explode('.', $input);

        return count($parts) === 3
            && ctype_print($input)
            && strlen($input) > 60;
    }

    /**
     * Call LicensingServer to exchange a short license key for a signed JWT.
     */
    private function exchangeKeyForJwt(string $licenseKey): string
    {
        $result = $this->sync->activate(
            $licenseKey,
            $this->fingerprint->generate(),
        );

        if (($result['status'] ?? '') !== 'ok') {
            if (($result['status'] ?? '') === 'unreachable') {
                throw new \RuntimeException('Licensing server is unreachable. Please try again or use offline activation.');
            }

            // Switch on the server's stable error code (not the message) per contract §3.1.
            $message = match ($result['code'] ?? '') {
                'tenant_mismatch' => 'This license key is not registered to your organization. Contact your administrator.',
                'activation_limit_reached' => 'This license has reached its maximum number of activations. Deactivate another device first.',
                'license_revoked' => 'This license has been revoked. Contact your administrator.',
                'license_expired' => 'This license has expired. Please renew it.',
                'license_not_active' => 'This license is not active (suspended or expired).',
                'license_not_found' => 'No license was found for the supplied key.',
                'device_mismatch' => 'This license is bound to a different device.',
                'insufficient_scope' => 'This application is not permitted to activate licenses. Contact your administrator.',
                'invalid_credentials',
                'missing_credentials' => 'The application is misconfigured (invalid licensing credentials). Contact your administrator.',
                default => $result['message'] ?? 'License activation was rejected by the licensing server.',
            };

            throw new \RuntimeException($message);
        }

        $jwt = $result['data']['license_token'] ?? null;
        if (! is_string($jwt) || $jwt === '') {
            throw new \RuntimeException('Licensing server response missing license_token.');
        }

        return $jwt;
    }

    public function deactivate(): void
    {
        $this->loader->remove();
        Cache::forget('license.validation_result');
        Cache::forget('license.last_sync');
        Cache::forget('license.last_validate');
        Cache::forget('license.server_time');
        Cache::forget('license.server_entitlements');
        Cache::forget('license.revoked');
        $this->auditLogger->log('license_deactivated');
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint->generate();
    }

    public function getFingerprintFile(): string
    {
        return $this->fingerprint->generateFingerprintFile();
    }

    public function isLicensed(): bool
    {
        return $this->loader->exists();
    }

    private function handleExpired(): array
    {
        $this->enforcement->setMode('read_only');
        $this->auditLogger->log('license_expired');

        $result = [
            'valid' => false,
            'mode' => 'read_only',
            'reason' => 'expired',
            'plan' => 'expired',
            'features' => [],
            'days_remaining' => 0,
            'max_users' => 0,
            'license_key' => null,
            'license_id' => null,
            'grace_period' => $this->grace->getStatus(),
            'message' => 'Your license has expired. The system is in read-only mode.',
            'validated_at' => time(),
        ];

        Cache::put('license.validation_result', $result, 60);

        return $result;
    }

    private function noLicenseResult(): array
    {
        return [
            'valid' => false,
            'mode' => 'unlicensed',
            'reason' => 'no_license',
            'plan' => 'none',
            'features' => [],
            'days_remaining' => 0,
            'max_users' => 0,
            'grace_period' => null,
            'message' => 'No license found. Please activate your license.',
            'validated_at' => time(),
        ];
    }

    private function failResult(string $reason, string $message): array
    {
        $this->enforcement->setMode('locked');

        return [
            'valid' => false,
            'mode' => 'locked',
            'reason' => $reason,
            'plan' => 'none',
            'features' => [],
            'days_remaining' => 0,
            'max_users' => 0,
            'grace_period' => null,
            'message' => $message,
            'validated_at' => time(),
        ];
    }
}
