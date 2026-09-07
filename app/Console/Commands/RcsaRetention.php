<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Rcsa\RcsaRetentionService;
use Illuminate\Console\Command;

/**
 * §14 Q10's sweep — and by default it does nothing at all.
 *
 * TWO SAFETY PROPERTIES, BOTH DELIBERATE.
 *
 * 1. It REPORTS unless told `--commit`. A dry run is the default because the
 *    cost of a wrong retention sweep is unrecoverable and the cost of a
 *    needless dry run is a page of output.
 * 2. A tenant with no policy is a no-op even WITH `--commit`. Every period
 *    defaults to null, so the command that a scheduler runs nightly against a
 *    bank that never answered Q10 removes nothing, for ever.
 *
 * It is deliberately NOT registered in `routes/console.php`. The other two RCSA
 * commands are scheduled because reminders and deadline flips are safe to run
 * unattended; this one deletes files. A bank that wants it nightly can schedule
 * it once they have set a policy and watched a dry run — and that ordering is
 * the point, so the note is here rather than in a commit message nobody reads.
 */
class RcsaRetention extends Command
{
    protected $signature = 'rcsa:retention
        {--organization= : One tenant. Omit to sweep every tenant that has a policy.}
        {--commit : Actually delete. Without this the command only reports.}';

    protected $description = 'Report or apply the RCSA retention policy (§14 Q10) — reports unless --commit';

    public function handle(RcsaRetentionService $retention): int
    {
        $organizations = $this->option('organization')
            ? Organization::query()->whereKey((int) $this->option('organization'))->get()
            : Organization::query()->get();

        if ($organizations->isEmpty()) {
            $this->error('No organisation matched.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');

        foreach ($organizations as $organization) {
            $report = $retention->report($organization);
            $policy = $report['policy'];

            if ($policy === array_fill_keys(array_keys($policy), null)) {
                $this->line("{$organization->name}: no retention policy set — nothing is purged.");

                continue;
            }

            $this->info($organization->name);
            $this->line(sprintf(
                '  Policy: export files %s, import files %s, closed cycles %s.',
                $this->period($policy['export_files_days'], 'days'),
                $this->period($policy['import_files_days'], 'days'),
                $this->period($policy['closed_cycle_years'], 'years'),
            ));

            $this->line(sprintf(
                '  Past the period: %d export file(s), %d import file(s).',
                count($report['export_files']),
                count($report['import_files']),
            ));

            if ($report['closed_cycles'] !== []) {
                // REPORTED, NEVER PURGED. Listed by name because somebody has
                // to decide, and a count would not tell them what they were
                // deciding about.
                $this->warn(sprintf(
                    '  %d closed cycle(s) older than the retention period. NOT deleted — this is the '
                        .'bank\'s regulatory record and no command here removes one: %s',
                    count($report['closed_cycles']),
                    implode(', ', array_column($report['closed_cycles'], 'name')),
                ));
            }

            if ($report['held'] !== []) {
                $this->line(sprintf(
                    '  %d cycle(s) under legal hold, excluded from every sweep: %s',
                    count($report['held']),
                    implode(', ', array_column($report['held'], 'name')),
                ));
            }

            if (! $commit) {
                $this->comment('  Dry run. Re-run with --commit to delete the files listed above.');

                continue;
            }

            $done = $retention->purge($organization, null);

            $this->info(sprintf(
                '  Purged %d export file(s), %d import file(s) and %d staged import row(s). '
                    .'Every log row was kept.',
                $done['export_files'],
                $done['import_files'],
                $done['import_rows'],
            ));
        }

        return self::SUCCESS;
    }

    private function period(?int $value, string $unit): string
    {
        return $value === null ? 'keep for ever' : "{$value} {$unit}";
    }
}
