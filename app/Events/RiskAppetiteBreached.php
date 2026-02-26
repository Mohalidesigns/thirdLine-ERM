<?php

namespace App\Events;

use App\Models\RiskCategory;
use App\Models\RiskAppetite;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiskAppetiteBreached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public RiskCategory $category,
        public RiskAppetite $appetite,
        public float $currentPosition,
        public float $threshold
    ) {}
}
