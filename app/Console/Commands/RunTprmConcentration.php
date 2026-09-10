<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Tprm\Graph\ConcentrationService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The concentration snapshot — FR-NTH-03 through FR-NTH-05.
 *
 * WEEKLY, NOT DAILY. Concentration is a property of the portfolio's shape, and
 * a portfolio's shape does not move overnight. A daily run would produce three
 * hundred and sixty-five near-identical snapshots a year, which is not a
 * history — it is a haystack with the four quarters somebody actually wants to
 * compare buried in it.
 *
 * The alert only fires on a CHANGE in the breach set, for the same reason: a
 * portfolio that breaches on Monday breaches every day until somebody moves a
 * service, and a daily email about it stops being read by Thursday.
 */
class RunTprmConcentration extends Command
{
    protected $signature = 'tprm:run-concentration
        {--organization= : Limit to one tenant}
        {--dimension= : Run one dimension rather than all}';

    protected $description = 'Snapshot the portfolio concentration analysis and alert on newly breached thresholds';

    public function handle(ConcentrationService $concentration): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $organizations = $this->option('organization') !== null
            ? [(int) $this->option('organization')]
            : Organization::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $dimension = $this->option('dimension');
        $runs = 0;
        $breaches = 0;

        foreach ($organizations as $organizationId) {
            TenantContext::actingAs($organizationId, function () use (
                $concentration, $organizationId, $dimension, &$runs, &$breaches
            ): void {
                $analyses = $dimension !== null
                    ? collect([$concentration->run($organizationId, $dimension)])
                    : $concentration->runAll($organizationId);

                $runs += $analyses->count();
                $breaches += $analyses->sum(fn ($analysis) => count($analysis->threshold_breaches ?? []));
            });
        }

        $this->info(sprintf('%d snapshot(s) taken across %d tenant(s).', $runs, count($organizations)));

        if ($breaches > 0) {
            $this->warn(sprintf('%d threshold breach(es) standing.', $breaches));
        }

        return self::SUCCESS;
    }
}
