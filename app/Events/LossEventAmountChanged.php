<?php

namespace App\Events;

use App\Models\LossEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LossEventAmountChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LossEvent $lossEvent,
        public int $oldAmountKobo,
        public int $newAmountKobo
    ) {}
}
