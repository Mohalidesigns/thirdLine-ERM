<?php

namespace ThirdLine\Platform\Licensing\Middleware;

use ThirdLine\Platform\Licensing\DeviceFingerprint;
use ThirdLine\Platform\Licensing\LicenseManager;
use ThirdLine\Platform\Licensing\SyncManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LicenseHeartbeat
{
    public function __construct(
        private LicenseManager $licenseManager,
        private SyncManager $sync,
        private DeviceFingerprint $fingerprint
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Check in with the server when either the usage-heartbeat interval or the
        // shorter revocation-check cadence is due, so a server-side revoke locks the
        // app within minutes rather than waiting out the full heartbeat window.
        //
        // A cooldown gates the check-in: the "due" flags derive from license.last_sync,
        // which only advances on a SUCCESSFUL sync. If the server is unreachable or the
        // heartbeat fails, the due-check stays permanently true and would queue a
        // heartbeat on EVERY request — and its terminate-time network call (with retry
        // backoff) stalls the next request on a single-worker dev server. The cooldown
        // caps check-ins to once per revocation-check window regardless of outcome.
        if ($this->licenseManager->isLicensed()
            && ! Cache::has('license.heartbeat_cooldown')
            && ($this->sync->isHeartbeatDue() || $this->sync->isRevocationCheckDue())) {
            // Run asynchronously via terminate middleware (after the response is sent).
            Cache::put('license.heartbeat_pending', true, 60);
            Cache::put(
                'license.heartbeat_cooldown',
                true,
                now()->addMinutes((int) config('licensing.revocation_check_minutes', 30))
            );
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! Cache::pull('license.heartbeat_pending')) {
            return;
        }

        try {
            $status = $this->licenseManager->getStatus();
            $licenseKey = $status['license_key'] ?? '';

            if (! empty($licenseKey)) {
                $result = $this->sync->heartbeat($licenseKey, $this->fingerprint->generate());

                // Persist server-reported entitlements so /settings/license reflects
                // server-side parameter changes on the next read (no redeploy required).
                $updated = $result['data']['updated_entitlements'] ?? null;
                if (is_array($updated)) {
                    $this->licenseManager->applyServerEntitlements($updated);
                }

                // If revoked, clear the license validation cache to force re-validation
                if (($result['status'] ?? '') === 'revoked') {
                    Cache::forget('license.validation_result');
                }
            }
        } catch (\Exception $e) {
            // Heartbeat should never break the request
            logger()->warning('License heartbeat failed: '.$e->getMessage());
        }
    }
}
