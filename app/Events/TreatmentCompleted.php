<?php

namespace App\Events;

use App\Models\TreatmentPlan;
use App\Models\Risk;
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
