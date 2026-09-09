<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Bcms\CallTrees\CallTreeService;
use App\Services\Bcms\CallTrees\TreeHealthService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The nightly call-tree hygiene sweep (Blueprint §6.4, criterion 6).
 *
 * A LEAVER IS THE FAILURE THIS EXISTS FOR. Somebody resigns on Friday, HR
 * deactivates them, and a cascade tree that has looked complete for two years
 * keeps looking complete — the node still has a name on it. Nothing goes red
 * on its own. This sweep is what makes it go red, and the number beside it is
 * the downstream count, because "Ibrahim has left" and "Ibrahim has left and
 * thirty-four people now have nobody to call them" get fixed at different
 * speeds.
 *
 * IT CONSUMES THE DIRECTORY SYNC RATHER THAN PERFORMING ONE. Phase 2C owns the
 * staged AD/Entra read and the human-approved change report; by the time this
 * runs, a leaver is already an inactive contact. Reading LDAP here would be a
 * second sync with its own opinion about who works at the bank.
 *
 * IT FLAGS AND IT DOES NOT RAISE A FINDING, and that is a deliberate refusal.
 * `FindingSource::CallTreeTest` carries `call_tree_test_id`, and the register
 * declines a source with a foreign key and nothing to point at — correctly, or
 * a nightly sweep would file one unlinked finding every night for ever. The
 * alternatives were both worse: inventing a ninth source for a sweep, or
 * filing under `audit`, which would tell a certification auditor that internal
 * audit raised something they have never seen.
 *
 * So the sweep writes an audit row and moves the numbers on the dashboard and
 * the designer's problems panel, which is what criterion 6 asks for — "flags
 * the tree as impacted with the downstream count". The corrective action is
 * raised by a person, from the broken-branch screen, against a cascade that
 * actually ran (criterion 9).
 */
class SweepBcmsCallTreeHealth extends Command
{
    protected $signature = 'bcms:call-tree-hygiene
                            {--organization= : Restrict to one organisation id}';

    protected $description = 'Flag orphaned call tree nodes and overdue trees, and mirror the cascade KRIs.';

    public function handle(CallTreeService $trees, TreeHealthService $health): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to sweep.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totals = ['trees' => 0, 'stale' => 0, 'orphans' => 0, 'blocked' => 0, 'kris' => 0];

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                foreach ($trees->currentQuery()->get() as $tree) {
                    $totals['trees']++;

                    $orphans = $trees->orphanedNodes($tree);
                    $stale = $trees->isStale($tree);

                    if ($stale) {
                        $totals['stale']++;
                    }

                    if ($orphans === [] && ! $stale) {
                        continue;
                    }

                    $blocked = array_sum(array_column($orphans, 'downstream_count'));
                    $totals['orphans'] += count($orphans);
                    $totals['blocked'] += $blocked;

                    $tree->recordAudit('call_tree.hygiene_flagged', [
                        'orphaned_nodes' => count($orphans),
                        'downstream_blocked' => $blocked,
                        'is_stale' => $stale,
                    ]);

                    $this->line(sprintf(
                        '%s: %d orphaned %s blocking %d downstream%s.',
                        $tree->name,
                        count($orphans),
                        count($orphans) === 1 ? 'node' : 'nodes',
                        $blocked,
                        $stale ? ', and the tree is overdue for review' : '',
                    ));
                }

                $totals['kris'] += count($health->mirrorKris());
            } finally {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            '%d trees checked: %d overdue, %d orphaned nodes blocking %d staff, %d KRIs mirrored.',
            $totals['trees'], $totals['stale'], $totals['orphans'],
            $totals['blocked'], $totals['kris'],
        ));

        return self::SUCCESS;
    }
}
