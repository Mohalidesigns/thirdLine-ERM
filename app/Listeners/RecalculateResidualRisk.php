<?php

namespace App\Listeners;

use App\Events\ControlUpdated;
use App\Services\ControlEffectivenessService;

class RecalculateResidualRisk
{
    public function __construct(
        private ControlEffectivenessService $effectivenessService
    ) {}

    public function handle(ControlUpdated $event): void
    {
        foreach ($event->control->risks as $risk) {
            $this->effectivenessService->recalculateForRisk($risk);
        }
    }
}
