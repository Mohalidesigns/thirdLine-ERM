<?php

namespace ThirdLine\Platform\Licensing;

use Illuminate\Support\Facades\Cache;

class TamperDetector
{
    public function runChecks(): array
    {
        // Hard signals — these genuinely indicate tampering and lock the app:
        //   • license_file_modified: the stored licence's HMAC no longer matches
        //     this install's app.key (the file was edited/swapped).
        //   • clock_rollback: the wall clock jumped backwards past the drift
        //     tolerance (classic trick to un-expire a licence).
        //   • debugger_detected: a debugger attached in a production environment.
        $issues = [];

        if ($this->detectLicenseFileModification()) {
            $issues[] = 'license_file_modified';
        }

        if ($this->detectClockRollback()) {
            $issues[] = 'clock_rollback';
        }

        if ($this->detectDebuggerAttached()) {
            $issues[] = 'debugger_detected';
        }

        // Soft signals — logged for visibility but NOT lock-worthy on their own.
        // clock_skew and env_manipulation false-positive on legitimate operations
        // (an NTP correction, switching APP_ENV, or a cache clear during
        // re-licensing) and were repeatedly locking valid installs. We still run
        // the detectors (they re-baseline their own anchors) and record a warning,
        // but they no longer gate validation.
        $warnings = [];

        if ($this->detectClockSkew()) {
            $warnings[] = 'clock_skew';
        }

        if ($this->detectEnvironmentManipulation()) {
            $warnings[] = 'env_manipulation';
        }

        if (! empty($issues) || ! empty($warnings)) {
            app(LicenseAuditLogger::class)->log('tamper_detected', [
                'issues' => $issues,
                'warnings' => $warnings,
                'timestamp' => now()->toIso8601String(),
                'hostname' => gethostname(),
            ]);
        }

        // Only hard signals gate validation; warnings are advisory.
        return $issues;
    }

    private function detectClockRollback(): bool
    {
        $lastKnownTime = Cache::get('license.last_known_time', 0);
        $currentTime = time();

        Cache::forever('license.last_known_time', $currentTime);

        if ($lastKnownTime > 0 && $currentTime < ($lastKnownTime - 300)) {
            return true;
        }

        return false;
    }

    private function detectClockSkew(): bool
    {
        $serverTime = Cache::get('license.server_time');
        // The local clock captured at the SAME instant server_time was received.
        $observedAt = Cache::get('license.server_time_observed_at');

        // Without a paired (server_time, observed_at) sample we cannot measure
        // skew. Comparing against unrelated anchors (e.g. last_sync, which a
        // bare activate sets without refreshing server_time) yields false
        // positives, so bail out instead.
        if (! $serverTime || ! $observedAt) {
            return false;
        }

        // A stale sample (server long unreachable) cannot prove a clock anomaly;
        // only enforce skew while the observation is reasonably fresh.
        if ((time() - (int) $observedAt) > 86400) {
            return false;
        }

        // Skew = difference between the two clocks at the moment of observation.
        $skew = abs((int) $observedAt - strtotime($serverTime));
        $maxDrift = config('licensing.max_clock_drift_seconds', 300);

        return $skew > $maxDrift;
    }

    private function detectLicenseFileModification(): bool
    {
        $licensePath = storage_path('licensing/license.enc');
        $sigPath = storage_path('licensing/license.sig');

        if (! file_exists($licensePath) || ! file_exists($sigPath)) {
            return false;
        }

        $encrypted = file_get_contents($licensePath);
        $storedSig = file_get_contents($sigPath);
        $computedSig = hash_hmac('sha256', $encrypted, config('app.key'));

        return ! hash_equals($storedSig, $computedSig);
    }

    private function detectDebuggerAttached(): bool
    {
        if (app()->environment('production') && extension_loaded('xdebug')) {
            return true;
        }

        $debugIndicators = ['XDEBUG_SESSION', 'XDEBUG_CONFIG', 'PHP_IDE_CONFIG'];
        foreach ($debugIndicators as $indicator) {
            if (app()->environment('production') && getenv($indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private function detectEnvironmentManipulation(): bool
    {
        $storedEnv = Cache::get('license.app_env');
        $currentEnv = app()->environment();

        if ($storedEnv && $storedEnv !== $currentEnv) {
            return true;
        }

        Cache::forever('license.app_env', $currentEnv);

        return false;
    }
}
