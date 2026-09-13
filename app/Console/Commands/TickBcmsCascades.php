<?php

namespace App\Console\Commands;

use App\Models\Bcms\CallTreeTest;
use App\Models\Organization;
use App\Services\Bcms\CallTrees\CascadeEngine;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Advance every running cascade: settle timeouts, escalate to deputies,
 * release whatever that unblocks.
 *
 * EVERY MINUTE, BECAUSE THE UNIT OF MEASUREMENT IS A MINUTE. A node's
 * `expected_response_minutes` is commonly ten or fifteen, and a sweep that ran
 * every five would report a fifteen-minute window as having closed after
 * twenty. The scorecard's whole value is that its timings are true.
 *
 * THE LIVE SCREEN ALSO TICKS. Polling the map advances the same clock, so a
 * cascade somebody is watching stays correct between sweeps. Both paths are
 * idempotent: a tick settles what is already overdue and touches nothing else,
 * so the two racing produces the same answer as either alone.
 *
 * A CASCADE IS NEVER AUTO-COMPLETED. When every node has settled the test is
 * finished in fact, and a human still has to close it — the facilitator adds
 * what the system could not see (who was in a meeting, whose phone was in a
 * drawer) and that note is part of the evidence. Closing it for them would file
 * the report before the person running the test had read it.
 */
class TickBcmsCascades extends Command
{
    protected $signature = 'bcms:cascade-tick
                            {--organization= : Restrict to one organisation id}';

    protected $description = 'Advance running call tree cascades: timeouts, deputy escalation and tier release.';

    public function handle(CascadeEngine $engine): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to advance.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totals = ['cascades' => 0, 'escalated' => 0, 'timed_out' => 0, 'released' => 0];

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                $running = CallTreeTest::query()
                    ->whereNotNull('initiated_at')
                    ->whereNull('completed_at')
                    ->get();

                foreach ($running as $test) {
                    $result = $engine->tick($test);

                    $totals['cascades']++;
                    $totals['escalated'] += $result['escalated'];
                    $totals['timed_out'] += $result['timed_out'];
                    $totals['released'] += $result['released'];
                }
            } finally {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            '%d running cascades: %d escalated to a deputy, %d timed out, %d nodes released.',
            $totals['cascades'], $totals['escalated'], $totals['timed_out'], $totals['released'],
        ));

        return self::SUCCESS;
    }
}
