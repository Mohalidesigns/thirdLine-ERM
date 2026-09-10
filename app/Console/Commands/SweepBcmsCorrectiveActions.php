<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Bcms\Findings\CorrectiveActionService;
use App\Services\Bcms\Integration\ErmBridge;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The nightly CAPA sweep — ISO 22301 clause 10.1 housekeeping.
 *
 * THREE THINGS, and each of them is a state that would otherwise rot silently.
 *
 *   OVERDUE. An action past its due date is marked overdue by this sweep rather
 *   than by an accessor, because an accessor makes "overdue" a property of when
 *   you looked. A board pack printed in March has to still say in December what
 *   it said in March.
 *
 *   LAPSED ACCEPTANCES. A risk accepted until June and still closed in
 *   September is a nonconformity that quietly went away. They reopen.
 *
 *   ISSUES CLOSED IN THE ERM REGISTER. Somebody who has never opened the BCMS
 *   module closes the mirrored issue; the finding should not sit open for
 *   another year. Pulled here rather than by an observer on `Issue`, so that
 *   every issue save in the product does not pay for a BCMS lookup in a
 *   deployment that may not have BCMS switched on.
 */
class SweepBcmsCorrectiveActions extends Command
{
    protected $signature = 'bcms:sweep-actions {--organization= : Restrict to one organisation id}';

    protected $description = 'Mark overdue BCMS corrective actions, reopen lapsed risk acceptances and pull ERM issue closures.';

    public function handle(CorrectiveActionService $actions, ErmBridge $erm): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to sweep.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totals = ['overdue' => 0, 'lapsed' => 0, 'pulled' => 0];

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                $result = $actions->sweep();
                $pulled = $erm->pullClosedIssues($organization->id)->count();

                $totals['overdue'] += $result['overdue'];
                $totals['lapsed'] += $result['lapsed'];
                $totals['pulled'] += $pulled;

                if ($result['overdue'] || $result['lapsed'] || $pulled) {
                    $this->line(sprintf(
                        '%s: %d overdue, %d acceptance(s) lapsed, %d finding(s) closed from the issue register.',
                        $organization->name, $result['overdue'], $result['lapsed'], $pulled
                    ));
                }
            } finally {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            '%d action(s) marked overdue, %d acceptance(s) reopened, %d finding(s) closed from the ERM register.',
            $totals['overdue'], $totals['lapsed'], $totals['pulled']
        ));

        return self::SUCCESS;
    }
}
