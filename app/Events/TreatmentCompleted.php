<?php

namespace App\Events;

use App\Models\Risk;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TreatmentCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TreatmentPlan $treatment,
        public Risk $risk
    ) {}
}
