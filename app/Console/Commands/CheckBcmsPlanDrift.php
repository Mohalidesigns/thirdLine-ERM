<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Bcms\Plans\PlanDriftDetector;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The nightly drift sweep — acceptance criterion 2, made durable.
 *
 * DRIFT IS ALSO DETECTED THE MOMENT A BIA IS APPROVED, and that is the path a
 * user sees: approve a new RTO and the plans bound to it light up before the
 * screen has finished loading. This exists because that path only covers the
 * changes the application itself makes. A vendor soft-deleted in TPRM, a call
 * tree member offboarded, a site closed, a dependency removed — none of those
 * knows that a plan depends on it, and wiring an observer from every one of
 * eight upstream tables into the plan builder would couple the whole product to
 * this module. A nightly re-resolve and compare costs one query per bound
 * section and cannot be forgotten by a future feature.
 *
 * IT RAISES A FINDING FOR AN APPROVED PLAN, ONCE. A drifted draft is somebody's
 * work in progress. A drifted approved plan is a document the bank is relying on
 * that no longer describes it, which is a nonconformity against clause 8.4.4 —
 * and one open finding per plan, not one per night, because a register nobody
 * can read is a register nobody reads.
 */
class CheckBcmsPlanDrift extends Command
{
    protected $signature = 'bcms:check-plan-drift
                            {--organization= : Restrict to one organisation id}
                            {--no-findings : Flag the sections but raise nothing in the CAPA register}';

    protected $description = 'Re-resolve every bound plan section and flag the ones whose source data has changed.';

    public function handle(PlanDriftDetector $detector): int
    {
        if (! config('features.bcms')) {
            $this->line('The BCMS module is switched off; nothing to check.');

            return self::SUCCESS;
        }

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $totals = ['checked' => 0, 'drifted' => 0, 'sections' => 0, 'findings' => 0];

        foreach ($organizations as $organization) {
            TenantContext::set($organization->id);

            try {
                $result = $detector->sweep(raiseFindings: ! $this->option('no-findings'));

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }

                if ($result['drifted'] > 0) {
                    $this->line(sprintf(
                        '%s: %d of %d plans have drifted (%d sections, %d findings raised).',
                        $organization->name,
                        $result['drifted'],
                        $result['checked'],
                        $result['sections'],
                        $result['findings'],
                    ));
                }
            } finally {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            '%d plans checked, %d drifted, %d sections flagged, %d findings raised.',
            $totals['checked'], $totals['drifted'], $totals['sections'], $totals['findings'],
        ));

        return self::SUCCESS;
    }
}
