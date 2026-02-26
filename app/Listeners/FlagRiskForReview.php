<?php

namespace App\Listeners;

use App\Events\LossEventCreated;

class FlagRiskForReview
{
    public function handle(LossEventCreated $event): void
    {
        $lossEvent = $event->lossEvent;

        // Get the linked risk
        if ($lossEvent->risk_id) {
            $risk = $lossEvent->risk;

            // Count loss events in the last 12 months
            $lossEventCount = $risk->lossEvents()
                ->where('created_at', '>=', now()->subYear())
                ->count();

            // Flag for review if 3 or more loss events in 12 months
            if ($lossEventCount >= 3) {
                $risk->update([
                    'review_required' => true,
                    'next_review_date' => now()->addDays(7),
                ]);
            }
        }
    }
}
