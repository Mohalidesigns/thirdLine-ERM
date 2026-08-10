<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ThresholdRebaselineService;
use Illuminate\Console\Command;

/**
 * Re-evaluates formula-valued thresholds against a closed period and raises
 * approval tasks where a limit has drifted past tolerance.
 *
 * Runs on a schedule as well as from the period-close screen, because a period
 * closed by a job at midnight has no request to run the review inside.
 */
class RebaselineThresholds extends Command
{
    protected $signature = 'measures:rebaseline-thresholds
                            {--period= : Period code to evaluate against; defaults to each organisation\'s most recently closed period}
                            {--organization= : Limit the run to one organization id}';

    protected $description = 'Re-evaluate formula thresholds and raise re-baselining approvals where they have drifted';

    public function handle(ThresholdRebaselineService $rebaseline): int
    {
        $organizationId = $this->option('organization');

        if ($organizationId !== null) {
            $period = $this->resolvePeriod((int) $organizationId);

            if ($period === null) {
                $this->error('No closed period found for that organisation.');

                return self::FAILURE;
            }

            $result = $rebaseline->review($period, (int) $organizationId);
        } else {
            $result = $rebaseline->reviewAllOrganizations($this->option('period'));
        }

        $this->info(sprintf(
            'Examined %d formula threshold(s): %d drifted, %d approval(s) raised, %d skipped for want of inputs.',
            $result['examined'], $result['drifted'], $result['raised'], $result['skipped']
        ));

        return self::SUCCESS;
    }

    private function resolvePeriod(int $organizationId): ?Period
    {
        $code = $this->option('period');

        return Period::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->when($code !== null, fn ($query) => $query->where('code', $code))
            ->when($code === null, fn ($query) => $query->where('is_closed', true))
            ->orderByDesc('end_date')
            ->first();
    }
}
