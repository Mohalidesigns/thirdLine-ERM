<?php

namespace App\Events;

use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched AFTER COMMIT (ADR 0026 section 6.4). Outside a transaction it
 * dispatches immediately, exactly as before, so the nightly sweep and the manual
 * entry path are unchanged. Inside one - the champion's atomic submit - it waits
 * for the commit and is dropped on rollback, so a submit that rolls back cannot
 * have queued a breach notification for a reading that was never kept.
 */
class KriBreachDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public KeyRiskIndicator $kri,
        public KriMeasurement $measurement,
        public string $breachLevel // 'red' or 'amber'
    ) {}
}
