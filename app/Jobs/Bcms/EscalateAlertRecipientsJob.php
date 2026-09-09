<?php

namespace App\Jobs\Bcms;

use App\Models\Bcms\Alert;
use App\Services\Bcms\Emns\EscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Chase the people who have not answered, once the acknowledgement window has
 * run out.
 *
 * IT IS SCHEDULED AT DISPATCH RATHER THAN POLLED. The window is per alert
 * (`ack_window_minutes`), so a job delayed by exactly that long is both cheaper
 * and more accurate than a sweep that runs every minute and asks every alert
 * whether it is time yet.
 *
 * ESCALATION GOES TO SOMEBODY ELSE, NEVER BACK TO THE PERSON. They have already
 * had the alert on every channel they have; a seventh copy is what teaches
 * people to ignore the sixth. The manager is the one who can physically go and
 * find them, which in an evacuation is the only thing that helps.
 */
class EscalateAlertRecipientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public int $alertId,
        public int $organizationId,
    ) {}

    public function handle(EscalationService $escalation): void
    {
        TenantContext::set($this->organizationId);

        try {
            $alert = Alert::query()->find($this->alertId);

            if ($alert === null || ! $alert->escalation_enabled) {
                return;
            }

            $escalation->escalateUnacknowledged($alert);
        } finally {
            TenantContext::clear();
        }
    }
}
