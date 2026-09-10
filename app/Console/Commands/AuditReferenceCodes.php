<?php

namespace App\Console\Commands;

use App\Support\ReferenceCodeAudit;
use Illuminate\Console\Command;

/**
 * Reports reference codes that are duplicated within an organization.
 *
 * Run this before deploying the migration that adds
 * UNIQUE(organization_id, code): the migration skips any table this reports
 * on rather than failing mid-run, so a silent skip is the signal that there is
 * data to fix here first.
 */
class AuditReferenceCodes extends Command
{
    protected $signature = 'reference-codes:audit {--json : Emit the report as JSON for a follow-up script}';

    protected $description = 'Find reference codes duplicated within an organization';

    public function handle(): int
    {
        $report = ReferenceCodeAudit::all();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($report === []) {
            $this->info('No duplicate reference codes found. Every code is unique within its organization.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($report as $table => $collisions) {
            $this->newLine();
            $this->error("{$table} — ".count($collisions).' duplicated code(s)');
            $this->table(
                ['organization_id', 'code', 'rows', 'row ids'],
                array_map(fn (array $c) => [
                    $c['organization_id'],
                    $c['code'],
                    $c['occurrences'],
                    implode(', ', $c['ids']),
                ], $collisions)
            );
            $total += count($collisions);
        }

        $this->newLine();
        $this->error("{$total} duplicated reference code(s) across ".count($report).' table(s).');
        $this->line(
            'Reference codes appear on regulatory filings, so this command does not renumber them. '
            .'Decide per row which record keeps the code, reissue the others, then re-run the migration '
            .'that adds the composite unique index.'
        );

        return self::FAILURE;
    }
}
