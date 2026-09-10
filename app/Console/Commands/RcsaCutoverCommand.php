<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Rcsa\RcsaMigrationReconciler;
use App\Support\Rcsa\RcsaCutover;
use Illuminate\Console\Command;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * §13 step 6 and step 7: cut a tenant over, or reverse it.
 *
 * IT REFUSES TO CUT OVER AN UNRECONCILED TENANT. §13 step 4 says "sign off the
 * reconciliation before cutover", and a step that is only written down in a
 * runbook is a step somebody skips at 2am on a Saturday. The reconciliation
 * runs here, and a blocker — a whole business unit present in the legacy module
 * and absent from v2 — stops the cutover. `--force` exists because an operator
 * with a reason the code cannot know must not be locked out, and it says
 * loudly what it is overriding.
 *
 * TRIAGE ITEMS DO NOT BLOCK, they are shown and acknowledged. A legacy response
 * whose risk was deleted years ago can never be migrated by anybody; refusing
 * cutover for ever on that basis would train operators to reach for --force
 * every time, which is worse than not checking.
 */
class RcsaCutoverCommand extends Command
{
    protected $signature = 'rcsa:cutover
        {--organization= : The tenant to cut over.}
        {--reverse : Undo the cutover, re-opening the legacy write path.}
        {--commit : Actually record it. Without this, nothing changes.}
        {--force : Cut over despite reconciliation blockers. Say why in the change record.}';

    protected $description = 'Cut one tenant over from the legacy RCSA module to RCSA v2, or reverse it';

    public function handle(RcsaCutover $cutover, RcsaMigrationReconciler $reconciler): int
    {
        $organization = Organization::query()->withoutGlobalScopes()->find((int) $this->option('organization'));

        if ($organization === null) {
            $this->error('Name a tenant with --organization.');

            return self::FAILURE;
        }

        TenantContext::set((int) $organization->id);

        $result = $this->option('reverse')
            ? $this->reverse($cutover, $organization)
            : $this->cutOver($cutover, $reconciler, $organization);

        TenantContext::clear();

        return $result;
    }

    private function cutOver(RcsaCutover $cutover, RcsaMigrationReconciler $reconciler, Organization $organization): int
    {
        if ($cutover->hasCutOver($organization)) {
            $this->info(sprintf(
                '%s cut over on %s — %d day(s) ago. Nothing to do.',
                $organization->name,
                $cutover->cutOverAt($organization)?->toDateString(),
                $cutover->daysSince($organization),
            ));

            return self::SUCCESS;
        }

        $verdict = $reconciler->report((int) $organization->id)['verdict'];

        $this->newLine();
        $this->line('Reconciliation: '.$verdict['note']);

        foreach ($verdict['blockers'] as $blocker) {
            $this->error('  ✗ '.$blocker);
        }

        foreach ($verdict['requires_triage'] as $item) {
            $this->warn('  ! '.$item);
        }

        if (! $verdict['signable'] && ! $this->option('force')) {
            $this->newLine();
            $this->error('Not cutting over: the reconciliation has blockers. Fix them, or pass --force and record why.');

            return self::FAILURE;
        }

        if (! $verdict['signable']) {
            $this->newLine();
            $this->warn('FORCED past a reconciliation with blockers. This belongs in the change record.');
        }

        $this->newLine();
        $this->line('Cutting over '.$organization->name.' will:');
        $this->line('  - close the legacy worksheet write path (it will refuse submissions);');
        $this->line('  - leave every legacy READ screen reachable, per §13\'s retention requirement;');
        $this->line('  - leave the enterprise risk register and every other module untouched.');

        if (! $this->option('commit')) {
            $this->newLine();
            $this->warn('DRY RUN — nothing recorded. Re-run with --commit.');

            return self::SUCCESS;
        }

        $cutover->cutOver($organization);

        $this->newLine();
        $this->info(sprintf('%s cut over at %s.', $organization->name, $cutover->cutOverAt($organization)?->toDateTimeString()));
        $this->line('Turn FEATURE_RCSA_V2 on for this environment if it is not already, and tell the unit heads.');

        return self::SUCCESS;
    }

    private function reverse(RcsaCutover $cutover, Organization $organization): int
    {
        if (! $cutover->hasCutOver($organization)) {
            $this->info($organization->name.' has not cut over. Nothing to reverse.');

            return self::SUCCESS;
        }

        $days = $cutover->daysSince($organization);

        $this->line(sprintf('%s cut over %d day(s) ago.', $organization->name, $days));

        if ($days > \App\Console\Commands\RcsaRollbackLegacyMigration::WINDOW_DAYS) {
            $this->warn(sprintf(
                'That is past the %d-day window in the runbook. Reversing is still possible, but anything '
                .'filed in v2 since cutover stays in v2 — this re-opens the legacy write path, it does not '
                .'move work back.',
                \App\Console\Commands\RcsaRollbackLegacyMigration::WINDOW_DAYS,
            ));
        }

        if (! $this->option('commit')) {
            $this->warn('DRY RUN — nothing changed. Re-run with --commit.');

            return self::SUCCESS;
        }

        $cutover->reverse($organization);

        $this->info('Reversed. The legacy worksheet accepts submissions again.');
        $this->line('To remove the migrated data as well, run rcsa:rollback-legacy-migration.');

        return self::SUCCESS;
    }
}
