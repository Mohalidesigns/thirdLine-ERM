<?php

namespace App\Events;

use App\Models\NearMiss;
use App\Models\LossEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NearMissConverted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public NearMiss $nearMiss,
        public LossEvent $lossEvent
    ) {}
}
