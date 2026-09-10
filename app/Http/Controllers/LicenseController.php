<?php

namespace App\Http\Controllers;

use App\Http\Requests\License\ActivateLicenseRequest;
use App\Http\Requests\License\OfflineActivateLicenseRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use ThirdLine\Platform\Licensing\DeviceFingerprint;
use ThirdLine\Platform\Licensing\LicenseManager;
use ThirdLine\Platform\Licensing\Models\LicenseAuditLog;
use ThirdLine\Platform\Licensing\SyncManager;

class LicenseController extends Controller
{
    public function __construct(private LicenseManager $licenseManager) {}

    /**
     * Display the license management page under Settings.
     *
     * On every page load we attempt a live validate against LicensingServer (cached for
     * 60 s to absorb refresh bursts) so server-side parameter changes propagate without
     * redeploy or stale state. Failure to reach the server is graceful: the page falls
     * back to the offline JWT-claim view and surfaces a clear "server unreachable" flag.
     */
    public function index(SyncManager $sync, DeviceFingerprint $fingerprint)
    {
        $serverState = $this->refreshFromServer($sync, $fingerprint);

        $status = $this->licenseManager->isLicensed()
            ? $this->licenseManager->getStatus()
            : [
                'valid' => false,
                'mode' => 'unlicensed',
                'plan' => 'none',
                'features' => [],
                'days_remaining' => 0,
                'max_users' => 0,
                'grace_period' => null,
                'license_id' => null,
                'last_sync' => null,
                'last_validate' => null,
                'server_overrides_applied' => false,
                'device_fingerprint' => $this->licenseManager->getFingerprint(),
            ];

        $status['server_reachable'] = $serverState['reachable'];
        $status['server_last_error'] = $serverState['last_error'];
        $status['server_last_validate'] = $serverState['last_validate_at'];

        $auditLogs = LicenseAuditLog::latest()->limit(50)->get()->map(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'metadata' => $log->metadata,
            'synced' => $log->synced,
            'created_at' => $log->created_at?->format('d M Y, H:i:s'),
        ]);

        return Inertia::render('Settings/License', [
            'licenseStatus' => $status,
            'auditLogs' => $auditLogs,
            'availableFeatures' => config('licensing.available_features'),
            'plans' => config('licensing.plans'),
            'totalUsers' => User::count(),
        ]);
    }

    /**
     * Live-validate against LicensingServer and apply any updated entitlements,
     * capped at one call per 60 s. Returns a small reachability summary for the UI.
     */
    private function refreshFromServer(SyncManager $sync, DeviceFingerprint $fingerprint): array
    {
        if (! $this->licenseManager->isLicensed()) {
            return ['reachable' => null, 'last_error' => null, 'last_validate_at' => null];
        }

        $licenseKey = $this->licenseManager->getStatus()['license_key'] ?? null;
        if (empty($licenseKey)) {
            return ['reachable' => null, 'last_error' => null, 'last_validate_at' => null];
        }

        $cacheKey = 'license.page_validate_throttle';
        if (Cache::get($cacheKey)) {
            // Within the throttle window — surface whatever we last cached.
            return [
                'reachable' => Cache::get('license.server_reachable'),
                'last_error' => Cache::get('license.server_last_error'),
                'last_validate_at' => $sync->getLastValidateTimestamp(),
            ];
        }
        Cache::put($cacheKey, 1, now()->addSeconds(60));

        $result = $sync->validate($licenseKey, $fingerprint->generate(), User::count());

        if (($result['status'] ?? '') === 'ok') {
            $data = $result['data'] ?? [];
            $entitlements = $data['entitlements'] ?? [];
            if (! empty($entitlements)) {
                $entitlements['expires_at'] = $data['expires_at'] ?? ($entitlements['expires_at'] ?? null);
                $this->licenseManager->applyServerEntitlements($entitlements);
            }

            Cache::forever('license.server_reachable', true);
            Cache::forget('license.server_last_error');

            return [
                'reachable' => true,
                'last_error' => null,
                'last_validate_at' => $sync->getLastValidateTimestamp(),
            ];
        }

        $error = $result['message'] ?? $result['error'] ?? 'Licensing server is unreachable.';
        Cache::forever('license.server_reachable', false);
        Cache::forever('license.server_last_error', $error);

        return [
            'reachable' => false,
            'last_error' => $error,
            'last_validate_at' => $sync->getLastValidateTimestamp(),
        ];
    }

    /**
     * Activate license with a license key (online activation).
     */
    public function activate(ActivateLicenseRequest $request)
    {

        $result = $this->licenseManager->activate($request->license_key);

        if ($result['success']) {
            return redirect()->route('admin.license')
                ->with('success', 'License activated successfully!');
        }

        return redirect()->route('admin.license')
            ->with('error', $result['error'] ?? 'License activation failed.');
    }

    /**
     * Offline activation — upload a signed license file.
     */
    public function offlineActivate(OfflineActivateLicenseRequest $request)
    {

        $content = file_get_contents($request->file('license_file')->getRealPath());

        // Try to decode as base64-encoded license envelope
        $decoded = base64_decode($content, true);
        if ($decoded) {
            $envelope = json_decode($decoded, true);
            if ($envelope && isset($envelope['token'])) {
                // Valid license envelope — extract the JWT token
                $token = $envelope['token'];
            } else {
                // Base64 content but not a valid envelope — treat as raw JWT
                $token = $decoded;
            }
        } else {
            // Not base64 — treat as raw JWT token
            $token = $content;
        }

        $result = $this->licenseManager->activate(trim($token));

        if ($result['success']) {
            return redirect()->route('admin.license')
                ->with('success', 'License activated successfully via offline activation!');
        }

        return redirect()->route('admin.license')
            ->with('error', $result['error'] ?? 'Offline license activation failed.');
    }

    /**
     * Generate a device fingerprint file for offline activation.
     */
    public function generateFingerprint()
    {
        $fingerprintData = $this->licenseManager->getFingerprintFile();

        return response()->json([
            'fingerprint_file' => $fingerprintData,
            'fingerprint' => $this->licenseManager->getFingerprint(),
        ]);
    }

    /**
     * Deactivate the current license — both server-side (release the activation slot)
     * and locally (remove the encrypted JWT). The local cleanup runs unconditionally
     * so the user is never trapped with a license they can't remove, even if the
     * server is unreachable; in that case a warning is surfaced.
     */
    public function deactivate(SyncManager $sync, DeviceFingerprint $fingerprint)
    {
        $status = $this->licenseManager->getStatus();
        $licenseKey = $status['license_key'] ?? null;

        $serverWarning = null;
        if (! empty($licenseKey)) {
            $serverResult = $sync->deactivate($licenseKey, $fingerprint->generate());
            if (($serverResult['status'] ?? '') !== 'ok') {
                $serverWarning = $serverResult['status'] === 'unreachable'
                    ? 'Licensing server unreachable; activation slot may still be held until next reconciliation.'
                    : ($serverResult['message'] ?? 'Server-side deactivation reported an error.');
            }
        }

        $this->licenseManager->deactivate();

        $message = 'License has been deactivated.';
        if ($serverWarning) {
            return redirect()->route('admin.license')
                ->with('error', $message.' '.$serverWarning);
        }

        return redirect()->route('admin.license')->with('success', $message);
    }

    /**
     * Trigger a manual heartbeat sync.
     */
    public function syncHeartbeat()
    {
        $sync = app(SyncManager::class);
        $fingerprint = app(DeviceFingerprint::class);

        $status = $this->licenseManager->getStatus();
        $licenseKey = $status['license_key'] ?? '';

        if (empty($licenseKey)) {
            return redirect()->route('admin.license')
                ->with('error', 'No active license key found for heartbeat sync.');
        }

        $result = $sync->heartbeat(
            $licenseKey,
            $fingerprint->generate()
        );

        $message = match ($result['status']) {
            'ok' => 'Heartbeat sync completed successfully.',
            'revoked' => 'WARNING: License has been revoked by the server.',
            'unreachable' => 'Licensing server is unreachable. Will retry later.',
            default => 'Heartbeat sync returned status: '.$result['status'],
        };

        return redirect()->route('admin.license')
            ->with($result['status'] === 'ok' ? 'success' : 'error', $message);
    }
}
