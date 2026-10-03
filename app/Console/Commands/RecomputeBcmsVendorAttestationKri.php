<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Bcms\ResilienceKriPublisher;
use App\Services\Bcms\Suppliers\SupplierResilienceService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * B7: `BCMS-VENDOR-ATTEST` was only ever measured on `storeAttestation()` and
 * the demo seeder — a vendor's evidence simply AGEING PAST `next_due_at`
 * never moved the KRI, because nothing else ever recomputed it. This is the
 * daily sweep that does, so the register reflects reality even on a tenant
 * where nobody records a new attestation that day.
 *
 * IDEMPOTENT AND SAFE TO RUN TWICE: `attestationRate()` is a pure read over
 * `tp_bcp_tests`/`tp_engagements`, and `recordVendorAttestation()` writes
 * through `KriMeasureBridge::recordMeasurement()`, which keys one measurement
 * per KRI per period — re-running this command the same day updates that same
 * row rather than creating a second one.
 */
class RecomputeBcmsVendorAttestationKri extends Command
{
    protected $signature = 'bcms:vendor-attestation-recompute';

    protected $description = 'Recompute and file the BCMS-VENDOR-ATTEST KRI reading for every tenant, so evidence ageing past its due date moves the register without waiting for a new attestation.';

    public function handle(SupplierResilienceService $suppliers, ResilienceKriPublisher $kris): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to recompute.');

            return self::SUCCESS;
        }

        $measured = 0;
        $skippedNull = 0;

        foreach (Organization::query()->get() as $organization) {
            TenantContext::set($organization->id);

            try {
                $rate = $suppliers->attestationRate();

                if ($rate === null) {
                    // No BCMS-critical vendor dependency graph on this
                    // tenant yet — a null reading is skipped and said out
                    // loud, never published as zero (development standard
                    // §5, ADR 0021 §2).
                    $skippedNull++;

                    continue;
                }

                $kris->recordVendorAttestation($rate, 'Recomputed by the daily bcms:vendor-attestation-recompute sweep.');
                $measured++;
            } finally {
                TenantContext::clear();
            }
        }

        $this->info("{$measured} tenant(s) measured; {$skippedNull} skipped (no BCMS-critical vendor dependency yet).");

        return self::SUCCESS;
    }
}
