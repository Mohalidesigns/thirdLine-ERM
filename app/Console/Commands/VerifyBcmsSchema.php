<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Diff the live BCMS schema against the frozen manifest — Gate G0, acceptance
 * criterion 2.
 *
 * WHAT IT IS FOR. Standing rule 2 forbids structural migrations after Week 1,
 * because four parallel tracks building against a moving schema produce
 * migration-order collisions across four branches. A rule nothing checks is a
 * rule that holds until the first busy Friday. This is the check.
 *
 * IT FAILS IN BOTH DIRECTIONS, deliberately. A missing table or column means a
 * migration did not run — the obvious case. An EXTRA table or column means
 * somebody added one without an ADR, which is the case that actually happens
 * and the one a `migrate:status` will never show. Both exit non-zero.
 *
 * `--write` REGENERATES THE MANIFEST and is the second half of the ADR
 * procedure: the architect approves a column, the phase adds the migration and
 * regenerates the manifest in the same commit, and the diff is visible in
 * review rather than discoverable in production.
 */
class VerifyBcmsSchema extends Command
{
    protected $signature = 'bcms:verify-schema
                            {--write : Rewrite the manifest from the live schema (an approved ADR only)}';

    protected $description = 'Diff the live BCMS schema against database/schema/bcms-manifest.php.';

    public function handle(): int
    {
        $manifestPath = database_path('schema/bcms-manifest.php');

        if (! file_exists($manifestPath)) {
            $this->error('No schema manifest at '.$manifestPath);

            return self::FAILURE;
        }

        $live = $this->liveSchema();

        if ($this->option('write')) {
            $this->writeManifest($manifestPath, $live);
            $this->info(sprintf('Manifest rewritten from the live schema: %d tables.', count($live)));

            return self::SUCCESS;
        }

        /** @var array<string, list<string>> $expected */
        $expected = require $manifestPath;

        $problems = [];

        foreach ($expected as $table => $columns) {
            if (! isset($live[$table])) {
                $problems[] = "MISSING TABLE   {$table}";

                continue;
            }

            $missing = array_values(array_diff($columns, $live[$table]));
            $extra = array_values(array_diff($live[$table], $columns));

            foreach ($missing as $column) {
                $problems[] = "MISSING COLUMN  {$table}.{$column}";
            }

            foreach ($extra as $column) {
                $problems[] = "EXTRA COLUMN    {$table}.{$column}  (added without an ADR?)";
            }
        }

        foreach (array_diff(array_keys($live), array_keys($expected)) as $table) {
            $problems[] = "EXTRA TABLE     {$table}  (added without an ADR?)";
        }

        if ($problems === []) {
            $this->info(sprintf('BCMS schema matches the frozen manifest: %d tables.', count($expected)));

            return self::SUCCESS;
        }

        $this->error('The live BCMS schema does not match the frozen manifest:');

        foreach ($problems as $problem) {
            $this->line('  '.$problem);
        }

        $this->newLine();
        $this->line('Standing rule 2: no structural migrations after Phase 0 without an architect ADR.');
        $this->line('If the change IS approved, add the ADR and re-run with --write in the same commit.');

        return self::FAILURE;
    }

    /**
     * The live schema, read through Laravel's schema builder rather than
     * `information_schema`, so the command works on MySQL in production and
     * SQLite in the test suite.
     *
     * @return array<string, list<string>>
     */
    private function liveSchema(): array
    {
        $out = [];

        foreach (Schema::getTableListing() as $table) {
            // Some drivers qualify the name; take the last segment.
            $name = str_contains($table, '.') ? substr(strrchr($table, '.'), 1) : $table;

            if (! str_starts_with($name, 'bcms_')) {
                continue;
            }

            $columns = Schema::getColumnListing($name);
            sort($columns);
            $out[$name] = $columns;
        }

        ksort($out);

        return $out;
    }

    /** @param array<string, list<string>> $live */
    private function writeManifest(string $path, array $live): void
    {
        $existing = file_get_contents($path);
        $header = substr($existing, 0, (int) strpos($existing, 'return ['));

        $php = $header."return [\n";

        foreach ($live as $table => $columns) {
            $php .= "    '{$table}' => [\n";
            $line = '        ';

            foreach ($columns as $column) {
                $add = "'{$column}', ";

                if (strlen($line) + strlen($add) > 100) {
                    $php .= rtrim($line)."\n";
                    $line = '        ';
                }

                $line .= $add;
            }

            $php .= rtrim(rtrim($line), ',')."\n    ],\n";
        }

        file_put_contents($path, $php."];\n");
    }
}
