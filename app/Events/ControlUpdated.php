<?php

namespace App\Events;

use App\Models\Control;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ControlUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int|null  $userId  who made the change, captured when the event
     *                            is dispatched so a listener (queued or not)
     *                            attributes its own writes to them
     */
    public function __construct(
        public Control $control,
        public array $changedFields = [],
        public ?int $userId = null,
    ) {}
}
