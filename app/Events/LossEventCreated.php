<?php

namespace App\Events;

use App\Models\LossEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LossEventCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LossEvent $lossEvent
    ) {}
}
