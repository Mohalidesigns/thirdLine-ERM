<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Tprm\Exit\ExitPlanService;
use App\Services\Tprm\Incidents\ClockEscalationService;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The regulatory clock sweep — AC-07 — and the exit staleness sweep — AC-11.
 *
 * TWO SWEEPS IN ONE COMMAND because they share a schedule and a failure mode:
 * both exist so that a deadline nobody is watching still reaches somebody.
 * They are separately guarded so a throw in one cannot silence the other.
 *
 * IT RUNS EVERY FIFTEEN MINUTES, which is unusual in this module and is a
 * property of the clocks. A twenty-four-hour window escalates at 50% and 80% —
 * twelve hours and roughly nineteen — and a daily job would miss the first
 * threshold entirely on an incident reported in the afternoon. The escalation
 * table's unique index is what makes a frequent sweep safe: an escalation
 * fires once however often the sweep runs.
 */
class RunTprmClocks extends Command
{
    protected $signature = 'tprm:run-clocks
        {--organization= : Limit to one tenant}
        {--skip-exit : Escalate the regulatory clocks only}';

    protected $description = 'Escalate running regulatory clocks and mark exit plans past their test interval';

    public function handle(ClockEscalationService $escalations, ExitPlanService $exitPlans): int
    {
        if (! config('features.tprm')) {
            $this->comment('TPRM is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $organizations = $this->option('organization') !== null
            ? [(int) $this->option('organization')]
            : Organization::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $escalated = 0;
        $stale = 0;
        $findings = 0;

        foreach ($organizations as $organizationId) {
            TenantContext::actingAs($organizationId, function () use (
                $escalations, $exitPlans, $organizationId, &$escalated, &$stale, &$findings
            ): void {
                try {
                    $escalated += $escalations->sweep($organizationId)['escalated'];
                } catch (\Throwable $exception) {
                    // Separately guarded: a throw in the clock sweep must not
                    // stop the exit sweep, and neither may stop the next
                    // tenant's.
                    $this->error(sprintf('Clock sweep failed for org %d: %s', $organizationId, $exception->getMessage()));
                }

                if ($this->option('skip-exit')) {
                    return;
                }

                try {
                    $result = $exitPlans->markStale($organizationId);
                    $stale += $result['marked'];
                    $findings += $result['findings'];
                } catch (\Throwable $exception) {
                    $this->error(sprintf('Exit sweep failed for org %d: %s', $organizationId, $exception->getMessage()));
                }
            });
        }

        $this->info(sprintf('%d clock escalation(s) fired.', $escalated));

        if (! $this->option('skip-exit')) {
            $this->info(sprintf(
                '%d exit plan(s) marked stale, %d finding(s) raised.',
                $stale,
                $findings,
            ));
        }

        return self::SUCCESS;
    }
}
