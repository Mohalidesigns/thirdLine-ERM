<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ThirdLine\Platform\Licensing\DeviceFingerprint;
use ThirdLine\Platform\Licensing\LicenseManager;
use ThirdLine\Platform\Licensing\SyncManager;

/**
 * Scheduled licensing heartbeat.
 *
 * The LicenseHeartbeat middleware only syncs when the app receives traffic, so
 * an idle deployment would silently disappear from the server's Deployments
 * view (and miss revocations) until someone browses it. This command keeps the
 * check-in cadence going regardless of traffic; it defers to the server-driven
 * heartbeat interval and the shorter revocation-check cadence unless --force.
 */
class LicenseHeartbeatCommand extends Command
{
    protected $signature = 'license:heartbeat {--force : Sync now even if no check-in is due}';

    protected $description = 'Check in with the licensing server (heartbeat + entitlement refresh), traffic or not.';

    public function handle(LicenseManager $manager, SyncManager $sync, DeviceFingerprint $fingerprint): int
    {
        if (! $manager->isLicensed()) {
            $this->info('No active license — nothing to sync.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $sync->isHeartbeatDue() && ! $sync->isRevocationCheckDue()) {
            $this->info('No check-in due yet (server-driven cadence).');

            return self::SUCCESS;
        }

        $licenseKey = $manager->getStatus()['license_key'] ?? '';
        if ($licenseKey === '') {
            $this->warn('Licensed but no license key resolvable; skipping.');

            return self::SUCCESS;
        }

        $result = $sync->heartbeat($licenseKey, $fingerprint->generate());

        if (($result['status'] ?? '') === 'ok') {
            // Propagate server-side entitlement changes without a redeploy.
            if (! empty($result['data']['updated_entitlements'])) {
                $manager->applyServerEntitlements($result['data']['updated_entitlements']);
            }
            $this->info('Heartbeat OK.');

            return self::SUCCESS;
        }

        if (($result['status'] ?? '') === 'revoked') {
            // SyncManager has already flagged the local revocation lock.
            $this->warn('Server reports the license as revoked — deployment will lock.');

            return self::SUCCESS;
        }

        $this->warn('Heartbeat not completed: '.($result['status'] ?? 'unknown').' '.($result['code'] ?? ''));

        // Unreachable/network issues are not a command failure; the schedule retries.
        return self::SUCCESS;
    }
}
