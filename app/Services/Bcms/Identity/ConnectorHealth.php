<?php

namespace App\Services\Bcms\Identity;

use App\Enums\Bcms\SyncRunStatus;
use App\Enums\Bcms\SyncTrigger;
use App\Models\Bcms\IdentityConnector;
use App\Models\Bcms\IdentitySyncRun;

/**
 * Health and last-success, DERIVED from `bcms_identity_sync_runs` — never a
 * stored column on the connector (ADR 0018 §2.2 point 3). The same argument
 * ADR 0013 made for call-tree staleness: a stored health column is only as
 * true as its last write, and a dashboard reading one reports the health of
 * the scheduler rather than the connector.
 */
class ConnectorHealth
{
    /** Twice a nightly schedule (work order §7) — the watchdog's own threshold. */
    private const STALE_AFTER_HOURS = 48;

    /** @return array<string, mixed> */
    public function summarize(IdentityConnector $connector): array
    {
        $lastRun = $connector->runs()->first();
        $lastSuccess = $this->lastSuccess($connector);
        $lastFullSuccess = $this->lastFullSuccess($connector);

        return [
            'last_run_at' => $lastRun?->started_at?->toIso8601String(),
            'last_run_status' => $lastRun?->status?->value,
            'last_run_error_code' => $lastRun?->error_code,
            'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
            'is_stale' => $this->isStale($connector, $lastSuccess),
            // ADR 0018 §3.1: a delta never re-resolves a manager edge, so
            // `is_stale` above (which counts ANY successful run, delta
            // included) can read green while the manager hierarchy has
            // quietly stopped being maintained for days. This is the
            // screen's half of the same check `BcmsWatchdog::
            // hierarchyStale()` runs for the alert — the two must move
            // together, which is why both read the identical trigger/status/
            // window rule rather than one deriving from the other.
            'last_full_reconciliation_at' => $lastFullSuccess?->finished_at?->toIso8601String(),
            'full_reconciliation_stale' => $this->fullReconciliationStale($connector, $lastFullSuccess),
            'credential_expires_on' => $connector->credential_expires_on?->toDateString(),
            'credential_expiring_soon' => $this->credentialExpiringSoon($connector),
        ];
    }

    public function isStale(IdentityConnector $connector, ?IdentitySyncRun $lastSuccess = null): bool
    {
        if (! $connector->is_active || $connector->sync_schedule === 'manual') {
            return false;
        }

        $lastSuccess ??= $this->lastSuccess($connector);

        if ($lastSuccess === null || $lastSuccess->started_at === null) {
            return true;
        }

        return $lastSuccess->started_at->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * Whether the manager hierarchy itself has stopped being maintained —
     * true only from a successful FULL reconciliation (`scheduled_full` or
     * `manual`), never a delta. A tenant on `nightly_plus_delta` can have
     * every fifteen-minute tick succeed while the nightly full has failed
     * every night for a week; `isStale()` would report that connector
     * healthy, because it does not distinguish which trigger produced the
     * success.
     */
    public function fullReconciliationStale(IdentityConnector $connector, ?IdentitySyncRun $lastFullSuccess = null): bool
    {
        if (! $connector->is_active || $connector->sync_schedule === 'manual') {
            return false;
        }

        $lastFullSuccess ??= $this->lastFullSuccess($connector);

        if ($lastFullSuccess === null || $lastFullSuccess->started_at === null) {
            return true;
        }

        return $lastFullSuccess->started_at->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    public function credentialExpiringSoon(IdentityConnector $connector): bool
    {
        return $connector->credential_expires_on !== null
            && $connector->credential_expires_on->lte(now()->addDays(30));
    }

    private function lastSuccess(IdentityConnector $connector): ?IdentitySyncRun
    {
        return $connector->runs()
            ->whereIn('status', [SyncRunStatus::Success->value, SyncRunStatus::Partial->value])
            ->orderByDesc('started_at')
            ->first();
    }

    private function lastFullSuccess(IdentityConnector $connector): ?IdentitySyncRun
    {
        return $connector->runs()
            ->whereIn('trigger', [SyncTrigger::ScheduledFull->value, SyncTrigger::Manual->value])
            ->whereIn('status', [SyncRunStatus::Success->value, SyncRunStatus::Partial->value])
            ->orderByDesc('started_at')
            ->first();
    }
}
