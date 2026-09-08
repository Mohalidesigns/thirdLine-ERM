<?php

namespace App\Console\Commands;

use App\Models\Bcms\BiaCampaign;
use App\Models\Organization;
use App\Services\Bcms\Bia\BiaCampaignService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The daily BIA chase — acceptance criterion 7's "on schedule".
 *
 * IT CHASES ON AN INTERVAL, NOT EVERY NIGHT. `BiaCampaignService::CHASE_INTERVAL_DAYS`
 * is what stops this teaching forty process owners to filter these into a
 * folder, at which point the escalation that follows reaches somebody who has
 * already stopped reading.
 *
 * ESCALATION HAPPENS ONCE AND IS RECORDED. `escalated_at` and
 * `escalated_to_user_id` are why: a sweep with no memory either escalates every
 * night or cannot say whether it escalated at all, and "we escalated to the Head
 * of Operations on the 14th" is the only version of that sentence which is
 * evidence.
 *
 * AN OWNER WITH NO MANAGER IS REPORTED, NOT SKIPPED SILENTLY. It is a gap in the
 * contact roster somebody has to close, and swallowing it means the assessment
 * quietly stops escalating forever.
 */
class ChaseBiaAssessments extends Command
{
    protected $signature = 'bcms:chase-bia
                            {--organization= : Restrict to one organisation id}
                            {--campaign= : Restrict to one campaign id}';

    protected $description = 'Chase outstanding BIA assessments and escalate the overdue ones to line managers.';

    public function handle(BiaCampaignService $campaigns): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to chase.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totals = ['chased' => 0, 'escalated' => 0];
        $noManager = [];

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                $open = BiaCampaign::query()
                    ->where('status', 'open')
                    ->when($this->option('campaign'), fn ($q, $id) => $q->whereKey($id))
                    ->get();

                foreach ($open as $campaign) {
                    $result = $campaigns->chase($campaign);

                    $totals['chased'] += $result['chased'];
                    $totals['escalated'] += $result['escalated'];
                    $noManager = array_merge($noManager, $result['no_manager']);

                    if ($result['chased'] > 0 || $result['escalated'] > 0) {
                        $this->line(sprintf(
                            '%s / %s: %d chased, %d escalated.',
                            $organization->name, $campaign->name, $result['chased'], $result['escalated']
                        ));
                    }
                }
            } finally {
                TenantContext::clear();
            }
        }

        if ($noManager !== []) {
            $this->warn(sprintf(
                '%d assessment(s) could not be escalated because no line manager is on record: %s. '
                .'Until that is fixed those assessments will keep being chased and never escalated.',
                count($noManager),
                implode(', ', array_slice($noManager, 0, 12)),
            ));
        }

        $this->info(sprintf('%d chased, %d escalated.', $totals['chased'], $totals['escalated']));

        return self::SUCCESS;
    }
}
