<?php

namespace App\Events;

use App\Models\Control;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ControlUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Control $control,
        public array $changedFields = []
    ) {}
}
