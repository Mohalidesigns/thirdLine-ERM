<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Tprm\Access\AccessService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The daily access sweep — FR-ACC-05.
 *
 * Marks grants past their end date and raises the Critical finding each one
 * owes. THE FINDING IS THE PRODUCT. A status quietly flipping to `expired` in
 * a table nobody opens changes nothing about the credential still sitting on
 * a vendor engineer's laptop; the finding is what puts it on somebody's list
 * with a date.
 *
 * RUNS PER TENANT INSIDE `TenantContext::actingAs()`, not inside `bypass()`.
 * The difference matters: bypassing the scope would let one pass see every
 * tenant's grants at once and raise findings against engagements it then
 * attributes to whichever organisation happened to be current. Acting as each
 * tenant in turn keeps the scope on and doing its job, and means one tenant's
 * bad data cannot stop another tenant's findings being raised.
 */
class ReconcileTprmAccess extends Command
{
    protected $signature = 'tprm:reconcile-access
        {--organization= : Limit to one tenant}
        {--dry-run : Report what would be marked and raised, without writing}';

    protected $description = 'Expire third-party access grants past their end date and raise the Critical findings they owe';

    public function handle(AccessService $access): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $organizations = $this->option('organization') !== null
            ? [(int) $this->option('organization')]
            : Organization::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $totalExpired = 0;
        $totalFindings = 0;

        foreach ($organizations as $organizationId) {
            TenantContext::actingAs($organizationId, function () use ($access, $organizationId, &$totalExpired, &$totalFindings): void {
                if ($this->option('dry-run')) {
                    $report = $access->reconciliation();

                    $this->line(sprintf(
                        '  org %-5d %d overdue, %d open-ended, %d against ended relationships',
                        $organizationId,
                        count($report['overdue']),
                        count($report['open_ended']),
                        count($report['discontinued']),
                    ));

                    return;
                }

                $result = $access->expireDueGrants();

                $totalExpired += $result['expired'];
                $totalFindings += $result['findings'];
            });
        }

        if ($this->option('dry-run')) {
            $this->comment('[dry run] Nothing was written.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d grant(s) marked expired, %d Critical finding(s) raised.',
            $totalExpired,
            $totalFindings,
        ));

        return self::SUCCESS;
    }
}
