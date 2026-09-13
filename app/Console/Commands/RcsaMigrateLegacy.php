<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Rcsa\RcsaLegacyInventory;
use App\Services\Rcsa\RcsaLegacyMigrator;
use App\Services\Rcsa\RcsaMigrationReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * §13's migration, in the order §13 puts it: inventory, migrate, reconcile.
 *
 * ONE COMMAND WITH THREE STEPS RATHER THAN THREE COMMANDS, because the order is
 * the point. An operator who runs the migration without having read the
 * inventory does not know how many risks have no business unit; one who commits
 * without reconciling does not know whether a unit was skipped. Each step can
 * still be run alone with `--step`, for the second and third times through.
 *
 * IT WILL NOT WRITE WITHOUT `--commit`. The default is a dry run, and the dry
 * run is a REAL run inside a transaction that is rolled back — so the numbers it
 * reports are the numbers a commit would produce, rather than a second
 * implementation's guess at them.
 *
 * EVERY REPORT IS WRITTEN TO DISK as well as printed. A cutover is a scheduled
 * event with a runbook and a sign-off, and "what did the inventory say on the
 * day" is a question somebody asks weeks later.
 */
class RcsaMigrateLegacy extends Command
{
    protected $signature = 'rcsa:migrate-legacy
        {--organization= : The tenant to migrate. Required unless --all.}
        {--all : Every organisation, one after another.}
        {--step=all : inventory, migrate, reconcile, or all.}
        {--commit : Actually write. Without it the migration runs and is rolled back.}';

    protected $description = 'Migrate the legacy RCSA module into RCSA v2: inventory, then map and backfill, then reconcile';

    public function handle(
        RcsaLegacyInventory $inventory,
        RcsaLegacyMigrator $migrator,
        RcsaMigrationReconciler $reconciler,
    ): int {
        $step = (string) $this->option('step');
        $commit = (bool) $this->option('commit');

        $organizations = $this->organizations();

        if ($organizations === []) {
            $this->error('Name a tenant with --organization, or pass --all.');

            return self::FAILURE;
        }

        foreach ($organizations as $organizationId) {
            $this->newLine();
            $this->info("Organisation {$organizationId}");
            $this->line(str_repeat('─', 60));

            // The services read through the tenant scope in places, and a
            // console command has no session to set it.
            TenantContext::set($organizationId);

            if (in_array($step, ['all', 'inventory'], true)) {
                $this->inventory($inventory, $organizationId);
            }

            if (in_array($step, ['all', 'migrate'], true)) {
                $this->migrate($migrator, $organizationId, $commit);
            }

            if (in_array($step, ['all', 'reconcile'], true)) {
                $this->reconcile($reconciler, $organizationId);
            }
        }

        TenantContext::clear();

        if (! $commit && in_array($step, ['all', 'migrate'], true)) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --commit when the numbers above look right.');
        }

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */
    /*  Step 1 */
    /* ------------------------------------------------------------------ */

    private function inventory(RcsaLegacyInventory $inventory, int $organizationId): void
    {
        $this->newLine();
        $this->comment('1. Inventory');

        $report = $inventory->report($organizationId);

        $this->line('  '.$report['finding']);
        $this->newLine();

        $this->table(
            ['Table', 'Rows', 'Shared', 'Role'],
            array_map(
                fn (string $table, array $meta) => [
                    $table,
                    $meta['rows'],
                    $meta['shared'] ? 'yes' : 'no',
                    \Illuminate\Support\Str::limit($meta['role'], 60),
                ],
                array_keys($report['sources']),
                $report['sources'],
            ),
        );

        $readiness = $report['readiness'];

        foreach ($readiness as $key => $value) {
            if ($value > 0) {
                $this->line(sprintf('  %-34s %d', str_replace('_', ' ', $key), $value));
            }
        }

        if ($report['campaigns'] !== []) {
            $this->newLine();
            $this->line('  Legacy campaigns, each becoming one closed cycle:');
            $this->table(
                ['Code', 'Title', 'Period', 'Responses'],
                array_map(fn (array $c) => [
                    $c['code'],
                    \Illuminate\Support\Str::limit((string) $c['title'], 34),
                    ($c['period_start'] ?? '?').' → '.($c['period_end'] ?? '?'),
                    $c['responses'],
                ], $report['campaigns']),
            );
        }

        $this->write($organizationId, 'inventory', $report);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 2 */
    /* ------------------------------------------------------------------ */

    private function migrate(RcsaLegacyMigrator $migrator, int $organizationId, bool $commit): void
    {
        $this->newLine();
        $this->comment('2. Map and backfill'.($commit ? '' : ' (dry run)'));

        try {
            $result = $migrator->migrate($organizationId, $commit);
        } catch (\RuntimeException $e) {
            $this->error('  '.$e->getMessage());

            return;
        }

        $this->table(['What', 'Count'], [
            ['Universe risks created', $result['master_data']['risks_created']],
            ['Universe risks skipped (already migrated or duplicate)', $result['master_data']['risks_skipped']],
            ['Universe controls created', $result['master_data']['controls_created']],
            ['Legacy cycles created', $result['history']['cycles_created']],
            ['Historical lines created', $result['history']['lines_created']],
            ['Exceptions', $result['exception_count']],
        ]);

        if ($result['exceptions'] !== []) {
            $this->newLine();
            $this->warn('  '.count($result['exceptions']).' row(s) could not be migrated. They are NOT dropped —');
            $this->warn('  the full list is in the exceptions report, for manual triage.');

            foreach (array_slice($result['exceptions'], 0, 5) as $exception) {
                $this->line(sprintf('    %s %s — %s', $exception['type'], $exception['reference'], $exception['reason']));
            }

            if (count($result['exceptions']) > 5) {
                $this->line(sprintf('    …and %d more.', count($result['exceptions']) - 5));
            }

            $this->write($organizationId, 'exceptions', $result['exceptions']);
        }

        $this->write($organizationId, $commit ? 'migration' : 'migration-dry-run', $result);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 3 */
    /* ------------------------------------------------------------------ */

    private function reconcile(RcsaMigrationReconciler $reconciler, int $organizationId): void
    {
        $this->newLine();
        $this->comment('3. Reconciliation');

        $report = $reconciler->report($organizationId);

        $this->table(['Measure', 'Value'], array_map(
            fn (string $k, $v) => [str_replace('_', ' ', $k), $v],
            array_keys($report['counts']),
            $report['counts'],
        ));

        $mismatched = array_filter($report['per_business_unit'], fn (array $u) => $u['difference'] !== 0);

        if ($mismatched !== []) {
            $this->newLine();
            $this->line('  Units where the counts differ:');
            $this->table(
                ['Business unit', 'Legacy', 'Migrated', 'Difference'],
                array_map(fn (array $u) => [
                    $u['business_unit'].($u['missing_entirely'] ? '  ← MISSING ENTIRELY' : ''),
                    $u['legacy'],
                    $u['migrated'],
                    $u['difference'],
                ], $mismatched),
            );
        }

        $this->newLine();
        $this->line('  Residual distribution — '.$report['residual_distribution']['note']);

        if ($report['residual_distribution']['rows'] !== []) {
            $this->table(
                ['Level', 'Legacy register', 'Migrated lines'],
                array_map(
                    fn (array $r) => [$r['level'], $r['legacy'], $r['migrated']],
                    $report['residual_distribution']['rows'],
                ),
            );
        }

        $this->newLine();

        if ($report['verdict']['signable']) {
            $this->info('  ✓ Signable. '.$report['verdict']['note']);
        } else {
            $this->error('  ✗ Not signable:');

            foreach ($report['verdict']['blockers'] as $blocker) {
                $this->error('    - '.$blocker);
            }
        }

        foreach ($report['verdict']['requires_triage'] as $item) {
            $this->newLine();
            $this->warn('  ! '.$item);
        }

        $this->write($organizationId, 'reconciliation', $report);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<int>
     */
    private function organizations(): array
    {
        if ($this->option('all')) {
            return Organization::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $id = $this->option('organization');

        return $id === null ? [] : [(int) $id];
    }

    /**
     * Every report on disk as well as on screen.
     */
    private function write(int $organizationId, string $name, mixed $payload): void
    {
        $path = sprintf('rcsa/migration/%d/%s-%s.json', $organizationId, $name, now()->format('Ymd-His'));

        Storage::disk('local')->put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line("  Written to storage/app/private/{$path}");
    }
}
