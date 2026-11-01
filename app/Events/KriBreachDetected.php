<?php

namespace App\Events;

use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by MeasureService::announceBreach() through DB::afterCommit(), with
 * a try/catch per event (ADR 0026 section 6.4, as amended): outside a transaction
 * it runs at once; inside one - the champion's atomic submit - it waits for the
 * commit and is dropped on rollback. It deliberately does NOT implement
 * ShouldDispatchAfterCommit: that moves the listeners outside announceBreach()'s
 * try/catch, so a listener error or a Redis outage would 500 a submit that had
 * already committed and starve the remaining breach events.
 */
class KriBreachDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public KeyRiskIndicator $kri,
        public KriMeasurement $measurement,
        public string $breachLevel // 'red' or 'amber'
    ) {}
}
