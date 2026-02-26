<?php

namespace App\Events;

use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KriBreachDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public KeyRiskIndicator $kri,
        public KriMeasurement $measurement,
        public string $breachLevel // 'red' or 'amber'
    ) {}
}
