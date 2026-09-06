<?php

namespace ThirdLine\Platform\Licensing;

use ThirdLine\Platform\Licensing\Models\LicenseAuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SyncManager
{
    private string $serverUrl;

    private ?string $clientId;

    private ?string $clientSecret;

    public function __construct()
    {
        // Normalize a trailing slash so "{$serverUrl}/api/v1/..." never yields
        // a double-slash path (some proxies 404/403 those).
        $this->serverUrl = $this->enforceSecureScheme(
            rtrim((string) config('licensing.server_url'), '/')
        );
        $this->clientId = config('licensing.client_id');
        $this->clientSecret = config('licensing.client_secret');
    }

    /**
     * VAPT-037: the client secret and licence key travel to this URL, so in
     * production a plaintext http:// to an external host must never be used.
     * Loopback (co-located LicensingServer on 127.0.0.1) is exempt; any other
     * http host is force-upgraded to https and logged rather than left in the
     * clear.
     */
    private function enforceSecureScheme(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isLoopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        if ($scheme === 'http' && ! $isLoopback && app()->isProduction()) {
            \Illuminate\Support\Facades\Log::warning(
                'licensing.server_url used plaintext http in production; upgraded to https.',
                ['host' => $host]
            );

            return preg_replace('#^http://#i', 'https://', $url);
        }

        return $url;
    }

    /**
     * Common headers + base URL for any client-app call to LicensingServer.
     */
    private function request()
    {
        return Http::withHeaders([
            'X-Client-Id' => $this->clientId ?: '',
            'X-Client-Secret' => $this->clientSecret ?: '',
            'Accept' => 'application/json',
        ])->timeout(30)->acceptJson();
    }

    /**
     * Exchange a short license key for a signed JWT + entitlements.
     * Returns: ['status' => 'ok', 'data' => [...]] on success;
     *          ['status' => 'error', 'code' => 'server_code', 'http' => N, 'message' => ...] on a server-reported failure;
     *          ['status' => 'unreachable', 'error' => '...'] if the server can't be reached.
     */
    public function activate(string $licenseKey, string $deviceFingerprint, array $deviceMeta = []): array
    {
        try {
            $response = $this->request()
                // throw:false so an HTTP 4xx (invalid_credentials, insufficient_scope,
                // tenant_mismatch, activation_limit_reached, license_revoked) returns a
                // parseable body and its real error code — instead of the retry throwing
                // and being caught below as a misleading "unreachable" message.
                ->retry(2, 1000, throw: false)
                ->post("{$this->serverUrl}/api/v1/licenses/activate", array_filter([
                    'license_key' => $licenseKey,
                    'device_fingerprint' => $deviceFingerprint,
                    'hostname' => $deviceMeta['hostname'] ?? gethostname() ?: null,
                    'ip_address' => $deviceMeta['ip_address'] ?? null,
                    'os_info' => $deviceMeta['os_info'] ?? (PHP_OS.' / PHP '.PHP_VERSION),
                    // Deployment registry metadata (admin Deployments view).
                    'domain' => $this->deploymentDomain(),
                    'app_version' => (string) config('app.version', '1.0.0'),
                    'app_env' => app()->environment(),
                ], fn ($v) => $v !== null && $v !== ''));

            if ($response->successful()) {
                $data = $response->json('data', []);
                // Persist server-driven heartbeat cadence + grace window so the
                // client follows the server, not its own hardcoded config.
                $this->persistServerDirectives($data);
                app(LicenseAuditLogger::class)->log('activation_success', [
                    'activation_id' => $data['activation_id'] ?? null,
                    'expires_at' => $data['entitlements']['expires_at'] ?? null,
                    'type' => $data['entitlements']['type'] ?? null,
                ]);

                return ['status' => 'ok', 'data' => $data];
            }

            $body = $response->json();
            app(LicenseAuditLogger::class)->log('activation_failed', [
                'http' => $response->status(),
                'code' => $body['error'] ?? null,
            ]);

            return [
                'status' => 'error',
                'http' => $response->status(),
                'code' => $body['error'] ?? 'unknown_error',
                'message' => $body['message'] ?? 'Activation rejected by the licensing server.',
            ];
        } catch (\Exception $e) {
            app(LicenseAuditLogger::class)->log('activation_failed', [
                'error' => $e->getMessage(),
                'reason' => 'unreachable',
            ]);

            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }
    }

    /**
     * Authoritative live validation of the current device's license. Does not consume an activation slot.
     * Used by /settings/license on page load (with a short TTL cache in the consumer).
     */
    public function validate(string $licenseKey, string $deviceFingerprint, ?int $currentUsers = null): array
    {
        try {
            $response = $this->request()
                ->post("{$this->serverUrl}/api/v1/licenses/validate", array_filter([
                    'license_key' => $licenseKey,
                    'device_fingerprint' => $deviceFingerprint,
                    'current_users' => $currentUsers ?? $this->getActiveUserCount(),
                    'app_version' => config('app.version', '1.0.0'),
                ], fn ($v) => $v !== null && $v !== ''));

            if ($response->successful()) {
                $data = $response->json('data', []);

                Cache::forever('license.last_validate', now()->timestamp);
                Cache::forever('license.server_time', $data['server_time'] ?? now()->toIso8601String());
                // Local clock at the instant server_time was observed — the only
                // sound anchor for clock-skew detection.
                Cache::forever('license.server_time_observed_at', now()->timestamp);

                // Defensive: honor a revoked/invalid verdict even on a 200 body.
                if (($data['revoked'] ?? false) === true || ($data['valid'] ?? true) === false) {
                    app(LicenseAuditLogger::class)->log('validation_failed', [
                        'reason' => 'server_marked_invalid',
                        'revoked' => $data['revoked'] ?? null,
                        'valid' => $data['valid'] ?? null,
                    ]);

                    $this->markRevoked();

                    return ['status' => 'revoked', 'data' => $data];
                }

                app(LicenseAuditLogger::class)->log('validation_success', [
                    'status' => $data['status'] ?? null,
                    'days_remaining' => $data['days_remaining'] ?? null,
                    'type' => $data['entitlements']['type'] ?? null,
                ]);

                $this->clearRevoked();

                return ['status' => 'ok', 'data' => $data];
            }

            $body = $response->json();
            $code = $body['error'] ?? 'unknown_error';
            app(LicenseAuditLogger::class)->log('validation_failed', [
                'http' => $response->status(),
                'code' => $code,
            ]);

            // A revoked/expired license surfaces here as a 403 (license_revoked,
            // license_expired) or a missing activation — lock the app, same as heartbeat.
            if (in_array($code, ['license_revoked', 'license_expired', 'license_not_found', 'activation_not_found'], true)) {
                $this->markRevoked();

                return ['status' => 'revoked', 'code' => $code, 'http' => $response->status()];
            }

            return [
                'status' => 'error',
                'http' => $response->status(),
                'code' => $code,
                'message' => $body['message'] ?? 'Validation rejected by the licensing server.',
            ];
        } catch (\Exception $e) {
            app(LicenseAuditLogger::class)->log('validation_failed', [
                'error' => $e->getMessage(),
                'reason' => 'unreachable',
            ]);

            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }
    }

    /**
     * Release the activation slot for this device on the server.
     * Idempotent: a 404 'activation_not_found' is treated as success.
     */
    public function deactivate(string $licenseKey, string $deviceFingerprint): array
    {
        try {
            $response = $this->request()
                ->post("{$this->serverUrl}/api/v1/licenses/deactivate", [
                    'license_key' => $licenseKey,
                    'device_fingerprint' => $deviceFingerprint,
                ]);

            if ($response->successful()) {
                return ['status' => 'ok', 'data' => $response->json('data', [])];
            }

            $body = $response->json();
            // Treat "already gone" as success — slot is released either way.
            if ($response->status() === 404 && ($body['error'] ?? '') === 'activation_not_found') {
                return ['status' => 'ok', 'data' => ['already_deactivated' => true]];
            }

            app(LicenseAuditLogger::class)->log('license_deactivate_failed', [
                'http' => $response->status(),
                'code' => $body['error'] ?? null,
            ]);

            return [
                'status' => 'error',
                'http' => $response->status(),
                'code' => $body['error'] ?? 'unknown_error',
                'message' => $body['message'] ?? 'Deactivation rejected by the licensing server.',
            ];
        } catch (\Exception $e) {
            app(LicenseAuditLogger::class)->log('license_deactivate_failed', [
                'error' => $e->getMessage(),
                'reason' => 'unreachable',
            ]);

            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }
    }

    public function heartbeat(string $licenseKey, string $deviceFingerprint): array
    {
        try {
            $response = $this->request()
                // throw:false so an HTTP error (e.g. 404 activation_not_found
                // after a server-side revoke) returns a parseable body instead
                // of bubbling up as an "unreachable" transport exception.
                // Gentle backoff: this runs at terminate time and holds the worker,
                // so keep it short (2×1s, not 3×5s) — a background check-in must never
                // stall the next request for 10+ seconds against a slow/unreachable server.
                ->retry(2, 1000, throw: false)
                ->post("{$this->serverUrl}/api/v1/licenses/heartbeat", [
                    'license_key' => $licenseKey,
                    'device_fingerprint' => $deviceFingerprint,
                    'active_users' => $this->getActiveUserCount(),
                    'feature_usage' => $this->getFeatureUsage(),
                    'app_version' => (string) config('app.version', '1.0.0'),
                    'domain' => $this->deploymentDomain(),
                    'app_env' => app()->environment(),
                ]);

            if ($response->successful()) {
                $data = $response->json('data', []);

                Cache::forever('license.last_sync', now()->timestamp);
                Cache::forever('license.server_time', $data['server_time'] ?? now()->toIso8601String());
                Cache::forever('license.server_time_observed_at', now()->timestamp);
                // grace window may change server-side (e.g. plan/type change).
                $this->persistServerDirectives($data);

                $this->markLogsSynced();

                // Surface server-driven commands in the local audit trail.
                foreach (($data['commands'] ?? []) as $cmd) {
                    app(LicenseAuditLogger::class)->log('server_command_received', ['command' => $cmd]);
                }

                if ($data['revoked'] ?? false) {
                    $this->markRevoked();

                    return ['status' => 'revoked', 'data' => $data];
                }

                app(LicenseAuditLogger::class)->log('heartbeat_success', [
                    'server_time' => $data['server_time'] ?? null,
                ]);

                $this->clearRevoked();

                return ['status' => 'ok', 'data' => $data];
            }

            $body = $response->json();
            $code = $body['error'] ?? 'unknown_error';

            // The server deactivates activations on revoke, so a revoked license
            // surfaces here as activation_not_found — treat it as a lock signal.
            if (in_array($code, ['activation_not_found', 'license_not_found', 'license_revoked'], true)) {
                app(LicenseAuditLogger::class)->log('server_command_received', [
                    'command' => 'force_deactivate',
                    'reason' => $code,
                ]);

                $this->markRevoked();

                return ['status' => 'revoked', 'code' => $code, 'http' => $response->status()];
            }

            app(LicenseAuditLogger::class)->log('heartbeat_failed', [
                'http' => $response->status(),
                'code' => $code,
            ]);

            return ['status' => 'error', 'code' => $code, 'http' => $response->status()];
        } catch (\Exception $e) {
            app(LicenseAuditLogger::class)->log('heartbeat_failed', [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'unreachable', 'error' => $e->getMessage()];
        }
    }

    /**
     * Persist a server-confirmed revocation so LicenseManager::validate() locks the
     * app even though the locally-stored JWT is still cryptographically valid.
     * Cleared by clearRevoked() on the next clean server verdict or a re-activation.
     */
    private function markRevoked(): void
    {
        Cache::forever('license.revoked', true);
        Cache::forget('license.validation_result');
    }

    private function clearRevoked(): void
    {
        if (Cache::get('license.revoked', false)) {
            Cache::forget('license.revoked');
            Cache::forget('license.validation_result');
        }
    }

    /**
     * A short revocation-check cadence (default 30 min), independent of the longer
     * usage-heartbeat interval, so a server-side revoke blocks the app promptly.
     */
    public function isRevocationCheckDue(): bool
    {
        $last = Cache::get('license.last_sync', 0);
        $interval = (int) config('licensing.revocation_check_minutes', 30) * 60;

        return (time() - $last) >= $interval;
    }

    public function isHeartbeatDue(): bool
    {
        $lastSync = Cache::get('license.last_sync', 0);
        $interval = $this->heartbeatIntervalHours() * 3600;

        return (time() - $lastSync) >= $interval;
    }

    /**
     * Server-driven heartbeat cadence (set at activation/heartbeat), falling
     * back to local config when the server has not supplied one yet.
     */
    public function heartbeatIntervalHours(): int
    {
        return (int) Cache::get(
            'license.heartbeat_interval_hours',
            config('licensing.heartbeat_interval_hours', 48),
        );
    }

    /**
     * Server-driven grace window in days, falling back to local config.
     */
    public function gracePeriodDays(): int
    {
        return (int) Cache::get(
            'license.grace_period_days',
            config('licensing.grace_period_days', 7),
        );
    }

    public function daysSinceLastSync(): int
    {
        $lastSync = Cache::get('license.last_sync', 0);
        if ($lastSync === 0) {
            return PHP_INT_MAX;
        }

        return (int) floor((time() - $lastSync) / 86400);
    }

    public function isServerReachable(): bool
    {
        try {
            $response = Http::withHeaders([
                'X-Client-Id' => $this->clientId ?: '',
                'X-Client-Secret' => $this->clientSecret ?: '',
            ])
                ->timeout(10)
                ->get("{$this->serverUrl}/api/v1/health");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getLastSyncTimestamp(): ?int
    {
        return Cache::get('license.last_sync');
    }

    public function getLastValidateTimestamp(): ?int
    {
        return Cache::get('license.last_validate');
    }

    /**
     * Seats in use, for the entitlement the licence server checks against.
     *
     * The user class is configuration: this read was `App\Models\User::count()`,
     * the one line in the licensing cluster that named the application. A seat
     * is whatever the consuming product calls a user, and both current
     * consumers spell it differently in every other respect.
     */
    private function getActiveUserCount(): int
    {
        return Cache::get('active_users_count', fn () => LicensingConfig::userModel()::count());
    }

    /**
     * The public host this deployment serves — reported to the server's
     * deployment registry. Derived from APP_URL so it's env-driven.
     */
    private function deploymentDomain(): ?string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: null;
    }

    /**
     * Per-feature usage counters reported as telemetry to the server. Populated
     * elsewhere in the app (cache key 'license.feature_usage'); defaults to an
     * empty object so the heartbeat always carries the contract-specified field.
     */
    private function getFeatureUsage(): array
    {
        $usage = Cache::get('license.feature_usage', []);

        return is_array($usage) ? $usage : [];
    }

    /**
     * Persist server-driven operational directives (heartbeat cadence + grace
     * window) returned by activate/heartbeat, so the client follows the server.
     */
    private function persistServerDirectives(array $data): void
    {
        if (isset($data['heartbeat_interval_hours']) && is_numeric($data['heartbeat_interval_hours'])) {
            Cache::forever('license.heartbeat_interval_hours', (int) $data['heartbeat_interval_hours']);
        }

        if (isset($data['grace_period_days']) && is_numeric($data['grace_period_days'])) {
            Cache::forever('license.grace_period_days', (int) $data['grace_period_days']);
        }
    }

    private function markLogsSynced(): void
    {
        LicenseAuditLog::where('synced', false)
            ->oldest()
            ->limit(100)
            ->update(['synced' => true, 'synced_at' => now()]);
    }
}
