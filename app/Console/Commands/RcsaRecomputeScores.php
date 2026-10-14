<?php

namespace App\Console\Commands;

use App\Models\Rcsa\RcsaAssessment;
use App\Services\Rcsa\RcsaAssessmentService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Repair assessment lines that hold all three answers but no computed scores.
 *
 * WHY THEY EXIST: before the line save became a locked compare-and-swap, three
 * near-simultaneous autosaves (likelihood, impact, control effectiveness) each
 * computed from a snapshot holding only their own field. The answers all
 * landed; inherent, residual and treatment did not, and the submission gate
 * read the null band as "not above appetite" (RCSA-01).
 *
 * THE ENGINE IS THE ONE THE WORKSPACE USES (`RcsaAssessmentService::
 * recomputeUnscored`), so every repaired line gets its revisions and a version
 * bump, with no user attached (the repair is the system's, not a person's) and
 * the reason "Recomputed by rcsa:recompute-scores". Nothing is
 * deleted or rewritten in the audit tables.
 *
 * LOCKED LINES BELONG TO A SUBMITTED ASSESSMENT: what was filed is evidence, so
 * they are reported and skipped unless `--include-locked` says the repair is
 * wanted on the record too. Run `--dry-run` first.
 */
class RcsaRecomputeScores extends Command
{
    protected $signature = 'rcsa:recompute-scores
        {--org= : Restrict to one organization id.}
        {--dry-run : Report what would be repaired without writing.}
        {--include-locked : Also repair lines of submitted (locked) assessments.}';

    protected $description = 'Recalculate RCSA lines that have answers but no stored scores (RCSA-01 repair)';

    public function handle(RcsaAssessmentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeLocked = (bool) $this->option('include-locked');
        $org = $this->option('org');

        $query = RcsaAssessment::query()->withoutGlobalScopes()->orderBy('id');

        if ($org !== null && $org !== '') {
            $query->where('organization_id', (int) $org);
        }

        $previous = TenantContext::organizationIdOrNull();
        $rows = [];
        $repaired = 0;
        $lockedSkipped = 0;

        foreach ($query->cursor() as $assessment) {
            // Tenant context per assessment so every query the service makes is
            // scoped to the tenant that owns it.
            TenantContext::set((int) $assessment->organization_id);

            try {
                $stuck = $service->unscoredLines($assessment, true)->get(['id', 'locked_at']);

                if ($stuck->isEmpty()) {
                    continue;
                }

                $locked = $stuck->whereNotNull('locked_at')->count();
                $eligible = $includeLocked ? $stuck->count() : $stuck->count() - $locked;
                $skipped = $includeLocked ? 0 : $locked;

                $done = $dryRun ? 0 : $service->recomputeUnscored($assessment, null, $includeLocked, 'Recomputed by rcsa:recompute-scores');

                $repaired += $dryRun ? $eligible : $done;
                $lockedSkipped += $skipped;

                $rows[] = [
                    $assessment->organization_id,
                    $assessment->id,
                    $stuck->count(),
                    $dryRun ? $eligible : $done,
                    $skipped,
                ];
            } finally {
                TenantContext::set($previous);
            }
        }

        if ($rows !== []) {
            $this->table(
                ['Org', 'Assessment', 'Unscored lines', $dryRun ? 'Would repair' : 'Repaired', 'Locked, skipped'],
                $rows,
            );
        }

        $this->info(sprintf(
            '%s%d line(s) %s, %d locked line(s) skipped.',
            $dryRun ? '[dry run] ' : '',
            $repaired,
            $dryRun ? 'would be repaired' : 'repaired',
            $lockedSkipped,
        ));

        return self::SUCCESS;
    }
}
