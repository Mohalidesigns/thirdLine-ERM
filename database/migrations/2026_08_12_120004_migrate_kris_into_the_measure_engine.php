<?php

use App\Support\Measures\KriMeasureMigrator;
use Illuminate\Database\Migrations\Migration;

/**
 * WP-04 TASK 3 — move every KRI, its thresholds and its measurement history
 * onto the measure engine.
 *
 * Additive. key_risk_indicators and kri_measurements are read, never written
 * and never dropped: they remain the facade the current screens read for one
 * release, and KriController now writes through the engine AND keeps them in
 * step (rule 1 — stop writing and backfill in this release, drop in the next).
 *
 * See App\Support\Measures\KriMeasureMigrator for what is and is not
 * recoverable, particularly for the breach backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        info('WP-04: KRIs migrated into the measure engine.', app(KriMeasureMigrator::class)->migrateAll());
    }

    public function down(): void
    {
        // The rows this migration wrote live in tables created by 120002, whose
        // own rollback drops them. Deleting kri-kind measures here as well
        // would take out any measure a user has since defined against the same
        // code, so the rollback is deliberately a no-op.
    }
};
