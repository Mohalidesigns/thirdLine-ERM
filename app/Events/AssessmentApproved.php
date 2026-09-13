<?php

namespace App\Events;

use App\Models\Risk;
use App\Models\RiskAssessment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssessmentApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public RiskAssessment $assessment,
        public Risk $risk
    ) {}
}
